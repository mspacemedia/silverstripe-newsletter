<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Email\MailHelper;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injectable;

/**
 * Sends individual newsletter emails synchronously (e.g. the CMS "Send test"
 * action), decoupled from the batched NewsletterSendJob used for bulk audience
 * sends. Delivery still goes through MailHelper (retry + throttle + logging).
 */
class NewsletterSender
{
    use Injectable;

    /**
     * Render the issue and send a single [TEST] copy to $toEmail immediately.
     */
    public function sendTest(NewsletterIssue $issue, string $toEmail): bool
    {
        $html = NewsletterRenderService::create()->renderEmail($issue, null);

        $email = Email::create()
            ->setFrom($issue->FromEmail ?: Email::config()->get('admin_email'), $issue->FromName ?: null)
            ->setTo($toEmail)
            ->setSubject('[TEST] ' . $issue->Subject);
        $email->setBody($html);

        return MailHelper::send($email, ['IssueID' => $issue->ID, 'Test' => true]);
    }
}
