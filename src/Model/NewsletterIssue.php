<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use DNADesign\Elemental\Models\ElementalArea;
use LeKoala\CmsActions\CustomAction;
use MSpaceMedia\Newsletter\Admin\NewsletterAdmin;
use MSpaceMedia\Newsletter\Elements\BoxedTextBlock;
use MSpaceMedia\Newsletter\Elements\ButtonBlock;
use MSpaceMedia\Newsletter\Elements\CodeBlock;
use MSpaceMedia\Newsletter\Elements\ColumnsBlock;
use MSpaceMedia\Newsletter\Elements\DividerBlock;
use MSpaceMedia\Newsletter\Elements\FooterBlock;
use MSpaceMedia\Newsletter\Elements\HeaderBlock;
use MSpaceMedia\Newsletter\Elements\HeadingBlock;
use MSpaceMedia\Newsletter\Elements\ImageBlock;
use MSpaceMedia\Newsletter\Elements\ImageCaptionBlock;
use MSpaceMedia\Newsletter\Elements\ImageCardBlock;
use MSpaceMedia\Newsletter\Elements\ImageGroupBlock;
use MSpaceMedia\Newsletter\Elements\LogoBlock;
use MSpaceMedia\Newsletter\Elements\SocialFollowBlock;
use MSpaceMedia\Newsletter\Elements\SpacerBlock;
use MSpaceMedia\Newsletter\Elements\TextBlock;
use MSpaceMedia\Newsletter\Elements\VideoBlock;
use MSpaceMedia\Newsletter\Job\NewsletterSendJob;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use MSpaceMedia\Newsletter\Service\NewsletterSender;
use MSpaceMedia\Newsletter\Service\NewsletterStarterService;
use SilverStripe\Core\Convert;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\CMSPreviewable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\VersionedAdmin\Forms\HistoryViewerField;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * A composed newsletter. Content is built from Elemental blocks (via
 * ElementalAreasExtension); it targets one or more audiences and is delivered by
 * NewsletterSendJob. The public "view online" page is served from URLToken.
 *
 * @property int $ElementalAreaID
 * @method \DNADesign\Elemental\Models\ElementalArea ElementalArea()
 */
class NewsletterIssue extends DataObject implements CMSPreviewable
{
    use NewsletterPermissions {
        canEdit as protected canEditWithNewsletterPermission;
        canDelete as protected canDeleteWithNewsletterPermission;
    }

    private static string $table_name = 'Newsletter_Issue';

    private static string $singular_name = 'Newsletter';

    private static string $plural_name = 'Newsletters';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Subject' => 'Varchar(255)',
        'PreheaderText' => 'Varchar(255)',
        'FromName' => 'Varchar(255)',
        'FromEmail' => 'Varchar(254)',
        'SendStatus' => "Enum('Draft,Queued,Sending,Sent,Cancelled','Draft')",
        'URLToken' => 'Varchar(64)',
        'SentDate' => 'Datetime',
        'SentHTML' => 'HTMLText',
        // A reusable layout offered in the "Start from" picker; never sent.
        'IsTemplate' => 'Boolean',
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    private static bool $show_stage_link = true;

    private static bool $show_live_link = true;

    private static array $has_one = [
        'ElementalArea' => ElementalArea::class,
        'Brand' => NewsletterBrand::class,
    ];

    private static array $owns = [
        'ElementalArea',
    ];

    private static array $cascade_deletes = [
        'ElementalArea',
    ];

    private static array $cascade_duplicates = [
        'ElementalArea',
    ];

    private static array $many_many = [
        'Audiences' => NewsletterAudience::class,
    ];

    /**
     * Restrict the block picker to email-safe newsletter blocks only.
     */
    private static array $allowed_elements = [
        HeaderBlock::class,
        HeadingBlock::class,
        TextBlock::class,
        BoxedTextBlock::class,
        ColumnsBlock::class,
        ImageBlock::class,
        ImageCaptionBlock::class,
        ImageGroupBlock::class,
        ImageCardBlock::class,
        ButtonBlock::class,
        VideoBlock::class,
        SocialFollowBlock::class,
        LogoBlock::class,
        CodeBlock::class,
        DividerBlock::class,
        SpacerBlock::class,
        FooterBlock::class,
    ];

    private static array $indexes = [
        'URLToken' => true,
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'Subject' => 'Subject',
        'SendStatus' => 'Status',
        'SentDate' => 'Sent',
        'IsTemplate.Nice' => 'Template',
    ];

    private static string $default_sort = 'Created DESC';

    /**
     * Transient "Start from" choice from the new-issue form, applied once the
     * record (and its block area) exists.
     */
    protected ?string $startFrom = null;

    public function setStartFrom(?string $choice): static
    {
        $this->startFrom = $choice ?: null;

        return $this;
    }

    public function getStartFrom(): ?string
    {
        return $this->startFrom;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->URLToken) {
            $this->URLToken = bin2hex(random_bytes(16));
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if ($this->startFrom) {
            // Clear first: applying a layout writes this record again.
            $choice = $this->startFrom;
            $this->startFrom = null;
            NewsletterStarterService::create()->apply($this, $choice);
        }
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['URLToken', 'SentDate', 'SendStatus', 'SentHTML', 'IsTemplate']);

        if (!$this->exists()) {
            $options = NewsletterStarterService::create()->options();
            if ($options) {
                $fields->addFieldToTab('Root.Main', DropdownField::create(
                    'StartFrom',
                    _t(__CLASS__ . '.START_FROM', 'Start from'),
                    $options
                )
                    ->setEmptyString(_t(__CLASS__ . '.START_BLANK', 'Blank newsletter'))
                    ->setDescription(_t(
                        __CLASS__ . '.START_FROM_DESCRIPTION',
                        'Pre-fills the content blocks with a ready-made layout, styled by the brand/theme. Edit everything after saving.'
                    )), 'Subject');
            }
        }

        $fields->addFieldToTab('Root.Main', CheckboxField::create(
            'IsTemplate',
            _t(__CLASS__ . '.IS_TEMPLATE', 'Use as a template')
        )->setDescription(_t(
            __CLASS__ . '.IS_TEMPLATE_DESCRIPTION',
            'Offers this newsletter\'s blocks in the "Start from" list for new newsletters. Templates cannot be sent.'
        )));

        $fields->dataFieldByName('Title')
            ?->setTitle(_t(__CLASS__ . '.INTERNAL_TITLE', 'Internal title'))
            ?->setDescription(_t(
                __CLASS__ . '.INTERNAL_TITLE_DESCRIPTION',
                'For your reference only - not shown to recipients.'
            ));

        $fields->replaceField('FromEmail', EmailField::create(
            'FromEmail',
            _t(__CLASS__ . '.FROM_EMAIL', 'From email')
        ));

        $fields->dataFieldByName('PreheaderText')
            ?->setDescription(_t(
                __CLASS__ . '.PREHEADER_DESCRIPTION',
                'Short preview text shown after the subject in most inboxes.'
            ));

        $fields->addFieldToTab('Root.Audiences', CheckboxSetField::create(
            'Audiences',
            _t(__CLASS__ . '.SEND_TO_AUDIENCES', 'Send to audiences'),
            NewsletterAudience::get()->map('ID', 'Title')
        ));
        $fields->addFieldToTab('Root.Audiences', LiteralField::create(
            'SelectedRecipientsSummary',
            $this->recipientPanelHTML()->forTemplate()
        ));

        if ($this->exists() && $this->Audiences()->exists()) {
            $fields->addFieldToTab('Root.Audiences', $this->recordGrid(
                'SelectedRecipients',
                $this->RecipientList(),
                [
                    'Email' => _t(__CLASS__ . '.EMAIL', 'Email'),
                    'getDisplayName' => _t(__CLASS__ . '.NAME', 'Name'),
                ],
                _t(__CLASS__ . '.SELECTED_RECIPIENTS', 'Selected recipients')
            ));
        }

        $fields->removeByName('BrandID');
        $fields->addFieldToTab('Root.Main', DropdownField::create(
            'BrandID',
            _t(__CLASS__ . '.BRAND_THEME', 'Brand / theme'),
            NewsletterBrand::get()->map('ID', 'Title')
        )->setEmptyString(_t(__CLASS__ . '.DEFAULT_BRAND', 'Default brand')));

        if ($this->exists()) {
            if (class_exists(HistoryViewerField::class)) {
                $fields->addFieldToTab('Root.History', HistoryViewerField::create('NewsletterHistory'));
            }
        }

        if ($this->exists() && !$this->isDraft()) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create(
                'SendStatusDisplay',
                _t(__CLASS__ . '.SEND_STATUS', 'Send status'),
                $this->SendStatus
            ), 'Subject');

            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'ViewOnlineLink',
                sprintf(
                    '<p class="message notice">%s: <a href="%s" target="_blank">%s</a></p>',
                    Convert::raw2xml(_t(__CLASS__ . '.VIEW_ONLINE', 'View online')),
                    Convert::raw2att($this->Link()),
                    Convert::raw2xml($this->Link())
                )
            ));

            $fields->addFieldToTab('Root.Statistics.Summary', LiteralField::create(
                'StatsPanel',
                $this->statsPanelHTML()->forTemplate()
            ));

            $fields->addFieldToTab('Root.Statistics.Opened', $this->recordGrid(
                'OpenedRecords',
                $this->SendRecords()->filter('OpenCount:GreaterThan', 0)->sort('LastOpened DESC'),
                [
                    'Email' => _t(__CLASS__ . '.EMAIL', 'Email'),
                    'OpenCount' => _t(__CLASS__ . '.OPENS', 'Opens'),
                    'LastOpened' => _t(__CLASS__ . '.LAST_OPENED', 'Last opened'),
                ]
            ));

            $fields->addFieldToTab('Root.Statistics.Clicked', $this->recordGrid(
                'ClickedRecords',
                $this->SendRecords()->filter('ClickCount:GreaterThan', 0)->sort('ClickCount DESC'),
                [
                    'Email' => _t(__CLASS__ . '.EMAIL', 'Email'),
                    'ClickCount' => _t(__CLASS__ . '.CLICKS', 'Clicks'),
                ]
            ));

            $fields->addFieldToTab('Root.Statistics.Bounced', $this->recordGrid(
                'BouncedRecords',
                $this->SendRecords()->filter('Status', 'Bounced')->sort('BouncedAt DESC'),
                [
                    'Email' => _t(__CLASS__ . '.EMAIL', 'Email'),
                    'BouncedAt' => _t(__CLASS__ . '.BOUNCED_AT', 'Bounced at'),
                    'BounceReason' => _t(__CLASS__ . '.REASON', 'Reason'),
                ]
            ));

            $fields->addFieldToTab('Root.Statistics.Failed', $this->recordGrid(
                'FailedRecords',
                $this->SendRecords()->filter('Status', 'Failed')->sort('SentAt DESC'),
                [
                    'Email' => _t(__CLASS__ . '.EMAIL', 'Email'),
                    'SentAt' => _t(__CLASS__ . '.ATTEMPTED', 'Attempted'),
                ]
            ));
        }

        return $fields;
    }

    /**
     * Read-only recipient grid for a Statistics sub-tab.
     */
    private function recordGrid(string $name, DataList $list, array $columns, string|bool $title = false): GridField
    {
        $grid = GridField::create($name, $title, $list, GridFieldConfig_RecordViewer::create());
        $grid->getConfig()
            ->getComponentByType(GridFieldDataColumns::class)
            ->setDisplayFields($columns);

        return $grid;
    }

    /**
     * Summarise the active, deduplicated recipients implied by the selected audiences.
     */
    private function recipientPanelHTML(): DBHTMLText
    {
        if (!$this->exists()) {
            $message = _t(
                __CLASS__ . '.RECIPIENTS_SAVE_FIRST',
                'Save the newsletter before reviewing selected recipients.'
            );
        } elseif (!$this->Audiences()->exists()) {
            $message = _t(
                __CLASS__ . '.RECIPIENTS_NO_AUDIENCES',
                'Select and save one or more audiences to show recipients.'
            );
        } else {
            $message = _t(
                __CLASS__ . '.RECIPIENTS_SUMMARY',
                'This newsletter will be sent to {count} active recipient(s) from {audiences} selected audience(s).',
                [
                    'count' => $this->RecipientList()->count(),
                    'audiences' => $this->Audiences()->count(),
                ]
            );
        }

        return DBHTMLText::create()->setValue(
            '<p class="message notice">' . Convert::raw2xml($message) . '</p>'
        );
    }

    public function PreviewLink($action = null)
    {
        if (!$this->isInDB()) {
            return null;
        }

        $admin = NewsletterAdmin::singleton();
        $link = Controller::join_links(
            $admin->getLinkForModelClass(static::class),
            'cmsPreview',
            $this->ID,
            $action
        );
        $this->extend('updatePreviewLink', $link, $action);

        return $link;
    }

    public function AbsoluteLink($action = null)
    {
        return $this->PreviewLink($action);
    }

    public function CMSEditLink()
    {
        if (!$this->isInDB()) {
            return null;
        }

        return NewsletterAdmin::singleton()->getCMSEditLinkForManagedDataObject($this);
    }

    public function getMimeType()
    {
        return 'text/html';
    }

    /**
     * Per-issue engagement summary — the seed of the in-CMS dashboard.
     */
    private function statsPanelHTML(): DBHTMLText
    {
        $cells = [
            [_t(__CLASS__ . '.SENT', 'Sent'), $this->getSentCount()],
            [_t(__CLASS__ . '.OPENED', 'Opened'), _t(
                __CLASS__ . '.COUNT_WITH_PERCENT',
                '{count} ({percentage}%)',
                [
                    'count' => $this->getOpenedCount(),
                    'percentage' => $this->getOpenRate(),
                ]
            )],
            [_t(__CLASS__ . '.CLICKED', 'Clicked'), _t(
                __CLASS__ . '.COUNT_WITH_PERCENT',
                '{count} ({percentage}%)',
                [
                    'count' => $this->getClickedCount(),
                    'percentage' => $this->getClickRate(),
                ]
            )],
            [_t(__CLASS__ . '.BOUNCED', 'Bounced'), $this->getBouncedCount()],
            [_t(__CLASS__ . '.FAILED', 'Failed'), $this->getFailedCount()],
        ];

        $html = '<div class="newsletter-stats" style="display:flex;flex-wrap:wrap;gap:16px;margin:8px 0;">';
        foreach ($cells as [$label, $value]) {
            $html .= sprintf(
                '<div style="min-width:110px;padding:12px 16px;background:#f4f6f8;border-radius:6px;">'
                . '<div style="font-size:22px;font-weight:bold;">%s</div>'
                . '<div style="font-size:12px;color:#667;text-transform:uppercase;">%s</div></div>',
                htmlspecialchars((string) $value),
                htmlspecialchars((string) $label)
            );
        }

        return DBHTMLText::create()->setValue($html . '</div>');
    }

    public function isDraft(): bool
    {
        return !$this->SendStatus || $this->SendStatus === 'Draft';
    }

    public function canEdit($member = null): bool
    {
        return $this->isDraft() && $this->canEditWithNewsletterPermission($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->isDraft() && $this->canDeleteWithNewsletterPermission($member);
    }

    public function hasSentSnapshot(): bool
    {
        return trim((string) $this->SentHTML) !== '';
    }

    public function captureSentSnapshot(): void
    {
        $this->SentHTML = NewsletterRenderService::create()->renderSnapshot($this);
    }

    public function renderViewOnlineHTML(): string
    {
        return NewsletterRenderService::create()->renderWeb($this);
    }

    public function lockForSend(string $status = 'Queued'): void
    {
        if (!$this->hasSentSnapshot()) {
            $this->captureSentSnapshot();
        }

        $this->SendStatus = $status;
        $this->write();
    }

    /**
     * The brand to render with — the issue's chosen brand, or the default.
     */
    public function EffectiveBrand(): NewsletterBrand
    {
        return $this->Brand()->exists() ? $this->Brand() : NewsletterBrand::current();
    }

    /**
     * Public, absolute "view online" URL.
     */
    public function Link(): string
    {
        return Director::absoluteURL('newsletter/view/' . $this->URLToken);
    }

    /**
     * Distinct, active subscribers across all targeted audiences.
     */
    public function RecipientList(): DataList
    {
        $audienceIDs = $this->Audiences()->column('ID');

        if (empty($audienceIDs)) {
            return NewsletterSubscriber::get()->byIDs([0]);
        }

        return NewsletterSubscriber::get()
            ->filter([
                'Audiences.ID' => $audienceIDs,
                'Status' => 'Active',
            ])
            ->distinct(true)
            ->sort(['Email' => 'ASC', 'ID' => 'ASC']);
    }

    /**
     * All delivery/engagement records for this issue.
     */
    public function SendRecords(): DataList
    {
        return NewsletterSendRecord::get()->filter('IssueID', $this->ID);
    }

    public function getSentCount(): int
    {
        return $this->SendRecords()->filter('Status', 'Sent')->count();
    }

    public function getFailedCount(): int
    {
        return $this->SendRecords()->filter('Status', 'Failed')->count();
    }

    public function getBouncedCount(): int
    {
        return $this->SendRecords()->filter('Status', 'Bounced')->count();
    }

    public function getOpenedCount(): int
    {
        return $this->SendRecords()->filter('OpenCount:GreaterThan', 0)->count();
    }

    public function getClickedCount(): int
    {
        return $this->SendRecords()->filter('ClickCount:GreaterThan', 0)->count();
    }

    public function getOpenRate(): float
    {
        $sent = $this->getSentCount();

        return $sent > 0 ? round($this->getOpenedCount() / $sent * 100, 1) : 0.0;
    }

    public function getClickRate(): float
    {
        $sent = $this->getSentCount();

        return $sent > 0 ? round($this->getClickedCount() / $sent * 100, 1) : 0.0;
    }

    public function getCMSActions(): FieldList
    {
        $actions = parent::getCMSActions();

        if ($this->exists() && $this->isDraft() && class_exists(CustomAction::class)) {
            $actions->push(
                CustomAction::create('doSendTestNewsletter', _t(__CLASS__ . '.SEND_TEST_TO_ME', 'Send test to me'))
                    ->addExtraClass('btn-outline-primary')
            );
            // Templates can be test-sent to check them in an inbox, never sent live.
            if (!$this->IsTemplate) {
                $actions->push(
                    CustomAction::create('doSendNewsletter', _t(__CLASS__ . '.SEND_TO_AUDIENCES_ACTION', 'Send to audiences'))
                        ->addExtraClass('btn-primary')
                        ->setConfirmation(_t(
                            __CLASS__ . '.SEND_CONFIRMATION',
                            'Send this newsletter to every active subscriber in the targeted audiences?'
                        ))
                );
            }
        }

        return $actions;
    }

    public function doSendTestNewsletter($data, $form): string
    {
        $adminEmail = Email::config()->get('admin_email');
        $ok = NewsletterSender::create()->sendTest($this, $adminEmail);

        return $ok
            ? _t(__CLASS__ . '.TEST_EMAIL_SENT', 'Test email sent to {email}.', ['email' => $adminEmail])
            : _t(__CLASS__ . '.TEST_SEND_FAILED', 'Test send failed - check the mail log.');
    }

    public function doSendNewsletter($data, $form): string
    {
        if ($this->IsTemplate) {
            return _t(
                __CLASS__ . '.TEMPLATE_NOT_SENDABLE',
                'This newsletter is a template. Start a new newsletter from it to send.'
            );
        }

        $this->lockForSend('Queued');
        QueuedJobService::singleton()->queueJob(new NewsletterSendJob($this->ID, 0, false));

        return _t(
            __CLASS__ . '.NEWSLETTER_QUEUED',
            'Newsletter queued for sending to one recipient.|Newsletter queued for sending to {count} recipients.',
            ['count' => $this->RecipientList()->count()]
        );
    }
}
