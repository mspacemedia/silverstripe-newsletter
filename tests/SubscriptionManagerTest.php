<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\NewsletterSubscriptionManager;
use SilverStripe\Dev\SapphireTest;

class SubscriptionManagerTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function manager(): NewsletterSubscriptionManager
    {
        return NewsletterSubscriptionManager::create();
    }

    public function testSubscribeCreatesActiveAndAttachesAudience(): void
    {
        $sub = $this->manager()->subscribe('a@example.com', 'retail', ['FirstName' => 'Al']);

        $this->assertNotNull($sub);
        $this->assertSame('Active', $sub->Status);
        $this->assertSame('Al', $sub->FirstName);
        $this->assertTrue($this->manager()->isSubscribed('a@example.com'));

        $audience = NewsletterAudience::get()->filter('SourceKey', 'retail')->first();
        $this->assertNotNull($audience);
        $this->assertSame(1, $audience->Subscribers()->count());
    }

    public function testInvalidEmailIsIgnored(): void
    {
        $this->assertNull($this->manager()->subscribe('not-an-email', 'retail'));
    }

    public function testUnsubscribeSuppresses(): void
    {
        $this->manager()->subscribe('b@example.com', 'retail');
        $this->manager()->unsubscribe('b@example.com');

        $this->assertFalse($this->manager()->isSubscribed('b@example.com'));
        $sub = NewsletterSubscriber::get()->filter('Email', 'b@example.com')->first();
        $this->assertSame('Unsubscribed', $sub->Status);
    }

    public function testBounceSuppresses(): void
    {
        $this->manager()->subscribe('c@example.com', 'retail');
        $this->manager()->bounce('c@example.com');

        $sub = NewsletterSubscriber::get()->filter('Email', 'c@example.com')->first();
        $this->assertSame('Bounced', $sub->Status);
        $this->assertFalse($this->manager()->isSubscribed('c@example.com'));
    }

    public function testSubscribeReactivatesUnsubscribedButNeverBounced(): void
    {
        // Unsubscribed → re-subscribing re-activates (explicit new opt-in).
        $this->manager()->subscribe('d@example.com', 'retail');
        $this->manager()->unsubscribe('d@example.com');
        $this->manager()->subscribe('d@example.com', 'retail');
        $this->assertTrue($this->manager()->isSubscribed('d@example.com'));

        // Bounced → never resurrected by a subscribe.
        $this->manager()->bounce('d@example.com');
        $this->manager()->subscribe('d@example.com', 'retail');
        $sub = NewsletterSubscriber::get()->filter('Email', 'd@example.com')->first();
        $this->assertSame('Bounced', $sub->Status);
    }

    public function testDedupedByEmailAcrossAudiences(): void
    {
        $this->manager()->subscribe('e@example.com', 'retail');
        $this->manager()->subscribe('e@example.com', 'charity');

        $this->assertSame(1, NewsletterSubscriber::get()->filter('Email', 'e@example.com')->count());
        $this->assertSame(2, NewsletterSubscriber::get()->filter('Email', 'e@example.com')->first()->Audiences()->count());
    }
}
