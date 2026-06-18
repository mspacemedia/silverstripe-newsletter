<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Per-recipient delivery + engagement ledger for a sent issue. Records the SMTP
 * outcome, open/click engagement (via the per-record Token used by the tracking
 * pixel and click redirector) and bounce status for reconciliation.
 */
class NewsletterSendRecord extends DataObject
{
    use NewsletterPermissions;

    private static string $table_name = 'Newsletter_SendRecord';

    private static string $singular_name = 'Send record';

    private static string $plural_name = 'Send records';

    private static array $db = [
        'Email' => 'Varchar(254)',
        'Status' => "Enum('Sent,Failed,Bounced','Sent')",
        'SentAt' => 'Datetime',
        'Token' => 'Varchar(64)',
        'OpenCount' => 'Int',
        'ClickCount' => 'Int',
        'FirstOpened' => 'Datetime',
        'LastOpened' => 'Datetime',
        'BouncedAt' => 'Datetime',
        'BounceReason' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Issue' => NewsletterIssue::class,
        'Subscriber' => NewsletterSubscriber::class,
    ];

    private static array $indexes = [
        'Email' => true,
        'IssueID' => true,
        'Token' => true,
    ];

    private static array $summary_fields = [
        'Email' => 'Email',
        'Status' => 'Status',
        'OpenCount' => 'Opens',
        'ClickCount' => 'Clicks',
        'SentAt' => 'Sent at',
    ];

    private static string $default_sort = 'SentAt DESC';

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->Token) {
            $this->Token = bin2hex(random_bytes(16));
        }
    }

    /**
     * Record an email open (tracking pixel hit).
     */
    public function recordOpen(): void
    {
        $now = DBDatetime::now()->getValue();
        $this->OpenCount = (int) $this->OpenCount + 1;
        if (!$this->FirstOpened) {
            $this->FirstOpened = $now;
        }
        $this->LastOpened = $now;
        $this->write();
    }

    /**
     * Record a tracked link click.
     */
    public function recordClick(): void
    {
        $this->ClickCount = (int) $this->ClickCount + 1;
        $this->write();
    }

    public function markBounced(string $reason = ''): void
    {
        $this->Status = 'Bounced';
        $this->BouncedAt = DBDatetime::now()->getValue();
        $this->BounceReason = mb_substr($reason, 0, 255);
        $this->write();
    }

    public function canCreate($member = null, $context = []): bool
    {
        // Created programmatically by the send job, never by hand.
        return false;
    }

    public function canEdit($member = null): bool
    {
        return false;
    }
}
