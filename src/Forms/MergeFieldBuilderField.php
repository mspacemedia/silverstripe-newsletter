<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Forms;

use MSpaceMedia\Newsletter\Admin\NewsletterAdmin;
use MSpaceMedia\Newsletter\Model\NewsletterMergeField;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\TextareaField;

/**
 * Expression editor for a {@see NewsletterMergeField}. Server-side it is a plain
 * textarea holding the canonical expression string; client-side
 * newsletter-mergefield.js enhances it with relation/field pickers and a live
 * preview that evaluates against a sample anchor record. Degrades to a usable
 * textarea if JavaScript is unavailable.
 */
class MergeFieldBuilderField extends TextareaField
{
    protected $rows = 3;

    private bool $segmentMode = false;

    public function __construct($name, $title = null, $value = '')
    {
        parent::__construct($name, $title, $value);

        $this->addExtraClass('js-mergefield-input');
        $this->refreshMergeData();
    }

    /**
     * Mark this as a segment expression: the preview reads as true/false and an
     * "Estimate matches" button is offered (counts matching subscribers).
     */
    public function asSegment(): static
    {
        $this->segmentMode = true;
        $this->refreshMergeData();

        return $this;
    }

    private function refreshMergeData(): void
    {
        $data = [
            'schemaUrl' => $this->endpoint('mergeSchema'),
            'previewUrl' => $this->endpoint('mergePreview'),
            'segment' => $this->segmentMode,
        ];
        if ($this->segmentMode) {
            $data['estimateUrl'] = $this->endpoint('segmentEstimate');
        }

        $this->setAttribute('data-mergefield', (string) json_encode($data));
    }

    /**
     * Absolute admin URL for one of NewsletterAdmin's merge-field endpoints.
     */
    private function endpoint(string $action): string
    {
        $segment = (string) Config::inst()->get(NewsletterAdmin::class, 'url_segment');
        $model = str_replace('\\', '-', NewsletterMergeField::class);

        return Controller::join_links(AdminRootController::admin_url(), $segment, $model, $action);
    }
}
