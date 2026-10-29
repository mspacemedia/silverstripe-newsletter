<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use DNADesign\Elemental\Models\ElementalArea;
use MSpaceMedia\Newsletter\Elements\ButtonBlock;
use MSpaceMedia\Newsletter\Elements\FooterBlock;
use MSpaceMedia\Newsletter\Elements\HeadingBlock;
use MSpaceMedia\Newsletter\Elements\ImageBlock;
use MSpaceMedia\Newsletter\Elements\TextBlock;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
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

        // Emogrifier normalises inline declarations to "padding: 0;".
        $this->assertMatchesRegularExpression('/<td[^>]*style="[^"]*[";\s]padding:\s*0;/', $html);
    }

    public function testBrandTypographyIsInlined(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p>Inline me</p>';
        $issue = $this->issueWith([$text]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        // Clients that strip <style> rely on these inline declarations.
        $this->assertMatchesRegularExpression('/<p style="[^"]*font-size:[^"]*">Inline me/', $html);
    }

    public function testPreheaderSurvivesCssInlining(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p>Body</p>';
        $issue = $this->issueWith([$text]);
        $issue->PreheaderText = 'Inbox preview line';
        $issue->write();

        $html = NewsletterRenderService::create()->renderWeb($issue);

        // The hidden preheader uses display:none, which the pruner would drop.
        $this->assertStringContainsString('Inbox preview line', $html);
    }

    public function testPrimarySurfaceUsesBrandColourAndInverseText(): void
    {
        $brand = NewsletterBrand::create();
        $brand->PrimaryColor = '#123456';
        $brand->InverseTextColor = '#fefefe';
        $brand->write();

        $text = TextBlock::create();
        $text->Surface = 'primary';
        $text->Content = '<p>On brand <a href="https://example.com/">link</a></p>';
        $issue = $this->issueWith([$text]);
        $issue->BrandID = $brand->ID;
        $issue->write();

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertMatchesRegularExpression('/<td[^>]*background-color:\s*#123456/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*color:\s*#fefefe/', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*color:\s*#fefefe/', $html);
    }

    public function testButtonIsBulletproofAndOutlineVariantHasNoFill(): void
    {
        $solid = ButtonBlock::create();
        $solid->Label = 'Solid';
        $solid->ButtonURL = 'https://example.com/a';

        $outline = ButtonBlock::create();
        $outline->Label = 'Outline';
        $outline->ButtonURL = 'https://example.com/b';
        $outline->Variant = 'outline';
        $outline->ButtonColor = '#abcdef';

        $issue = $this->issueWith([$solid, $outline]);
        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('mso-padding-alt', $html);
        $this->assertMatchesRegularExpression('/<td[^>]*bgcolor="[^"]+"[^>]*>\s*<a[^>]*>Solid<\/a>/', $html);
        $this->assertDoesNotMatchRegularExpression('/bgcolor="#abcdef"/', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*border:\s*2px solid #abcdef[^>]*>Outline<\/a>/', $html);
    }

    public function testHeadingRendersKickerAndBrandWeight(): void
    {
        $brand = NewsletterBrand::create();
        $brand->HeadingFontWeight = 400;
        $brand->write();

        $heading = HeadingBlock::create();
        $heading->Kicker = 'October update';
        $heading->Content = 'Title';
        $heading->Level = 'h1';
        $issue = $this->issueWith([$heading]);
        $issue->BrandID = $brand->ID;
        $issue->write();

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertStringContainsString('October update', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*font-weight:\s*400/', $html);
    }

    public function testFooterParagraphsUseSmallPrint(): void
    {
        $footer = FooterBlock::create();
        $footer->Content = '<p>Registered address</p>';
        $issue = $this->issueWith([$footer]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        $this->assertMatchesRegularExpression('/<p[^>]*font-size:\s*12px[^>]*>Registered address/', $html);
    }

    public function testTinyMceAlignmentClassesAreInlined(): void
    {
        $text = TextBlock::create();
        $text->Content = '<p class="text-center">a</p><p class="text-justify">b</p>';
        $issue = $this->issueWith([$text]);

        $html = NewsletterRenderService::create()->renderWeb($issue);

        // Assert on the elements: the classes also appear as rules in <style>.
        $this->assertMatchesRegularExpression('/<p style="[^"]*text-align:\s*center[^"]*">a</', $html);
        $this->assertMatchesRegularExpression('/<p style="[^"]*text-align:\s*justify[^"]*">b</', $html);
    }
}
