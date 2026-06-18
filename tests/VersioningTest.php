<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use DNADesign\Elemental\Models\ElementalArea;
use MSpaceMedia\Newsletter\Elements\TextBlock;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class VersioningTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function draftIssue(): NewsletterIssue
    {
        $issue = NewsletterIssue::create();
        $issue->Title = 'Versioned';
        $issue->Subject = 'Subject';
        $issue->write();

        $area = ElementalArea::create();
        $area->write();
        $issue->ElementalAreaID = $area->ID;
        $issue->write();

        $text = TextBlock::create();
        $text->Content = '<p>Versioned body</p>';
        $text->ParentID = $area->ID;
        $text->write();

        return $issue;
    }

    public function testIssueIsVersioned(): void
    {
        $this->assertTrue(NewsletterIssue::create()->hasExtension(Versioned::class));
    }

    public function testDraftRendersBeforePublish(): void
    {
        $issue = $this->draftIssue();

        $html = NewsletterRenderService::create()->renderWeb($issue);
        $this->assertStringContainsString('Versioned body', $html);

        $live = Versioned::get_by_stage(NewsletterIssue::class, Versioned::LIVE)->byID($issue->ID);
        $this->assertNull($live, 'nothing on live until published');
    }

    public function testPublishCascadesToElements(): void
    {
        $issue = $this->draftIssue();
        $issue->publishRecursive();

        $live = Versioned::get_by_stage(NewsletterIssue::class, Versioned::LIVE)->byID($issue->ID);
        $this->assertNotNull($live);

        $liveHtml = Versioned::withVersionedMode(function () use ($issue) {
            Versioned::set_stage(Versioned::LIVE);
            return NewsletterRenderService::create()->renderWeb(
                NewsletterIssue::get()->byID($issue->ID)
            );
        });
        $this->assertStringContainsString('Versioned body', $liveHtml);
    }
}
