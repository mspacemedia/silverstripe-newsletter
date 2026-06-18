<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use DNADesign\Elemental\Models\ElementalArea;
use MSpaceMedia\Newsletter\Elements\ImageBlock;
use MSpaceMedia\Newsletter\Elements\TextBlock;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class RenderServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    /**
     * @param array<int, \DNADesign\Elemental\Models\BaseElement> $blocks
     */
    private function issueWith(array $blocks): NewsletterIssue
    {
        $issue = NewsletterIssue::create();
        $issue->Title = 'Test';
        $issue->Subject = 'Subject';
        $issue->write();

        $area = ElementalArea::create();
        $area->write();
        $issue->ElementalAreaID = $area->ID;
        $issue->write();

        foreach ($blocks as $block) {
            $block->ParentID = $area->ID;
            $block->write();
        }

        return $issue;
    }

    public function testRendersBlockContentInsideWrapper(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p>Hello world</p>';
        $issue = $this->issueWith([$text]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('Hello world', $html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testMergeTagsResolvedForRecipient(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p>Hi *|FNAME|*</p>';
        $issue = $this->issueWith([$text]);

        $sub = NewsletterSubscriber::create();
        $sub->Email = 'jane@example.com';
        $sub->FirstName = 'Jane';
        $sub->write();

        $html = NewsletterRenderService::create()->renderEmail($issue, $sub);

        $this->assertStringContainsString('Hi Jane', $html);
        $this->assertStringNotContainsString('*|FNAME|*', $html);
    }

    public function testTrackingTokenAddsPixelAndRewritesLinks(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p><a href="https://example.com/x">link</a></p>';
        $issue = $this->issueWith([$text]);

        $html = NewsletterRenderService::create()->renderEmail($issue, null, 'tok123');

        $this->assertStringContainsString('newsletter/open/tok123', $html);
        $this->assertStringContainsString('newsletter/click/tok123', $html);
    }

    public function testEdgeToEdgeImageHasZeroPadding(): void
    {
        $image = ImageBlock::create();
        $image->FullWidth = true;
        $issue = $this->issueWith([$image]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('padding:0', $html);
    }

    public function testTinyMceAlignmentClassesAreInlined(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p class="text-center">a</p><p class="text-justify">b</p>';
        $issue = $this->issueWith([$text]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('text-align:justify', $html);
    }
}
