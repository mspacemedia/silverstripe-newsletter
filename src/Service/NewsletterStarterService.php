<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;
use MSpaceMedia\Newsletter\Elements\NewsletterBlockElemental;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Versioned\Versioned;

/**
 * Starts a new issue from a ready-made layout instead of a blank canvas.
 *
 * Two sources feed the "Start from" picker on a new newsletter:
 * - starters: block layouts defined in YAML (NewsletterStarterService.starters),
 *   shipped with the module and extendable by host projects;
 * - saved templates: any issue flagged IsTemplate, whose blocks are copied.
 *
 * Starter blocks inherit the brand like hand-built ones, so a starter looks
 * on-brand in every install without hard-coded colours.
 */
class NewsletterStarterService
{
    use Injectable;
    use Configurable;

    private const ELEMENT_NAMESPACE = 'MSpaceMedia\\Newsletter\\Elements\\';

    private const PLACEHOLDER_RESOURCE = 'mspacemedia/silverstripe-newsletter:client/dist/starters/';

    private const PLACEHOLDER_FOLDER = 'newsletter/starters';

    /**
     * Keyed starter definitions:
     *   title, description, sort (optional), enabled (optional, default true),
     *   blocks: list of { type: <short or FQCN block class>, image: <placeholder>, <Field>: <value>, … }
     *
     * @config
     */
    private static array $starters = [];

    /**
     * Enabled starters, sorted, keyed by starter key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function starters(): array
    {
        $starters = array_filter(
            (array) $this->config()->get('starters'),
            static fn ($starter): bool => is_array($starter)
                && ($starter['enabled'] ?? true)
                && !empty($starter['blocks'])
        );

        uasort($starters, static fn (array $a, array $b): int => ((int) ($a['sort'] ?? 100)) <=> ((int) ($b['sort'] ?? 100)));

        return $starters;
    }

    /**
     * Picker options: "starter:<key>" for YAML layouts and "issue:<ID>" for saved
     * templates, labelled for the CMS dropdown.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->starters() as $key => $starter) {
            $label = (string) ($starter['title'] ?? $key);
            if (!empty($starter['description'])) {
                $label .= ' - ' . $starter['description'];
            }
            $options['starter:' . $key] = $label;
        }

        foreach (NewsletterIssue::get()->filter('IsTemplate', true)->sort('Title') as $template) {
            $options['issue:' . $template->ID] = _t(
                __CLASS__ . '.SAVED_TEMPLATE',
                'Saved template: {title}',
                ['title' => $template->Title ?: $template->Subject]
            );
        }

        return $options;
    }

    /**
     * Fill the issue's (empty) block area from a picker choice. Returns the
     * number of blocks created; 0 for an unknown or empty choice.
     */
    public function apply(NewsletterIssue $issue, string $choice): int
    {
        [$source, $key] = array_pad(explode(':', $choice, 2), 2, '');

        // Never pile a layout on top of content the editor already has.
        $area = $this->ensureArea($issue);
        if ($area->Elements()->exists()) {
            return 0;
        }

        if ($source === 'starter') {
            $starter = $this->starters()[$key] ?? null;

            return $starter ? $this->buildStarter($area, $starter) : 0;
        }

        if ($source === 'issue' && ctype_digit($key)) {
            $template = NewsletterIssue::get()->byID((int) $key);

            return $template && $template->IsTemplate && (int) $template->ID !== (int) $issue->ID
                ? $this->copyBlocks($template, $area)
                : 0;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $starter
     */
    protected function buildStarter(ElementalArea $area, array $starter): int
    {
        $allowed = (array) NewsletterIssue::config()->get('allowed_elements');
        $sort = 0;

        foreach ((array) $starter['blocks'] as $spec) {
            if (!is_array($spec) || empty($spec['type'])) {
                continue;
            }

            $class = $this->resolveBlockClass((string) $spec['type']);
            if (!$class || ($allowed && !in_array($class, $allowed, true))) {
                continue;
            }

            /** @var NewsletterBlockElemental $block */
            $block = $class::create();
            $block->ParentID = $area->ID;
            $block->Sort = ++$sort;

            $image = $spec['image'] ?? null;
            unset($spec['type'], $spec['image']);

            foreach ($spec as $field => $value) {
                if ($block->hasField($field) || $block->hasMethod('set' . $field)) {
                    $block->$field = $value;
                }
            }

            if ($image && isset($block->hasOne()['Image'])) {
                $placeholder = $this->placeholderImage((string) $image);
                if ($placeholder) {
                    $block->ImageID = $placeholder->ID;
                }
            }

            $block->write();
        }

        return $sort;
    }

    /**
     * Copy every block of a saved template issue (including many_many links
     * such as an image group's images) into the target area.
     */
    protected function copyBlocks(NewsletterIssue $template, ElementalArea $area): int
    {
        $count = 0;

        foreach ($template->ElementalArea()->Elements() as $element) {
            /** @var BaseElement $copy */
            $copy = $element->duplicate(false);
            $copy->ParentID = $area->ID;
            $copy->write();

            foreach (array_keys((array) $element->manyMany()) as $relation) {
                $source = $element->getManyManyComponents($relation);
                $extraFields = $source instanceof ManyManyList ? array_keys($source->getExtraFields()) : [];
                $target = $copy->getManyManyComponents($relation);

                foreach ($source as $item) {
                    $extra = [];
                    foreach ($extraFields as $field) {
                        $extra[$field] = $item->getField($field);
                    }
                    $target->add($item, $extra);
                }
            }

            $count++;
        }

        return $count;
    }

    protected function ensureArea(NewsletterIssue $issue): ElementalArea
    {
        $area = $issue->ElementalArea();
        if ($area && $area->exists()) {
            return $area;
        }

        $area = ElementalArea::create();
        $area->OwnerClassName = $issue->ClassName;
        $area->write();
        $issue->ElementalAreaID = $area->ID;
        $issue->write();

        return $area;
    }

    protected function resolveBlockClass(string $type): ?string
    {
        foreach ([$type, self::ELEMENT_NAMESPACE . $type] as $class) {
            if (class_exists($class) && is_subclass_of($class, NewsletterBlockElemental::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * A shared, published Image for a placeholder shipped in
     * client/dist/starters/, created on first use so every starter that needs
     * it points at the same asset.
     */
    protected function placeholderImage(string $name): ?Image
    {
        $name = basename($name);
        $filename = self::PLACEHOLDER_FOLDER . '/' . $name;

        $existing = Versioned::withVersionedMode(static function () use ($filename): ?Image {
            Versioned::set_stage(Versioned::DRAFT);

            return Image::get()->filter('FileFilename', $filename)->first();
        });
        if ($existing) {
            return $existing;
        }

        $path = ModuleResourceLoader::singleton()->resolvePath(self::PLACEHOLDER_RESOURCE . $name);
        $absolute = $path ? BASE_PATH . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : null;
        if (!$absolute || !is_file($absolute)) {
            return null;
        }

        $image = Image::create();
        $image->setFromLocalFile($absolute, $filename);
        $image->Title = _t(__CLASS__ . '.PLACEHOLDER_TITLE', 'Starter placeholder - replace me');
        $image->write();
        $image->publishSingle();

        return $image;
    }
}
