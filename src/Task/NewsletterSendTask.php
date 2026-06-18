<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Task;

use MSpaceMedia\Newsletter\Job\NewsletterSendJob;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queues a send job for a newsletter issue.
 * Usage: dev/tasks/NewsletterSendTask?id=<IssueID>&test=true
 */
class NewsletterSendTask extends BuildTask
{
    private static string $segment = 'NewsletterSendTask';

    protected $title = 'Newsletter: send an issue';

    protected $description = 'Queues a NewsletterSendJob. Params: id=<IssueID>, test=true (send a single copy to the admin email).';

    public function run($request)
    {
        $print = static fn (string $line): int => printf(Director::is_cli() ? "%s\n" : '<p>%s</p>', $line);

        $id = (int) $request->getVar('id');
        $test = $request->getVar('test') === 'true';

        if (!$id) {
            $print('Provide an issue id, e.g. ?id=1 (add &test=true for a test send).');
            return;
        }

        QueuedJobService::singleton()->queueJob(new NewsletterSendJob($id, 0, $test));
        $print(sprintf('Queued %s send for issue #%d.', $test ? 'test' : 'live', $id));
    }
}
