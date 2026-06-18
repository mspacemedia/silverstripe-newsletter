<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Task;

use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Service\NewsletterSubscriptionManager;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * Processes a bounce notification (DSN). Designed to be piped a raw RFC822
 * message from a mail forwarder, e.g.:
 *
 *   all mail to bounces@… | sake dev/tasks/NewsletterBounceTask
 *
 * For testing, pass ?file=/path/to/bounce.eml. The correlation key is the
 * X-Newsletter-Token header we stamp on every send (quoted back in the DSN);
 * if absent we fall back to the Final-Recipient address. processRaw() is public
 * so a future IMAP/POP poller can reuse the same parsing.
 */
class NewsletterBounceTask extends BuildTask
{
    private static string $segment = 'NewsletterBounceTask';

    protected $title = 'Newsletter: process a bounce (DSN)';

    protected $description = 'Reads a bounce message from STDIN (or ?file=) and marks the send record + subscriber as bounced.';

    public function run($request)
    {
        $print = static fn (string $line): int => printf(Director::is_cli() ? "%s\n" : '<p>%s</p>', $line);

        $file = $request->getVar('file');
        if ($file && is_readable($file)) {
            $raw = (string) file_get_contents($file);
        } else {
            $raw = (string) file_get_contents('php://stdin');
        }

        if (trim($raw) === '') {
            $print('No bounce message received on STDIN (or ?file=).');
            return;
        }

        $print($this->processRaw($raw)
            ? 'Bounce recorded.'
            : 'Could not correlate bounce to a send record.');
    }

    /**
     * Parse a raw bounce message and record it. Returns true if a matching send
     * record was found and marked bounced.
     */
    public function processRaw(string $raw): bool
    {
        $reason = $this->extractReason($raw);
        $record = $this->findRecord($raw);

        if (!$record) {
            return false;
        }

        $record->markBounced($reason);
        NewsletterSubscriptionManager::create()->bounce($record->Email);

        return true;
    }

    private function findRecord(string $raw): ?NewsletterSendRecord
    {
        // Preferred: our own token, quoted back in the DSN's original headers.
        if (preg_match('/X-Newsletter-Token:\s*([0-9a-f]{8,64})/i', $raw, $m)) {
            $record = NewsletterSendRecord::get()->filter('Token', $m[1])->first();
            if ($record) {
                return $record;
            }
        }

        // Fallback: the failed recipient address from the DSN.
        if (preg_match('/(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^>\s]+@[^>\s]+)>?/i', $raw, $m)) {
            return NewsletterSendRecord::get()
                ->filter(['Email' => trim($m[1]), 'Status' => 'Sent'])
                ->sort('SentAt DESC')
                ->first();
        }

        return null;
    }

    private function extractReason(string $raw): string
    {
        if (preg_match('/Diagnostic-Code:\s*(.+)/i', $raw, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/Status:\s*(5\.\d+\.\d+)/i', $raw, $m)) {
            return 'SMTP status ' . trim($m[1]);
        }

        return 'Bounced';
    }
}
