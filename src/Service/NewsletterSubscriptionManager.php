<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use SilverStripe\Core\Injector\Injectable;

/**
 * Reusable, project-agnostic API for managing subscription state. Keyed purely by
 * email + audience key — it has no knowledge of Members, Orders or Groups, so the
 * host project owns the mapping from its own models to these calls.
 *
 * NewsletterSubscriber is the single source of truth: subscribe/unsubscribe here,
 * and let project hooks mirror the result back onto project models.
 */
class NewsletterSubscriptionManager
{
    use Injectable;

    /**
     * Subscribe an email to an audience (creating both the subscriber and the
     * audience if needed). An explicit opt-in re-activates an Unsubscribed record
     * but never resurrects a hard Bounce.
     *
     * @param array{FirstName?:string, Surname?:string, MergeData?:array<string,string>} $data
     */
    public function subscribe(string $email, string $audienceKey, array $data = []): ?NewsletterSubscriber
    {
        $email = trim($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $subscriber = $this->findOrCreate($email);

        if ($subscriber->Status !== 'Bounced') {
            $subscriber->Status = 'Active';
        }

        if (!empty($data['FirstName'])) {
            $subscriber->FirstName = (string) $data['FirstName'];
        }
        if (!empty($data['Surname'])) {
            $subscriber->Surname = (string) $data['Surname'];
        }
        if (!empty($data['MergeData']) && is_array($data['MergeData'])) {
            $subscriber->setMergeArray($data['MergeData']);
        }

        $subscriber->write();

        NewsletterAudience::getOrCreateBySourceKey($audienceKey)->Subscribers()->add($subscriber);

        return $subscriber;
    }

    /**
     * Globally suppress an email (account opt-out, unsubscribe link, etc.) and
     * notify listeners via the onNewsletterUnsubscribe extension hook.
     */
    public function unsubscribe(string $email): void
    {
        $subscriber = NewsletterSubscriber::get()->filter('Email', trim($email))->first();

        if (!$subscriber) {
            return;
        }

        if ($subscriber->Status === 'Active') {
            $subscriber->Status = 'Unsubscribed';
            $subscriber->write();
        }

        $subscriber->invokeWithExtensions('onNewsletterUnsubscribe');
    }

    /**
     * Globally suppress an email because it hard-bounced. Unlike unsubscribe this
     * is a deliverability state, not a consent change, so no member-pref hook fires.
     */
    public function bounce(string $email): void
    {
        $subscriber = NewsletterSubscriber::get()->filter('Email', trim($email))->first();

        if ($subscriber && $subscriber->Status !== 'Bounced') {
            $subscriber->Status = 'Bounced';
            $subscriber->write();
        }
    }

    public function isSubscribed(string $email): bool
    {
        $subscriber = NewsletterSubscriber::get()->filter('Email', trim($email))->first();

        return $subscriber !== null && $subscriber->Status === 'Active';
    }

    private function findOrCreate(string $email): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::get()->filter('Email', $email)->first();

        if (!$subscriber) {
            $subscriber = NewsletterSubscriber::create();
            $subscriber->Email = $email;
        }

        return $subscriber;
    }
}
