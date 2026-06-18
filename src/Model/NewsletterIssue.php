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
use MSpaceMedia\Newsletter\Service\NewsletterSender;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
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
    use NewsletterPermissions;

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
    ];

    private static string $default_sort = 'Created DESC';

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->URLToken) {
            $this->URLToken = bin2hex(random_bytes(16));
        }
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['URLToken', 'SentDate', 'SendStatus']);

        $fields->dataFieldByName('Title')
            ?->setTitle('Internal title')
            ?->setDescription('For your reference only — not shown to recipients.');

        $fields->replaceField('FromEmail', EmailField::create('FromEmail', 'From email'));

        $fields->dataFieldByName('PreheaderText')
            ?->setDescription('Short preview text shown after the subject in most inboxes.');

        $fields->addFieldToTab('Root.Audiences', CheckboxSetField::create(
            'Audiences',
            'Send to audiences',
            NewsletterAudience::get()->map('ID', 'Title')
        ));

        $fields->removeByName('BrandID');
        $fields->addFieldToTab('Root.Main', DropdownField::create(
            'BrandID',
            'Brand / theme',
            NewsletterBrand::get()->map('ID', 'Title')
        )->setEmptyString('Default brand'));

        if ($this->exists()) {
            if (class_exists(HistoryViewerField::class)) {
                $fields->addFieldToTab('Root.History', HistoryViewerField::create('NewsletterHistory'));
            }
        }

        if ($this->exists() && !$this->isDraft()) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create(
                'SendStatusDisplay',
                'Send status',
                $this->SendStatus
            ), 'Subject');

            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'ViewOnlineLink',
                sprintf('<p class="message notice">View online: <a href="%1$s" target="_blank">%1$s</a></p>', $this->Link())
            ));

            $fields->addFieldToTab('Root.Statistics.Summary', LiteralField::create(
                'StatsPanel',
                $this->statsPanelHTML()
            ));

            $fields->addFieldToTab('Root.Statistics.Opened', $this->recordGrid(
                'OpenedRecords',
                $this->SendRecords()->filter('OpenCount:GreaterThan', 0)->sort('LastOpened DESC'),
                ['Email' => 'Email', 'OpenCount' => 'Opens', 'LastOpened' => 'Last opened']
            ));

            $fields->addFieldToTab('Root.Statistics.Clicked', $this->recordGrid(
                'ClickedRecords',
                $this->SendRecords()->filter('ClickCount:GreaterThan', 0)->sort('ClickCount DESC'),
                ['Email' => 'Email', 'ClickCount' => 'Clicks']
            ));

            $fields->addFieldToTab('Root.Statistics.Bounced', $this->recordGrid(
                'BouncedRecords',
                $this->SendRecords()->filter('Status', 'Bounced')->sort('BouncedAt DESC'),
                ['Email' => 'Email', 'BouncedAt' => 'Bounced at', 'BounceReason' => 'Reason']
            ));

            $fields->addFieldToTab('Root.Statistics.Failed', $this->recordGrid(
                'FailedRecords',
                $this->SendRecords()->filter('Status', 'Failed')->sort('SentAt DESC'),
                ['Email' => 'Email', 'SentAt' => 'Attempted']
            ));
        }

        return $fields;
    }

    /**
     * Read-only recipient grid for a Statistics sub-tab.
     */
    private function recordGrid(string $name, DataList $list, array $columns): GridField
    {
        $grid = GridField::create($name, false, $list, GridFieldConfig_RecordViewer::create());
        $grid->getConfig()
            ->getComponentByType(GridFieldDataColumns::class)
            ->setDisplayFields($columns);

        return $grid;
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
    private function statsPanelHTML(): string
    {
        $cells = [
            ['Sent', $this->getSentCount()],
            ['Opened', sprintf('%d (%s%%)', $this->getOpenedCount(), $this->getOpenRate())],
            ['Clicked', sprintf('%d (%s%%)', $this->getClickedCount(), $this->getClickRate())],
            ['Bounced', $this->getBouncedCount()],
            ['Failed', $this->getFailedCount()],
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

        return $html . '</div>';
    }

    public function isDraft(): bool
    {
        return $this->SendStatus === 'Draft';
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
            ]);
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
                CustomAction::create('doSendTestNewsletter', 'Send test to me')
                    ->addExtraClass('btn-outline-primary')
            );
            $actions->push(
                CustomAction::create('doSendNewsletter', 'Send to audiences')
                    ->addExtraClass('btn-primary')
                    ->setConfirmation('Send this newsletter to every active subscriber in the targeted audiences?')
            );
        }

        return $actions;
    }

    public function doSendTestNewsletter($data, $form): string
    {
        $adminEmail = Email::config()->get('admin_email');
        $ok = NewsletterSender::create()->sendTest($this, $adminEmail);

        return $ok
            ? 'Test email sent to ' . $adminEmail . '.'
            : 'Test send failed — check the mail log.';
    }

    public function doSendNewsletter($data, $form): string
    {
        QueuedJobService::singleton()->queueJob(new NewsletterSendJob($this->ID, 0, false));

        $this->SendStatus = 'Queued';
        $this->write();

        return 'Newsletter queued for sending to ' . $this->RecipientList()->count() . ' recipient(s).';
    }
}
