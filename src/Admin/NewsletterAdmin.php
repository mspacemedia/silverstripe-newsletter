<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Admin;

use DNADesign\Elemental\Forms\EditFormFactory;
use DNADesign\Elemental\Models\BaseElement;
use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterMergeField;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\MergeExpression\Introspector;
use MSpaceMedia\Newsletter\Service\MergeFieldService;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use MSpaceMedia\Newsletter\Service\NewsletterSegmentService;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
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
        NewsletterMergeField::class,
        NewsletterSendRecord::class,
    ];

    private static array $required_permission_codes = ['MANAGE_NEWSLETTERS'];

    private static array $allowed_actions = [
        'ImportForm',
        'SearchForm',
        'cmsPreview',
        'cmsPreviewUnsaved',
        'mergeSchema',
        'mergePreview',
        'segmentEstimate',
    ];

    private static array $url_handlers = [
        '$ModelClass/cmsPreviewUnsaved/$ID' => 'cmsPreviewUnsaved',
        '$ModelClass/cmsPreview/$ID' => 'cmsPreview',
        '$ModelClass/mergeSchema' => 'mergeSchema',
        '$ModelClass/mergePreview' => 'mergePreview',
        '$ModelClass/segmentEstimate' => 'segmentEstimate',
        '$ModelClass/$Action' => 'handleAction',
    ];

    protected function init()
    {
        parent::init();

        Requirements::javascript('mspacemedia/silverstripe-newsletter:client/dist/newsletter-preview.js');
        Requirements::javascript('mspacemedia/silverstripe-newsletter:client/dist/newsletter-mergefield.js');
        Requirements::css('mspacemedia/silverstripe-newsletter:client/dist/newsletter-mergefield.css');
    }

    /**
     * Describe the allowlisted anchor classes (relations + fields) for the merge
     * field builder. Returns every exposed class so the UI can drill into
     * relations without extra round-trips.
     */
    public function mergeSchema(HTTPRequest $request): HTTPResponse
    {
        if (!Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(403);
        }

        $allowlist = (array) Config::inst()->get(MergeFieldService::class, 'allowlist');
        $introspector = Introspector::create($allowlist);

        $classes = [];
        foreach (array_keys($allowlist) as $class) {
            $classes[str_replace('\\', '-', $class)] = $introspector->describe($class);
        }

        $anchorClass = (string) Config::inst()->get(NewsletterSubscriber::class, 'anchor_class');

        return $this->jsonResponse([
            'anchorClass' => str_replace('\\', '-', $anchorClass),
            'classes' => $classes,
        ]);
    }

    /**
     * Evaluate an expression against a sample anchor record and return the
     * formatted value, so editors can preview a merge field as they build it.
     */
    public function mergePreview(HTTPRequest $request): HTTPResponse
    {
        if (!Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(403);
        }

        $expression = trim((string) $request->getVar('expression'));
        if ($expression === '') {
            return $this->jsonResponse(['ok' => true, 'value' => '', 'record' => null]);
        }

        $anchorClass = (string) Config::inst()->get(NewsletterSubscriber::class, 'anchor_class');
        if (!is_subclass_of($anchorClass, DataObject::class)) {
            return $this->jsonResponse(['ok' => false, 'error' => 'No anchor class configured.']);
        }

        $record = $this->sampleAnchor($anchorClass, (int) $request->getVar('recordID'));
        if (!$record) {
            return $this->jsonResponse([
                'ok' => false,
                'error' => _t(__CLASS__ . '.NO_SAMPLE', 'No {class} records to preview against.', [
                    'class' => $anchorClass,
                ]),
            ]);
        }

        try {
            $value = MergeFieldService::create()->evaluate($expression, $record, $this->builtinsFromRecord($record));
        } catch (\Throwable $e) {
            return $this->jsonResponse(['ok' => false, 'error' => $e->getMessage(), 'record' => $this->recordLabel($record)]);
        }

        return $this->jsonResponse([
            'ok' => true,
            'value' => is_scalar($value) ? (string) $value : '',
            'record' => $this->recordLabel($record),
            'recordID' => $record->ID,
        ]);
    }

    /**
     * Estimate how many active subscribers a (possibly unsaved) segment
     * expression matches, for the builder's "Estimate matches" button.
     */
    public function segmentEstimate(HTTPRequest $request): HTTPResponse
    {
        if (!Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(403);
        }

        $expression = trim((string) $request->getVar('expression'));
        if ($expression === '') {
            return $this->jsonResponse(['ok' => true, 'matched' => 0, 'total' => 0]);
        }

        try {
            $result = NewsletterSegmentService::create()->estimate(
                $expression,
                (int) $request->getVar('baseAudienceID') ?: null
            );
        } catch (\Throwable $e) {
            return $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }

        return $this->jsonResponse(['ok' => true] + $result);
    }

    private function sampleAnchor(string $class, int $recordID): ?DataObject
    {
        if ($recordID > 0) {
            $record = DataObject::get($class)->byID($recordID);
            if ($record) {
                return $record;
            }
        }

        $list = DataObject::get($class);
        $count = $list->count();
        if ($count === 0) {
            return null;
        }

        return $list->limit(1, random_int(0, $count - 1))->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function builtinsFromRecord(DataObject $record): array
    {
        $builtins = [];
        foreach (['FirstName' => ['firstname', 'fname'], 'Surname' => ['surname', 'lastname', 'lname'], 'Email' => ['email']] as $field => $keys) {
            if ($record->hasField($field)) {
                foreach ($keys as $key) {
                    $builtins[$key] = $record->getField($field);
                }
            }
        }

        return $builtins;
    }

    private function recordLabel(DataObject $record): string
    {
        $title = trim((string) $record->getTitle());

        return $title !== '' ? $title : (self::shortClass(get_class($record)) . ' #' . $record->ID);
    }

    private static function shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data));
        $response->addHeader('Content-Type', 'application/json');

        return $response;
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

        return Versioned::withVersionedMode(function () use ($id, $stage, $request): HTTPResponse {
            Versioned::set_stage($stage);

            $issue = Versioned::get_by_stage(NewsletterIssue::class, $stage)->byID($id);
            if (!$issue || !$issue->exists()) {
                return $this->httpError(404, _t(__CLASS__ . '.NEWSLETTER_NOT_FOUND', 'Newsletter not found.'));
            }

            $subscriber = $this->resolvePreviewSubscriber($issue, (string) $request->getVar('previewSubscriber'));

            return $this->renderPreviewResponse($issue, false, $subscriber, $stage);
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

    private function renderPreviewResponse(
        NewsletterIssue $issue,
        bool $hasUnsavedChanges = false,
        ?NewsletterSubscriber $subscriber = null,
        string $stage = Versioned::DRAFT
    ): HTTPResponse {
        $oldThemes = SSViewer::get_themes();
        SSViewer::set_themes(SSViewer::config()->get('themes'));
        Requirements::clear();

        try {
            // Render personalised (merge tags + {{ }} resolved, no tracking) when
            // previewing as a subscriber; otherwise the generic view-online HTML.
            $html = $subscriber
                ? NewsletterRenderService::create()->renderEmail($issue, $subscriber)
                : $issue->renderViewOnlineHTML();
        } finally {
            SSViewer::set_themes($oldThemes);
            Requirements::restore();
        }

        if ($hasUnsavedChanges) {
            $html = $this->injectUnsavedChangesBanner($html);
        }
        $html = $this->injectPreviewSubscriberBar($html, $subscriber, $stage);

        $response = HTTPResponse::create($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        return $response;
    }

    /**
     * Resolve the subscriber to preview as from the previewSubscriber request var
     * ('random', a subscriber ID, or empty for none). Random draws from the
     * issue's recipients, falling back to any active subscriber.
     */
    private function resolvePreviewSubscriber(NewsletterIssue $issue, string $param): ?NewsletterSubscriber
    {
        $param = trim($param);
        if ($param === '' || $param === '0') {
            return null;
        }

        if (ctype_digit($param)) {
            return NewsletterSubscriber::get()->byID((int) $param);
        }

        $pool = $issue->RecipientList();
        if (!$pool->exists()) {
            $pool = NewsletterSubscriber::get()->filter('Status', 'Active');
        }

        $count = $pool->count();

        return $count > 0 ? $pool->limit(1, random_int(0, $count - 1))->first() : null;
    }

    /**
     * Inject the "preview as subscriber" toolbar. Its links live inside the
     * preview iframe and just reload it with a different previewSubscriber, so no
     * parent-frame scripting is needed.
     */
    private function injectPreviewSubscriberBar(string $html, ?NewsletterSubscriber $subscriber, string $stage): string
    {
        $stageParam = 'stage=' . ($stage === Versioned::LIVE ? Versioned::LIVE : Versioned::DRAFT);
        $randomHref = '?previewSubscriber=random&amp;' . $stageParam;
        $clearHref = '?' . $stageParam;

        $link = static fn (string $href, string $label): string =>
            '<a href="' . $href . '" style="color:#fff;text-decoration:underline;margin-left:12px;">'
            . Convert::raw2xml($label) . '</a>';

        if ($subscriber) {
            $who = trim((string) $subscriber->getDisplayName() . ' <' . $subscriber->Email . '>');
            $label = _t(__CLASS__ . '.PREVIEW_AS', 'Previewing as:') . ' ' . $who;
            $controls = $link($randomHref, _t(__CLASS__ . '.PREVIEW_ANOTHER', 'Another'))
                . $link($clearHref, _t(__CLASS__ . '.PREVIEW_NONE', 'No personalisation'));
        } else {
            $label = _t(__CLASS__ . '.PREVIEW_GENERIC', 'No personalisation.');
            $controls = $link($randomHref, _t(__CLASS__ . '.PREVIEW_RANDOM', 'Preview as a random subscriber'));
        }

        $bar = '<div role="status" style="position:sticky;top:0;z-index:2147483646;box-sizing:border-box;'
            . 'width:100%;padding:8px 12px;background:#0b6e99;color:#fff;'
            . 'font:600 13px/1.4 Arial,Helvetica,sans-serif;">'
            . Convert::raw2xml($label) . $controls
            . '</div>';

        $withBar = preg_replace('/(<body\b[^>]*>)/i', '$1' . "\n" . $bar, $html, 1);

        return $withBar ?? $bar . $html;
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
