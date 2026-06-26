<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\NewsletterSegmentService;
use MSpaceMedia\Newsletter\Tests\Stub\TestDonation;
use MSpaceMedia\Newsletter\Tests\Stub\TestDonor;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers materialising a boolean expression into segment-audience membership:
 * the batched fast path agreeing with row-by-row, build() syncing membership,
 * base-audience scoping, and exclusion of unanchored subscribers.
 */
class SegmentServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestDonor::class,
        TestDonation::class,
    ];

    /** @var array<string, NewsletterSubscriber> */
    private array $subs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(NewsletterSubscriber::class, 'anchor_class', TestDonor::class);
        Config::modify()->set(\MSpaceMedia\Newsletter\Service\MergeFieldService::class, 'allowlist', [
            TestDonor::class => ['relations' => ['Donations'], 'fields' => ['FirstName']],
            TestDonation::class => ['fields' => ['Amount', 'Status']],
        ]);

        // Donors with 3, 2, 1 and 0 donations; plus a subscriber with no anchor.
        $this->subs['A'] = $this->makeSubscriber('a@example.com', 3);
        $this->subs['B'] = $this->makeSubscriber('b@example.com', 2);
        $this->subs['C'] = $this->makeSubscriber('c@example.com', 1);
        $this->subs['D'] = $this->makeSubscriber('d@example.com', 0);
        $this->subs['E'] = $this->makeSubscriber('e@example.com', null); // no anchor
    }

    private function makeSubscriber(string $email, ?int $donationCount): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::create();
        $subscriber->Email = $email;
        $subscriber->FirstName = strtoupper($email[0]);

        if ($donationCount !== null) {
            $donor = TestDonor::create();
            $donor->FirstName = strtoupper($email[0]);
            $donor->write();

            for ($i = 0; $i < $donationCount; $i++) {
                $donation = TestDonation::create();
                $donation->Amount = 100 * ($i + 1);
                $donation->Status = 'Paid';
                $donation->DonorID = $donor->ID;
                $donation->write();
            }

            $subscriber->AnchorClass = TestDonor::class;
            $subscriber->AnchorID = $donor->ID;
        }

        $subscriber->write();

        return $subscriber;
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, int>
     */
    private function ids(array $keys): array
    {
        $ids = array_map(fn (string $k): int => (int) $this->subs[$k]->ID, $keys);
        sort($ids);

        return $ids;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    public function testFastPathMatchesCountThreshold(): void
    {
        $service = NewsletterSegmentService::create();
        $matched = $service->matchingIDs('Donations.Count >= 2', $service->pool());

        $this->assertSame($this->ids(['A', 'B']), $this->sorted($matched));
    }

    public function testFastPathAndRowByRowAgree(): void
    {
        $service = NewsletterSegmentService::create();

        // "Donations.Count >= 2" uses the grouped-query fast path; the Where()
        // form is row-by-row but semantically identical (all amounts > 0).
        $fast = $service->matchingIDs('Donations.Count >= 2', $service->pool());
        $rowByRow = $service->matchingIDs('Donations.Where(Amount > 0).Count >= 2', $service->pool());

        $this->assertSame($this->sorted($fast), $this->sorted($rowByRow));
        $this->assertSame($this->ids(['A', 'B']), $this->sorted($fast));
    }

    public function testSumAggregateFastPath(): void
    {
        $service = NewsletterSegmentService::create();
        // A: 100+200+300=600, B: 100+200=300, C: 100, D/E: 0.
        $matched = $service->matchingIDs('Donations.Sum(Amount) >= 300', $service->pool());

        $this->assertSame($this->ids(['A', 'B']), $this->sorted($matched));
    }

    public function testBuildSyncsMembership(): void
    {
        $segment = NewsletterAudience::create();
        $segment->Title = 'Two or more donations';
        $segment->SegmentExpression = 'Donations.Count >= 2';
        $segment->write();

        $result = NewsletterSegmentService::create()->build($segment);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame($this->ids(['A', 'B']), $this->sorted($segment->Subscribers()->column('ID')));
    }

    public function testRebuildAddsAndRemoves(): void
    {
        $segment = NewsletterAudience::create();
        $segment->Title = 'One or more';
        $segment->SegmentExpression = 'Donations.Count >= 1';
        $segment->write();

        $service = NewsletterSegmentService::create();
        $service->build($segment);
        $this->assertSame($this->ids(['A', 'B', 'C']), $this->sorted($segment->Subscribers()->column('ID')));

        // Tighten the threshold; C should drop out on rebuild.
        $segment->SegmentExpression = 'Donations.Count >= 2';
        $segment->write();
        $result = $service->build($segment);

        $this->assertSame(1, $result['removed']);
        $this->assertSame($this->ids(['A', 'B']), $this->sorted($segment->Subscribers()->column('ID')));
    }

    public function testBaseAudienceScopesThePool(): void
    {
        $base = NewsletterAudience::create();
        $base->Title = 'Base';
        $base->write();
        $base->Subscribers()->add($this->subs['A']); // only A is in the base pool

        $segment = NewsletterAudience::create();
        $segment->Title = 'Within base';
        $segment->SegmentExpression = 'Donations.Count >= 1';
        $segment->BaseAudienceID = $base->ID;
        $segment->write();

        NewsletterSegmentService::create()->build($segment);

        // B and C also have ≥1 donation but are outside the base audience.
        $this->assertSame($this->ids(['A']), $this->sorted($segment->Subscribers()->column('ID')));
    }

    public function testUnanchoredSubscriberIsExcluded(): void
    {
        $service = NewsletterSegmentService::create();
        $matched = $service->matchingIDs('Donations.Count >= 1', $service->pool());

        $this->assertNotContains((int) $this->subs['E']->ID, $this->sorted($matched));
    }
}
