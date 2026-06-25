<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Model\NewsletterMergeField;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\MergeExpression\ExpressionException;
use MSpaceMedia\Newsletter\Service\MergeFieldService;
use MSpaceMedia\Newsletter\Tests\Stub\TestDonation;
use MSpaceMedia\Newsletter\Tests\Stub\TestDonor;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * End-to-end coverage of the computed merge-field engine against a real anchor
 * record: relation aggregates, Where() filters, the {{ … }} / {{#if}} template
 * syntax, fallbacks when there is no anchor, and the allowlist security gate.
 */
class MergeFieldEngineTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestDonor::class,
        TestDonation::class,
    ];

    private TestDonor $donor;

    private NewsletterSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(NewsletterSubscriber::class, 'anchor_class', TestDonor::class);
        Config::modify()->set(MergeFieldService::class, 'currency_symbol', '£');
        Config::modify()->set(MergeFieldService::class, 'allowlist', [
            TestDonor::class => [
                'relations' => ['Donations'],
                'fields' => ['FirstName', 'Surname', 'Email'],
            ],
            TestDonation::class => [
                // Note: "Secret" is deliberately NOT exposed.
                'fields' => ['Amount', 'Status'],
            ],
        ]);

        $this->donor = TestDonor::create();
        $this->donor->FirstName = 'Jane';
        $this->donor->Surname = 'Doe';
        $this->donor->Email = 'jane@example.com';
        $this->donor->write();

        foreach ([[1000, 'Paid'], [330, 'Paid'], [50, 'Pending']] as [$amount, $status]) {
            $donation = TestDonation::create();
            $donation->Amount = $amount;
            $donation->Status = $status;
            $donation->DonorID = $this->donor->ID;
            $donation->Secret = 'hidden';
            $donation->write();
        }

        $this->subscriber = NewsletterSubscriber::create();
        $this->subscriber->FirstName = 'Jane';
        $this->subscriber->Surname = 'Doe';
        $this->subscriber->Email = 'jane@example.com';
        $this->subscriber->AnchorClass = TestDonor::class;
        $this->subscriber->AnchorID = $this->donor->ID;
        $this->subscriber->write();

        $this->defineField('ORDERCOUNT', 'Donations.Count');
        $this->defineField('TOTALDONATION', "Donations.Where(Status = 'Paid').Sum(Amount) | currency");
    }

    private function defineField(string $tag, string $expression): void
    {
        $field = NewsletterMergeField::create();
        $field->Tag = $tag;
        $field->Expression = $expression;
        $field->write();
    }

    private function render(string $html, ?NewsletterSubscriber $subscriber = null): string
    {
        return MergeFieldService::create()->render($html, $subscriber ?? $this->subscriber);
    }

    public function testCountAggregate(): void
    {
        $this->assertSame('You made 3 donations', $this->render('You made {{ ORDERCOUNT }} donations'));
    }

    public function testFilteredSumWithCurrency(): void
    {
        // 1000 + 330 Paid; the 50 Pending is filtered out.
        $this->assertSame('£1,330.00', $this->render('{{ TOTALDONATION }}'));
    }

    public function testInlineExpressionUsesSubscriberBuiltins(): void
    {
        $this->assertSame('Hi Jane Doe', $this->render('Hi {{ Concat(FirstName, " ", Surname) }}'));
    }

    public function testConditionalTrueBranch(): void
    {
        $this->assertSame(
            'thank you',
            $this->render('{{#if ORDERCOUNT}}thank you{{else}}no orders placed{{/if}}')
        );
    }

    public function testConditionalFallsBackWithoutAnchor(): void
    {
        $orphan = NewsletterSubscriber::create();
        $orphan->Email = 'no-anchor@example.com';
        $orphan->write();

        $this->assertSame(
            'no orders placed',
            $this->render('{{#if ORDERCOUNT}}thanks{{else}}no orders placed{{/if}}', $orphan)
        );
    }

    public function testDefinedFieldComposesIntoExpression(): void
    {
        // ORDERCOUNT (3) doubled via arithmetic on a defined field.
        $this->assertSame('6', $this->render('{{ ORDERCOUNT * 2 }}'));
    }

    public function testNonExposedFieldIsRejected(): void
    {
        $this->expectException(ExpressionException::class);

        MergeFieldService::create()->evaluate('Donations.Sum(Secret)', $this->donor);
    }

    public function testBrokenTagNeverLeaksIntoOutput(): void
    {
        // A reference to an unexposed field resolves to empty in rendered HTML
        // rather than surfacing the error or the raw tag.
        $this->assertSame('Value: ', $this->render('Value: {{ Donations.Sum(Secret) }}'));
    }
}
