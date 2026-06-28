<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Task;

use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\MergeFieldService;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * Read-only diagnostic: dumps what the merge engine actually sees for one
 * subscriber and renders a probe template, so blank-substitution issues can be
 * traced to data vs. engine without going through the CMS preview.
 *
 *   sake dev/tasks/NewsletterMergeDebugTask "email=person@example.com"
 *   (or ?id=14, or no arg for the first subscriber)
 */
class NewsletterMergeDebugTask extends BuildTask
{
    private static string $segment = 'NewsletterMergeDebugTask';

    protected $title = 'Newsletter: merge-field debug';

    protected $description = 'Dumps a subscriber\'s fields and renders a probe template through the {{ … }} engine.';

    public function run($request)
    {
        $eol = Director::is_cli() ? "\n" : '<br>';
        $line = static fn (string $text): int => print($text . $eol);

        $subscriber = $this->resolveSubscriber($request);
        if (!$subscriber) {
            $line('No matching subscriber.');
            return;
        }

        $anchor = $subscriber->getAnchorRecord();

        $line('Subscriber #' . $subscriber->ID);
        $line('  FirstName (->prop) = [' . $subscriber->FirstName . ']');
        $line('  FirstName (getField) = [' . $subscriber->getField('FirstName') . ']');
        $line('  Surname  (getField) = [' . $subscriber->getField('Surname') . ']');
        $line('  Email    (getField) = [' . $subscriber->getField('Email') . ']');
        $line('  getDisplayName() = [' . $subscriber->getDisplayName() . ']');
        $line('  MergeData = ' . json_encode($subscriber->getMergeArray()));
        $line('  AnchorID = ' . (int) $subscriber->AnchorID . ', AnchorClass = [' . (string) $subscriber->AnchorClass . ']');
        $line('  getAnchorRecord() = ' . ($anchor ? get_class($anchor) . '#' . $anchor->ID : 'null'));

        $probe = 'FN={{ FirstName }} | SN={{ Surname }} | NM={{ Name }} | EM={{ Email }} | OC={{ ORDERCOUNT }}';
        $line('');
        $line('Probe template : ' . $probe);
        $line('Rendered       : ' . MergeFieldService::create()->render($probe, $subscriber));
    }

    private function resolveSubscriber($request): ?NewsletterSubscriber
    {
        if ($id = (int) $request->getVar('id')) {
            return NewsletterSubscriber::get()->byID($id);
        }
        if ($email = trim((string) $request->getVar('email'))) {
            return NewsletterSubscriber::get()->filter('Email', $email)->first();
        }

        return NewsletterSubscriber::get()->first();
    }
}
