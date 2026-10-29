<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use DNADesign\Elemental\Models\ElementalArea;
use MSpaceMedia\Newsletter\Elements\ImageBlock;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * Images in a saved-but-unpublished issue are draft files whose URLs only work
 * for CMS users; sending must publish them first.
 */
class EmailAssetsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function issueWithDraftImage(): array
    {
        $issue = NewsletterIssue::create(['Title' => 'Assets', 'Subject' => 'Subject']);
        $issue->write();
        $area = ElementalArea::create();
        $area->write();
        $issue->ElementalAreaID = $area->ID;
        $issue->write();

        $image = Image::create();
        // A 1x1 PNG.
        $image->setFromString(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
            'Uploads/newsletter-asset-test.png'
        );
        $image->write();

        $block = ImageBlock::create(['ParentID' => $area->ID, 'ImageID' => $image->ID]);
        $block->write();

        return [$issue, $image];
    }

    public function testPublishEmailAssetsPublishesDraftImages(): void
    {
        [$issue, $image] = $this->issueWithDraftImage();
        $this->assertFalse($image->isPublished(), 'uploads start as drafts');

        $this->assertSame(1, $issue->publishEmailAssets());

        $this->assertTrue(Image::get()->byID($image->ID)->isPublished());
        $this->assertSame(0, $issue->publishEmailAssets(), 'already-published files are left alone');
    }

    public function testLockForSendPublishesImages(): void
    {
        [$issue, $image] = $this->issueWithDraftImage();

        $issue->lockForSend();

        $this->assertTrue(Image::get()->byID($image->ID)->isPublished());
    }
}
