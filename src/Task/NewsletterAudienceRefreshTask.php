<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Task;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Source\AudienceSourceProvider;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;

/**
 * Refreshes dynamic audiences from their registered source providers. Upserts
 * subscribers deduped by email, refreshes name/merge data, attaches them to the
 * provider's audience (auto-created on first run), and never re-activates a
 * subscriber that has unsubscribed or bounced.
 *
 * Register providers via config, e.g.:
 *   MSpaceMedia\Newsletter\Task\NewsletterAudienceRefreshTask:
 *     providers:
 *       - App\Newsletter\RetailOrderAudienceProvider
 */
class NewsletterAudienceRefreshTask extends BuildTask
{
    use Configurable;

    private static string $segment = 'NewsletterAudienceRefreshTask';

    /**
     * @var array<int, string>
     */
    private static array $providers = [];

    protected $title = null;

    protected $description = null;

    public function getTitle()
    {
        return _t(__CLASS__ . '.TITLE', 'Newsletter: refresh dynamic audiences');
    }

    public function getDescription()
    {
        return _t(
            __CLASS__ . '.DESCRIPTION',
            'Pulls subscribers from registered AudienceSourceProviders into their audiences (deduped by email).'
        );
    }

    public function run($request)
    {
        $print = static fn (string $line): int => printf(Director::is_cli() ? "%s\n" : '<p>%s</p>', $line);

        $providerClasses = (array) static::config()->get('providers');

        if (empty($providerClasses)) {
            $print(_t(__CLASS__ . '.NO_PROVIDERS', 'No audience source providers registered.'));
            return;
        }

        foreach ($providerClasses as $class) {
            if (!is_a($class, AudienceSourceProvider::class, true)) {
                $print(_t(
                    __CLASS__ . '.SKIPPING_NOT_PROVIDER',
                    'Skipping {class}: not an AudienceSourceProvider.',
                    ['class' => $class]
                ));
                continue;
            }

            /** @var AudienceSourceProvider $provider */
            $provider = Injector::inst()->create($class);
            $audience = $this->audienceFor($provider);

            $added = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($provider->getSubscribers() as $row) {
                switch ($this->upsert($audience, $row)) {
                    case 'added':
                        $added++;
                        break;
                    case 'updated':
                        $updated++;
                        break;
                    default:
                        $skipped++;
                }
            }

            $print(_t(
                __CLASS__ . '.REFRESH_SUMMARY',
                '{key} -> audience "{audience}": {added} added, {updated} updated, {skipped} skipped.',
                [
                    'key' => $provider->getKey(),
                    'audience' => $audience->Title,
                    'added' => $added,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ]
            ));
        }
    }

    private function audienceFor(AudienceSourceProvider $provider): NewsletterAudience
    {
        return NewsletterAudience::getOrCreateBySourceKey($provider->getKey(), $provider->getTitle());
    }

    /**
     * @param array<string, mixed> $row
     * @return string one of 'added', 'updated', 'skipped'
     */
    private function upsert(NewsletterAudience $audience, array $row): string
    {
        $email = trim((string) ($row['Email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'skipped';
        }

        // Explicit non-consent means do not enrol.
        if (array_key_exists('Consent', $row) && !$row['Consent']) {
            return 'skipped';
        }

        $subscriber = NewsletterSubscriber::get()->filter('Email', $email)->first();
        $isNew = false;

        if (!$subscriber) {
            $subscriber = NewsletterSubscriber::create();
            $subscriber->Email = $email;
            $isNew = true;
        }

        if (isset($row['FirstName'])) {
            $subscriber->FirstName = (string) $row['FirstName'];
        }
        if (isset($row['Surname'])) {
            $subscriber->Surname = (string) $row['Surname'];
        }
        if (isset($row['MergeData']) && is_array($row['MergeData'])) {
            $subscriber->setMergeArray($row['MergeData']);
        }

        // Optional anchor record (e.g. the Member) that computed merge fields
        // traverse. Stored as the polymorphic Anchor has_one.
        if (($row['Anchor'] ?? null) instanceof DataObject && $row['Anchor']->exists()) {
            $subscriber->AnchorClass = $row['Anchor']->ClassName;
            $subscriber->AnchorID = (int) $row['Anchor']->ID;
        }

        // Never resurrect a suppressed (unsubscribed/bounced) subscriber.
        $subscriber->write();
        $audience->Subscribers()->add($subscriber);

        return $isNew ? 'added' : 'updated';
    }
}
