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

    protected $title = null;

    protected $description = null;

    public function getTitle()
    {
        return _t(__CLASS__ . '.TITLE', 'Newsletter: send an issue');
    }

    public function getDescription()
    {
        return _t(
            __CLASS__ . '.DESCRIPTION',
            'Queues a NewsletterSendJob. Params: id=<IssueID>, test=true (send a single copy to the admin email).'
        );
    }

    public function run($request)
    {
        $print = static fn (string $line): int => printf(Director::is_cli() ? "%s\n" : '<p>%s</p>', $line);

        $id = (int) $request->getVar('id');
        $test = $request->getVar('test') === 'true';

        if (!$id) {
            $print(_t(
                __CLASS__ . '.PROVIDE_ISSUE_ID',
                'Provide an issue id, e.g. ?id=1 (add &test=true for a test send).'
            ));
            return;
        }

        QueuedJobService::singleton()->queueJob(new NewsletterSendJob($id, 0, $test));
        $print(_t(
            __CLASS__ . '.QUEUED_SEND',
            'Queued {mode} send for issue #{id}.',
            [
                'mode' => $test
                    ? _t(__CLASS__ . '.MODE_TEST', 'test')
                    : _t(__CLASS__ . '.MODE_LIVE', 'live'),
                'id' => $id,
            ]
        ));
    }
}
