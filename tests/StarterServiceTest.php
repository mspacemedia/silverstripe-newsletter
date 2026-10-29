<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Elements\HeadingBlock;
use MSpaceMedia\Newsletter\Elements\TextBlock;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use MSpaceMedia\Newsletter\Service\NewsletterStarterService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class StarterServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);

        // A small, image-free starter so tests don't depend on the shipped set.
        Config::modify()->set(NewsletterStarterService::class, 'starters', [
            'test_starter' => [
                'title' => 'Test starter',
                'sort' => 1,
                'blocks' => [
                    ['type' => 'HeadingBlock', 'Content' => 'Starter heading', 'Kicker' => 'Kicker'],
                    ['type' => 'TextBlock', 'Content' => '<p>Starter text</p>', 'Surface' => 'primary'],
                    ['type' => 'NotARealBlock', 'Content' => 'skipped'],
                ],
            ],
            'disabled_starter' => [
                'title' => 'Disabled',
                'enabled' => false,
                'blocks' => [['type' => 'TextBlock']],
            ],
        ]);
    }

    private function newIssue(?string $startFrom = null): NewsletterIssue
    {
        $issue = NewsletterIssue::create();
        $issue->Title = 'Issue';
        $issue->Subject = 'Subject';
        $issue->setStartFrom($startFrom);
        $issue->write();

        return $issue;
    }

    public function testOptionsListEnabledStartersAndSavedTemplates(): void
    {
        $template = $this->newIssue();
        $template->IsTemplate = true;
        $template->write();

        $options = NewsletterStarterService::create()->options();

        $this->assertArrayHasKey('starter:test_starter', $options);
        $this->assertArrayNotHasKey('starter:disabled_starter', $options);
        $this->assertArrayHasKey('issue:' . $template->ID, $options);
    }

    public function testStartFromStarterBuildsBlocksInOrder(): void
    {
        $issue = $this->newIssue('starter:test_starter');
        $elements = $issue->ElementalArea()->Elements()->sort('Sort');

        // The unknown block type is skipped rather than failing the build.
        $this->assertCount(2, $elements);
        $this->assertInstanceOf(HeadingBlock::class, $elements->first());
        $this->assertSame('Starter heading', $elements->first()->Content);
        $this->assertInstanceOf(TextBlock::class, $elements->last());
        $this->assertSame('primary', $elements->last()->Surface);
    }

    public function testStartFromSavedTemplateCopiesBlocks(): void
    {
        $template = $this->newIssue('starter:test_starter');
        $template->IsTemplate = true;
        $template->write();

        $copy = $this->newIssue('issue:' . $template->ID);

        $this->assertCount(2, $copy->ElementalArea()->Elements());
        $this->assertNotSame($template->ElementalAreaID, $copy->ElementalAreaID);
        // The template keeps its own blocks.
        $this->assertCount(2, $template->ElementalArea()->Elements());
    }

    public function testNeverAppliesOverExistingBlocks(): void
    {
        $issue = $this->newIssue('starter:test_starter');

        $created = NewsletterStarterService::create()->apply($issue, 'starter:test_starter');

        $this->assertSame(0, $created);
        $this->assertCount(2, $issue->ElementalArea()->Elements());
    }

    public function testNonTemplateIssueCannotBeCopied(): void
    {
        $source = $this->newIssue('starter:test_starter');
        $copy = $this->newIssue('issue:' . $source->ID);

        $this->assertCount(0, $copy->ElementalArea()->Elements());
    }

    public function testStarterRendersWithBrandStyling(): void
    {
        $issue = $this->newIssue('starter:test_starter');

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('Starter heading', $html);
        $this->assertStringContainsString('KICKER', strtoupper($html));
        $this->assertStringContainsString('Starter text', $html);
    }

    public function testTemplatesHideTheLiveSendAction(): void
    {
        $issue = $this->newIssue();
        $issue->IsTemplate = true;
        $issue->write();

        $names = [];
        foreach ($issue->getCMSActions()->flattenFields() as $action) {
            if (method_exists($action, 'actionName')) {
                $names[] = $action->actionName();
            }
        }

        $this->assertContains('doSendTestNewsletter', $names);
        $this->assertNotContains('doSendNewsletter', $names);
    }
}
