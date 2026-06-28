<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Model\NewsletterMergeField;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\MergeExpression\Evaluator;
use MSpaceMedia\Newsletter\Service\MergeExpression\ExpressionException;
use MSpaceMedia\Newsletter\Service\MergeExpression\Parser;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Resolves the {{ … }} merge-field syntax over rendered newsletter HTML, per
 * recipient. Two constructs:
 *
 *   {{ expression }}                       output, e.g. {{ Order.Count }}
 *   {{#if expression}}…{{else}}…{{/if}}    conditional block (nestable)
 *
 * Expressions may reference a defined {@see NewsletterMergeField} by tag, a
 * built-in (FirstName/Email/…), the subscriber's custom merge data, or traverse
 * the subscriber's anchor record. Resolution happens at the same late stage as
 * the legacy *|FNAME|* tags (in NewsletterRenderService::finaliseSnapshot), so
 * the locked SentHTML snapshot stays generic and personalises per delivery.
 *
 * This is the new engine; the legacy *|…|* resolver is left intact alongside it.
 */
class MergeFieldService
{
    use Injectable;
    use Configurable;

    /**
     * Relations/fields editors may traverse, per class. Default-deny: a class
     * absent here exposes nothing. Parent-class entries cover subclasses.
     *
     * @var array<string, array{relations?: array<int,string>, fields?: array<int,string>}>
     */
    private static array $allowlist = [];

    private static string $currency_symbol = '£';

    /** @var array<string, array<string, mixed>> parsed-AST cache keyed by expression */
    private static array $astCache = [];

    /** @var array<string, NewsletterMergeField>|null tag => definition */
    private ?array $fieldMap = null;

    /**
     * Resolve every {{ … }} construct in $html for the given subscriber. A null
     * subscriber (generic "view online") leaves computed fields to their
     * fallbacks and treats {{#if}} conditions as false.
     */
    public function render(string $html, ?NewsletterSubscriber $subscriber): string
    {
        if (!str_contains($html, '{{')) {
            return $html;
        }

        // Rich-text editors wrap/encode the inside of {{ … }} markers (stray
        // <span>s, &nbsp;, &gt;). Clean every marker up front so the conditional
        // and output passes see plain {{#if …}}, {{else}}, {{/if}} and {{ expr }}.
        $html = $this->cleanMarkers($html);

        $anchor = $subscriber?->getAnchorRecord();
        $builtins = $this->builtinsFor($subscriber);

        $html = $this->renderConditionals($html, $anchor, $builtins);

        return $this->renderOutputs($html, $anchor, $builtins);
    }

    /**
     * Normalise the contents of every {{ … }} marker (including {{#if}}/{{else}}/
     * {{/if}}) so editor-inserted markup/entities don't stop them matching.
     */
    private function cleanMarkers(string $html): string
    {
        return preg_replace_callback(
            '/\{\{(.*?)\}\}/s',
            fn (array $m): string => '{{' . $this->normaliseExpression($m[1]) . '}}',
            $html
        ) ?? $html;
    }

    /**
     * Evaluate an expression for a subscriber (anchor + built-ins resolved from
     * the subscriber), surfacing errors. Shared by computed fields and segments.
     */
    public function evaluateForSubscriber(string $expression, NewsletterSubscriber $subscriber): mixed
    {
        return $this->evaluate($expression, $subscriber->getAnchorRecord(), $this->builtinsFor($subscriber));
    }

    /**
     * Whether a subscriber matches a boolean segment expression (truthy result).
     * Used by NewsletterSegmentService to materialise segment membership.
     */
    public function matches(string $expression, NewsletterSubscriber $subscriber): bool
    {
        return (new Evaluator())->truthy($this->evaluateForSubscriber($expression, $subscriber));
    }

    /**
     * Evaluate a single expression directly (used by the live-preview endpoint),
     * surfacing parse/traversal errors rather than swallowing them.
     *
     * @param array<string, mixed> $builtins
     */
    public function evaluate(string $expression, ?DataObject $anchor, array $builtins = [], array $stack = []): mixed
    {
        $ast = $this->parse($expression);

        $evaluator = new Evaluator(
            (array) static::config()->get('allowlist'),
            $builtins,
            fn (string $tag) => $this->resolveDefinedField($tag, $anchor, $builtins, $stack),
            (string) static::config()->get('currency_symbol'),
        );

        return $evaluator->evaluate($ast, $anchor);
    }

    // ---- Output tags ----------------------------------------------------

    /**
     * @param array<string, mixed> $builtins
     */
    private function renderOutputs(string $html, ?DataObject $anchor, array $builtins): string
    {
        // {{ … }} but not {{#if}}, {{else}}, {{/if}}.
        return preg_replace_callback(
            '/\{\{\s*(?![#\/])(?!else\s*\}\})(.+?)\s*\}\}/s',
            function (array $m) use ($anchor, $builtins): string {
                try {
                    $value = $this->evaluate($this->normaliseExpression($m[1]), $anchor, $builtins);
                } catch (ExpressionException $e) {
                    // Never leak a broken tag into a delivered email.
                    return '';
                }

                return $this->stringify($value);
            },
            $html
        ) ?? $html;
    }

    // ---- Conditional blocks --------------------------------------------

    /**
     * @param array<string, mixed> $builtins
     */
    private function renderConditionals(string $html, ?DataObject $anchor, array $builtins): string
    {
        $open = preg_match('/\{\{\s*#if\s+(.+?)\s*\}\}/s', $html, $match, PREG_OFFSET_CAPTURE);
        if (!$open) {
            return $html;
        }

        $start = $match[0][1];
        $condition = $this->normaliseExpression($match[1][0]);
        $bodyStart = $start + strlen($match[0][0]);

        $split = $this->findBranches($html, $bodyStart);
        if ($split === null) {
            // Unbalanced {{#if}} — leave the text untouched rather than corrupt it.
            return $html;
        }

        $before = substr($html, 0, $start);
        $after = substr($html, $split['end']);

        $branch = $this->conditionIsTrue($condition, $anchor, $builtins)
            ? $split['true']
            : $split['else'];

        // Recurse so nested and sibling {{#if}} blocks are handled too.
        return $before
            . $this->renderConditionals($branch, $anchor, $builtins)
            . $this->renderConditionals($after, $anchor, $builtins);
    }

    /**
     * From just after an opening {{#if}}, locate the matching {{else}}/{{/if}}
     * accounting for nested blocks.
     *
     * @return array{true:string, else:string, end:int}|null
     */
    private function findBranches(string $html, int $bodyStart): ?array
    {
        $pattern = '/\{\{\s*(#if\s+.+?|else|\/if)\s*\}\}/s';
        $offset = $bodyStart;
        $depth = 0;
        $elsePos = null;
        $elseLen = 0;

        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $tokenStart = $m[0][1];
            $tokenText = $m[0][0];
            $keyword = strtolower(trim($m[1][0]));
            $offset = $tokenStart + strlen($tokenText);

            if (str_starts_with($keyword, '#if')) {
                $depth++;
                continue;
            }

            if ($keyword === 'else' && $depth === 0 && $elsePos === null) {
                $elsePos = $tokenStart;
                $elseLen = strlen($tokenText);
                continue;
            }

            if ($keyword === '/if') {
                if ($depth === 0) {
                    if ($elsePos !== null) {
                        $true = substr($html, $bodyStart, $elsePos - $bodyStart);
                        $else = substr($html, $elsePos + $elseLen, $tokenStart - ($elsePos + $elseLen));
                    } else {
                        $true = substr($html, $bodyStart, $tokenStart - $bodyStart);
                        $else = '';
                    }

                    return ['true' => $true, 'else' => $else, 'end' => $offset];
                }
                $depth--;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $builtins
     */
    private function conditionIsTrue(string $expression, ?DataObject $anchor, array $builtins): bool
    {
        try {
            $value = $this->evaluate($expression, $anchor, $builtins);
        } catch (ExpressionException $e) {
            return false;
        }

        return (new Evaluator())->truthy($value);
    }

    // ---- Defined fields / built-ins ------------------------------------

    /**
     * @param array<string, mixed> $builtins
     * @param array<int, string> $stack tags currently being resolved (cycle guard)
     */
    private function resolveDefinedField(string $tag, ?DataObject $anchor, array $builtins, array $stack): mixed
    {
        $key = NewsletterMergeField::normaliseTag($tag);
        if ($key === '' || in_array($key, $stack, true)) {
            return null;
        }

        $definition = $this->fieldMap()[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        return $this->evaluate((string) $definition->Expression, $anchor, $builtins, [...$stack, $key]);
    }

    /**
     * @return array<string, NewsletterMergeField>
     */
    private function fieldMap(): array
    {
        if ($this->fieldMap !== null) {
            return $this->fieldMap;
        }

        $this->fieldMap = [];
        foreach (NewsletterMergeField::get() as $field) {
            $this->fieldMap[NewsletterMergeField::normaliseTag((string) $field->Tag)] = $field;
        }

        return $this->fieldMap;
    }

    /**
     * @return array<string, mixed>
     */
    private function builtinsFor(?NewsletterSubscriber $subscriber): array
    {
        if ($subscriber === null) {
            return [];
        }

        // Custom merge data first; the core fields below take precedence on a
        // name collision so {{ FirstName }} always means the real field.
        $builtins = [];
        foreach ($subscriber->getMergeArray() as $tag => $value) {
            $builtins[strtolower((string) $tag)] = $value;
        }

        // Thin subscribers (email only) borrow name/email from the anchor record
        // when their own field is blank, so personalisation works off the Member.
        $anchor = $subscriber->getAnchorRecord();
        $field = static function (string $name) use ($subscriber, $anchor): string {
            $value = (string) $subscriber->getField($name);
            if ($value === '' && $anchor && $anchor->hasField($name)) {
                $value = (string) $anchor->getField($name);
            }

            return $value;
        };

        $first = $field('FirstName');
        $surname = $field('Surname');
        $email = $field('Email');
        $name = trim($first . ' ' . $surname) ?: $subscriber->getDisplayName();

        $builtins['firstname'] = $first;
        $builtins['fname'] = $first;
        $builtins['surname'] = $surname;
        $builtins['lastname'] = $surname;
        $builtins['lname'] = $surname;
        $builtins['email'] = $email;
        $builtins['name'] = $name;

        return $builtins;
    }

    /**
     * Clean an expression captured from rendered HTML before tokenising. Rich-text
     * editors mangle tags in ways the tokeniser would reject: they wrap content in
     * inline markup ({{ <span>FirstName</span> }}) and emit entities/non-breaking
     * spaces (&nbsp;, &lt;) in place of literal characters. So:
     *   1. strip inline tags (real `<…>` markup; a user's `<` comparison is stored
     *      as the entity `&lt;`, decoded in step 2, so it is preserved);
     *   2. decode HTML entities (&lt; → <, &amp; → &, &nbsp; → U+00A0);
     *   3. fold non-breaking spaces to ordinary spaces, then trim.
     */
    private function normaliseExpression(string $raw): string
    {
        $raw = strip_tags($raw);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = str_replace("\xc2\xa0", ' ', $raw);

        return trim($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $expression): array
    {
        return self::$astCache[$expression]
            ??= (new Parser())->parse($expression);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_float($value)) {
            // Drop trailing .0 so whole numbers read cleanly.
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return (string) ($value ?? '');
    }
}
