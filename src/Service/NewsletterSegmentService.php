<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\MergeExpression\Parser;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;

/**
 * Turns a boolean merge expression into audience membership: it materialises the
 * set of active subscribers whose expression is truthy into a segment audience,
 * so the normal "issue → audiences → recipients" send path works unchanged.
 *
 * Evaluation reuses {@see MergeFieldService} row-by-row (always correct). For the
 * common "Relation.Count >= N" shape it instead runs a single grouped query
 * (the fast path), avoiding one query per subscriber on large lists; results are
 * identical to the row-by-row path for the operators it accepts.
 */
class NewsletterSegmentService
{
    use Injectable;

    /** Operators safe for the batched fast path: zero/no-anchor never matches. */
    private const FAST_OPS = ['>', '>=', '='];

    private const FAST_AGGREGATES = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * Recompute a segment audience's membership in place.
     *
     * @return array{matched:int, added:int, removed:int}
     */
    public function build(NewsletterAudience $segment): array
    {
        $expression = trim((string) $segment->SegmentExpression);
        if ($expression === '') {
            return ['matched' => 0, 'added' => 0, 'removed' => 0];
        }

        $matched = $this->matchingIDs($expression, $this->pool($segment->BaseAudienceID));
        $matchedMap = array_fill_keys($matched, true);

        $current = array_map('intval', $segment->Subscribers()->column('ID'));
        $currentMap = array_fill_keys($current, true);

        $toAdd = array_diff_key($matchedMap, $currentMap);
        $toRemove = array_diff_key($currentMap, $matchedMap);

        foreach (array_keys($toAdd) as $id) {
            $segment->Subscribers()->add($id);
        }
        foreach (array_keys($toRemove) as $id) {
            $segment->Subscribers()->removeByID($id);
        }

        return ['matched' => count($matched), 'added' => count($toAdd), 'removed' => count($toRemove)];
    }

    /**
     * Count how many active subscribers a (possibly unsaved) expression matches,
     * for the builder's "Estimate matches" button.
     *
     * @return array{matched:int, total:int}
     */
    public function estimate(string $expression, ?int $baseAudienceID = null): array
    {
        $pool = $this->pool($baseAudienceID);
        $expression = trim($expression);

        return [
            'matched' => $expression === '' ? 0 : count($this->matchingIDs($expression, $pool)),
            'total' => $pool->count(),
        ];
    }

    /**
     * Active subscribers to evaluate, optionally restricted to a base audience.
     */
    public function pool(?int $baseAudienceID = null): DataList
    {
        $list = NewsletterSubscriber::get()->filter('Status', 'Active');

        if ($baseAudienceID) {
            $list = $list->filter('Audiences.ID', $baseAudienceID);
        }

        return $list;
    }

    /**
     * IDs of subscribers in $pool matching $expression.
     *
     * @return array<int, int>
     */
    public function matchingIDs(string $expression, DataList $pool): array
    {
        $anchorClass = (string) Config::inst()->get(NewsletterSubscriber::class, 'anchor_class');
        $spec = $this->fastPathSpec($expression, $anchorClass);

        if ($spec === null) {
            return $this->rowByRowIDs($pool, $expression);
        }

        // Fast path covers subscribers anchored to the configured class; any with
        // a different (or no) anchor fall back to row-by-row so results are exact.
        $fast = $this->batchedIDs($pool, $anchorClass, $spec);
        $rest = $this->rowByRowIDs($pool->exclude('AnchorClass', $anchorClass), $expression);

        return array_values(array_unique(array_merge($fast, $rest)));
    }

    /**
     * @return array<int, int>
     */
    private function rowByRowIDs(DataList $pool, string $expression): array
    {
        $service = MergeFieldService::create();
        $ids = [];

        foreach ($pool as $subscriber) {
            if ($service->matches($expression, $subscriber)) {
                $ids[] = (int) $subscriber->ID;
            }
        }

        return $ids;
    }

    /**
     * Resolve the fast path with one grouped HAVING query, returning matching
     * subscriber IDs (anchored to $anchorClass) within $pool.
     *
     * @param array{relation:string, fn:string, field:?string, op:string, value:float} $spec
     * @return array<int, int>
     */
    private function batchedIDs(DataList $pool, string $anchorClass, array $spec): array
    {
        $schema = DataObject::getSchema();
        $relatedClass = $schema->hasManyComponent($anchorClass, $spec['relation']);
        if (!$relatedClass) {
            return $this->rowByRowIDs($pool->filter('AnchorClass', $anchorClass), $this->describe($spec));
        }

        $polymorphic = false;
        $foreignKey = $schema->getRemoteJoinField($anchorClass, $spec['relation'], 'has_many', $polymorphic);
        if ($polymorphic) {
            return $this->rowByRowIDs($pool->filter('AnchorClass', $anchorClass), $this->describe($spec));
        }

        $aggregate = $spec['fn'] === 'count' ? 'COUNT(*)' : strtoupper($spec['fn']) . '("' . $spec['field'] . '")';
        $threshold = $spec['fn'] === 'count' ? (string) (int) $spec['value'] : (string) (float) $spec['value'];
        $having = $aggregate . ' ' . $spec['op'] . ' ' . $threshold;

        $anchorIDs = $relatedClass::get()
            ->alterDataQuery(function (DataQuery $query) use ($foreignKey, $having): void {
                // Drop the related class's default_sort: it adds ORDER BY columns
                // to the SELECT list, which breaks GROUP BY under MySQL's
                // only_full_group_by. We only need the grouped foreign key.
                $query->sort(null, null, true);
                $query->groupby($foreignKey);
                $query->having($having);
            })
            ->column($foreignKey);

        $anchorIDs = array_values(array_filter(array_map('intval', $anchorIDs)));
        if ($anchorIDs === []) {
            return [];
        }

        return array_map('intval', $pool
            ->filter(['AnchorClass' => $anchorClass, 'AnchorID' => $anchorIDs])
            ->column('ID'));
    }

    /**
     * Detect the "Relation.Aggregate(<field>?) <op> <number>" shape the fast path
     * supports; null means "evaluate row-by-row".
     *
     * @return array{relation:string, fn:string, field:?string, op:string, value:float}|null
     */
    private function fastPathSpec(string $expression, string $anchorClass): ?array
    {
        if (!is_subclass_of($anchorClass, DataObject::class)) {
            return null;
        }

        try {
            $ast = (new Parser())->parse($expression);
        } catch (\Throwable $e) {
            return null;
        }

        if (($ast['t'] ?? null) !== 'cmp' || !in_array($ast['op'], self::FAST_OPS, true)) {
            return null;
        }
        if (($ast['r']['t'] ?? null) !== 'num') {
            return null;
        }

        $left = $ast['l'];
        if (($left['t'] ?? null) !== 'path' || count($left['steps']) !== 2) {
            return null;
        }
        [$relationStep, $aggStep] = $left['steps'];
        if ($relationStep['op'] !== 'name' || $aggStep['op'] !== 'agg') {
            return null;
        }
        if (!in_array($aggStep['fn'], self::FAST_AGGREGATES, true)) {
            return null;
        }

        return [
            'relation' => $relationStep['name'],
            'fn' => $aggStep['fn'],
            'field' => $aggStep['arg'] ?? null,
            'op' => $ast['op'],
            'value' => (float) $ast['r']['v'],
        ];
    }

    /**
     * Re-serialise a fast-path spec back to its expression for the row-by-row
     * fallback used when a relation turns out not to be a plain has_many.
     *
     * @param array{relation:string, fn:string, field:?string, op:string, value:float} $spec
     */
    private function describe(array $spec): string
    {
        $agg = ucfirst($spec['fn']) . ($spec['fn'] === 'count' ? '' : '(' . $spec['field'] . ')');

        return $spec['relation'] . '.' . $agg . ' ' . $spec['op'] . ' ' . $spec['value'];
    }
}
