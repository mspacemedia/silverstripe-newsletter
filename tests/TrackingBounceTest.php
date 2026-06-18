<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Task\NewsletterBounceTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class TrackingBounceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function issue(): NewsletterIssue
    {
        $issue = NewsletterIssue::create();
        $issue->Title = 'T';
        $issue->Subject = 'S';
        $issue->write();

        return $issue;
    }

    private function record(NewsletterIssue $issue, string $email, string $status = 'Sent'): NewsletterSendRecord
    {
        $record = NewsletterSendRecord::create();
        $record->IssueID = $issue->ID;
        $record->Email = $email;
        $record->Status = $status;
        $record->write();

        return $record;
    }

    public function testRecordHasTokenAndOpensClicks(): void
    {
        $record = $this->record($this->issue(), 'a@example.com');
        $this->assertNotEmpty($record->Token);

        $record->recordOpen();
        $record->recordOpen();
        $record->recordClick();
        $record = NewsletterSendRecord::get()->byID($record->ID);

        $this->assertSame(2, (int) $record->OpenCount);
        $this->assertSame(1, (int) $record->ClickCount);
        $this->assertNotEmpty($record->FirstOpened);
    }

    public function testMarkBounced(): void
    {
        $record = $this->record($this->issue(), 'b@example.com');
        $record->markBounced('550 No such user');

        $record = NewsletterSendRecord::get()->byID($record->ID);
        $this->assertSame('Bounced', $record->Status);
        $this->assertStringContainsString('550', (string) $record->BounceReason);
    }

    public function testIssueAggregates(): void
    {
        $issue = $this->issue();
        $opened = $this->record($issue, 'o@example.com');
        $opened->recordOpen();
        $this->record($issue, 'p@example.com');
        $this->record($issue, 'q@example.com', 'Bounced');

        $this->assertSame(2, $issue->getSentCount());
        $this->assertSame(1, $issue->getOpenedCount());
        $this->assertSame(1, $issue->getBouncedCount());
        $this->assertSame(50.0, $issue->getOpenRate());
    }

    public function testBounceTaskMatchesByToken(): void
    {
        $issue = $this->issue();
        $sub = NewsletterSubscriber::create();
        $sub->Email = 'bounced@example.com';
        $sub->write();
        $record = $this->record($issue, 'bounced@example.com');

        $dsn = "Final-Recipient: rfc822; bounced@example.com\n"
            . "Status: 5.1.1\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 No such user\n"
            . "X-Newsletter-Token: {$record->Token}\n";

        $this->assertTrue(NewsletterBounceTask::create()->processRaw($dsn));

        $record = NewsletterSendRecord::get()->byID($record->ID);
        $sub = NewsletterSubscriber::get()->byID($sub->ID);
        $this->assertSame('Bounced', $record->Status);
        $this->assertSame('Bounced', $sub->Status);
    }

    public function testBounceTaskFallsBackToFinalRecipient(): void
    {
        $issue = $this->issue();
        $record = $this->record($issue, 'fallback@example.com');

        $dsn = "Final-Recipient: rfc822; fallback@example.com\n"
            . "Diagnostic-Code: smtp; 550 mailbox unavailable\n";

        $this->assertTrue(NewsletterBounceTask::create()->processRaw($dsn));
        $this->assertSame('Bounced', NewsletterSendRecord::get()->byID($record->ID)->Status);
    }

    public function testBounceTaskReturnsFalseWhenNoMatch(): void
    {
        $dsn = "Final-Recipient: rfc822; nobody@example.com\nDiagnostic-Code: smtp; 550\n";
        $this->assertFalse(NewsletterBounceTask::create()->processRaw($dsn));
    }
}
