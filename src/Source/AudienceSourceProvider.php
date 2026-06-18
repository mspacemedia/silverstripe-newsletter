<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Source;

/**
 * Implemented by a host project to feed subscribers into an audience from its
 * own models. Providers are registered on NewsletterAudienceRefreshTask and
 * matched to a NewsletterAudience by getKey() == NewsletterAudience.SourceKey.
 *
 * getSubscribers() yields associative rows:
 *   [
 *     'Email'     => 'person@example.com', // required
 *     'FirstName' => 'Jane',               // optional
 *     'Surname'   => 'Doe',                // optional
 *     'MergeData' => ['CITY' => 'Leeds'],  // optional, arbitrary merge tags
 *     'Consent'   => true,                 // optional, default true; false = skip
 *   ]
 */
interface AudienceSourceProvider
{
    /**
     * Stable key matching the target audience's SourceKey.
     */
    public function getKey(): string;

    /**
     * Human-readable title, used when auto-creating the audience.
     */
    public function getTitle(): string;

    /**
     * @return iterable<array<string, mixed>>
     */
    public function getSubscribers(): iterable;
}
