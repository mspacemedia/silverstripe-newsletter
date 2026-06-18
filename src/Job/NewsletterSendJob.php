<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Job;

use MSpaceMedia\Newsletter\Email\MailHelper;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use MSpaceMedia\Newsletter\Service\NewsletterSender;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Sends a newsletter issue to its audiences in batches, chaining a follow-up job
 * per batch (mirrors UpdateCanvasDataJob). Each recipient is delivered via
 * MailHelper (retry + throttle) and recorded in a NewsletterSendRecord.
 *
 * Test mode sends a single rendered copy to the configured admin email and
 * leaves the issue untouched.
 */
class NewsletterSendJob extends AbstractQueuedJob implements QueuedJob
{
    use Configurable;

    private static int $batch_size = 250;

    public function __construct($issueID = 0, $offset = 0, $testMode = false)
    {
        if ($issueID) {
            $this->issueID = (int) $issueID;
            $this->offset = (int) $offset;
            $this->testMode = (bool) $testMode;
        }
    }

    public function getTitle(): string
    {
        return _t(
            __CLASS__ . '.TITLE',
            'Send newsletter #{id}{mode}',
            [
                'id' => $this->issueID ?: '?',
                'mode' => $this->testMode ? _t(__CLASS__ . '.TEST_MODE_SUFFIX', ' (test)') : '',
            ]
        );
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        // Operate on the draft working copy (the same content the editor/preview
        // shows), so sending never depends on a separate publish step.
        Versioned::set_stage(Versioned::DRAFT);

        $issue = NewsletterIssue::get()->byID((int) $this->issueID);

        if (!$issue) {
            $this->addMessage(_t(
                __CLASS__ . '.ISSUE_NOT_FOUND',
                'Newsletter issue not found: {id}',
                ['id' => $this->issueID]
            ));
            $this->isComplete = true;
            return;
        }

        $service = NewsletterRenderService::create();

        if ($this->testMode) {
            $this->sendTest($issue);
            $this->isComplete = true;
            return;
        }

        $batchSize = (int) static::config()->get('batch_size');
        $offset = (int) $this->offset;

        if ($offset === 0) {
            $issue->lockForSend('Sending');
            $this->totalSteps = $issue->RecipientList()->count();
        }

        $sent = 0;
        foreach ($issue->RecipientList()->limit($batchSize, $offset) as $subscriber) {
            $this->sendToSubscriber($issue, $subscriber, $service);
            $sent++;
        }

        $newOffset = $offset + $sent;
        $this->currentStep = $newOffset;

        if ($sent < $batchSize) {
            $issue->SendStatus = 'Sent';
            $issue->SentDate = DBDatetime::now()->getValue();
            $issue->write();
            $this->addMessage(_t(
                __CLASS__ . '.DELIVERED',
                'Newsletter delivered to one recipient.|Newsletter delivered to {count} recipients.',
                ['count' => $newOffset]
            ));
            $this->isComplete = true;
            return;
        }

        QueuedJobService::singleton()->queueJob(
            new NewsletterSendJob((int) $this->issueID, $newOffset, false)
        );
        $this->addMessage(_t(
            __CLASS__ . '.BATCH_QUEUED',
            'Sent batch; queued next batch from offset {offset}.',
            ['offset' => $newOffset]
        ));
        $this->isComplete = true;
    }

    private function sendToSubscriber(
        NewsletterIssue $issue,
        NewsletterSubscriber $subscriber,
        NewsletterRenderService $service
    ): void {
        // Create the record first so its Token can drive open/click tracking and
        // bounce correlation (carried in the X-Newsletter-Token header).
        $record = NewsletterSendRecord::create();
        $record->IssueID = $issue->ID;
        $record->SubscriberID = $subscriber->ID;
        $record->Email = $subscriber->Email;
        $record->SentAt = DBDatetime::now()->getValue();
        $record->write();

        $html = $service->renderEmail($issue, $subscriber, $record->Token);
        $unsubscribe = Director::absoluteURL('newsletter/unsubscribe/' . $subscriber->UnsubscribeToken);

        $email = Email::create()
            ->setFrom($issue->FromEmail ?: Email::config()->get('admin_email'), $issue->FromName ?: null)
            ->setTo($subscriber->Email)
            ->setSubject($issue->Subject);
        $email->setBody($html);
        $headers = $email->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribe . '>');
        $headers->addTextHeader('X-Newsletter-Token', $record->Token);

        $ok = MailHelper::send($email, [
            'IssueID' => $issue->ID,
            'SubscriberID' => $subscriber->ID,
        ]);

        $record->Status = $ok ? 'Sent' : 'Failed';
        $record->write();
    }

    private function sendTest(NewsletterIssue $issue): void
    {
        $adminEmail = Email::config()->get('admin_email');
        $ok = NewsletterSender::create()->sendTest($issue, $adminEmail);
        $this->addMessage($ok
            ? _t(__CLASS__ . '.TEST_EMAIL_SENT', 'Test email sent to {email}', ['email' => $adminEmail])
            : _t(__CLASS__ . '.TEST_EMAIL_FAILED', 'Test email FAILED to {email}', ['email' => $adminEmail]));
    }
}
