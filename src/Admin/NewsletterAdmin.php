<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Admin;

use DNADesign\Elemental\Forms\EditFormFactory;
use DNADesign\Elemental\Models\BaseElement;
use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Convert;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;

/**
 * CMS section for composing newsletters, managing audiences (with CSV
 * import/export on each audience's subscriber list) and reviewing send records.
 */
class NewsletterAdmin extends ModelAdmin
{
    private static string $url_segment = 'newsletters';

    private static string $menu_title = 'Newsletters';

    private static string $menu_icon_class = 'font-icon-p-mail';

    private static int $menu_priority = 4;

    private static array $managed_models = [
        NewsletterIssue::class,
        NewsletterAudience::class,
        NewsletterSubscriber::class,
        NewsletterBrand::class,
        NewsletterSendRecord::class,
    ];

    private static array $required_permission_codes = ['MANAGE_NEWSLETTERS'];

    private static array $allowed_actions = [
        'ImportForm',
        'SearchForm',
        'cmsPreview',
        'cmsPreviewUnsaved',
    ];

    private static array $url_handlers = [
        '$ModelClass/cmsPreviewUnsaved/$ID' => 'cmsPreviewUnsaved',
        '$ModelClass/cmsPreview/$ID' => 'cmsPreview',
        '$ModelClass/$Action' => 'handleAction',
    ];

    protected function init()
    {
        parent::init();

        Requirements::javascript('mspacemedia/silverstripe-newsletter:client/dist/newsletter-preview.js');
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        // The newsletter issue is versioned — use the versioned detail form so it
        // gets Save draft / Publish / Unpublish / Archive + the History panel.
        if ($this->modelClass === NewsletterIssue::class) {
            $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName(NewsletterIssue::class));
            if ($grid instanceof GridField) {
                $detail = $grid->getConfig()->getComponentByType(GridFieldDetailForm::class);
                $detail?->setItemRequestClass(VersionedGridFieldItemRequest::class);
            }
        }

        return $form;
    }

    public function cmsPreview(HTTPRequest $request): HTTPResponse
    {
        if ($this->modelClass !== NewsletterIssue::class || !Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(404);
        }

        $id = (int) $request->param('ID');
        if (!$id) {
            return $this->httpError(404, _t(__CLASS__ . '.NEWSLETTER_NOT_FOUND', 'Newsletter not found.'));
        }

        $stage = $request->getVar('stage') === Versioned::LIVE
            ? Versioned::LIVE
            : Versioned::DRAFT;

        return Versioned::withVersionedMode(function () use ($id, $stage): HTTPResponse {
            Versioned::set_stage($stage);

            $issue = Versioned::get_by_stage(NewsletterIssue::class, $stage)->byID($id);
            if (!$issue || !$issue->exists()) {
                return $this->httpError(404, _t(__CLASS__ . '.NEWSLETTER_NOT_FOUND', 'Newsletter not found.'));
            }

            return $this->renderPreviewResponse($issue);
        });
    }

    public function cmsPreviewUnsaved(HTTPRequest $request): HTTPResponse
    {
        if ($this->modelClass !== NewsletterIssue::class || !Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(404);
        }

        $id = (int) $request->param('ID');
        if (!$id) {
            return $this->httpError(404, _t(__CLASS__ . '.NEWSLETTER_NOT_FOUND', 'Newsletter not found.'));
        }

        $stage = $request->getVar('stage') === Versioned::LIVE
            ? Versioned::LIVE
            : Versioned::DRAFT;

        return Versioned::withVersionedMode(function () use ($id, $stage, $request): HTTPResponse {
            Versioned::set_stage($stage);

            $issue = Versioned::get_by_stage(NewsletterIssue::class, $stage)->byID($id);
            if (!$issue || !$issue->exists()) {
                return $this->httpError(404, _t(__CLASS__ . '.NEWSLETTER_NOT_FOUND', 'Newsletter not found.'));
            }

            $payload = json_decode((string) $request->getBody(), true);
            if ($issue->canEdit() && is_array($payload) && isset($payload['blocks']) && is_array($payload['blocks'])) {
                $this->applyUnsavedBlockData($issue, $payload['blocks']);
            }

            return $this->renderPreviewResponse($issue, true);
        });
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    private function applyUnsavedBlockData(NewsletterIssue $issue, array $blocks): void
    {
        $area = $issue->ElementalArea();
        if (!$area || !$area->exists()) {
            return;
        }

        $elements = ArrayList::create();
        foreach ($area->Elements() as $element) {
            $blockData = $blocks[$element->ID] ?? $blocks[(string) $element->ID] ?? null;
            if (is_array($blockData)) {
                $this->applyUnsavedDataToElement(
                    $element,
                    $this->normaliseUnsavedBlockData((int) $element->ID, $blockData)
                );
            }

            $elements->push($element);
        }

        $area->setElementsCached($elements);
        $issue->setComponent('ElementalArea', $area);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyUnsavedDataToElement(BaseElement $element, array $data): void
    {
        foreach ($element->getCMSFields()->saveableFields() as $fieldName => $field) {
            $field->setSubmittedValue($data[$fieldName] ?? null);
            $field->saveInto($element);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseUnsavedBlockData(int $blockID, array $data): array
    {
        $prefix = sprintf(EditFormFactory::FIELD_NAMESPACE_TEMPLATE, $blockID, '');
        $normalised = [];

        foreach ($data as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, $prefix)) {
                continue;
            }

            $fieldName = substr($name, strlen($prefix));
            if ($fieldName === '' || $fieldName === 'SecurityID') {
                continue;
            }

            $normalised[$fieldName] = $value;
        }

        return $normalised;
    }

    private function renderPreviewResponse(NewsletterIssue $issue, bool $hasUnsavedChanges = false): HTTPResponse
    {
        $oldThemes = SSViewer::get_themes();
        SSViewer::set_themes(SSViewer::config()->get('themes'));
        Requirements::clear();

        try {
            $html = $issue->renderViewOnlineHTML();
        } finally {
            SSViewer::set_themes($oldThemes);
            Requirements::restore();
        }

        if ($hasUnsavedChanges) {
            $html = $this->injectUnsavedChangesBanner($html);
        }

        $response = HTTPResponse::create($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        return $response;
    }

    private function injectUnsavedChangesBanner(string $html): string
    {
        $message = Convert::raw2xml(_t(__CLASS__ . '.UNSAVED_CHANGES', 'Contains unsaved changes'));
        $banner = '<div role="status" style="position:sticky;top:0;z-index:2147483647;box-sizing:border-box;'
            . 'width:100%;padding:8px 12px;background:#7a3e00;color:#fff;'
            . 'font:600 13px/1.4 Arial,Helvetica,sans-serif;text-align:center;">'
            . $message
            . '</div>';

        $withBanner = preg_replace('/(<body\b[^>]*>)/i', '$1' . "\n" . $banner, $html, 1);

        return $withBanner ?? $banner . $html;
    }
}
