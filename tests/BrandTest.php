<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use SilverStripe\Dev\SapphireTest;

class BrandTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testFontStacks(): void
    {
        $brand = NewsletterBrand::create();
        $brand->FontFamily = 'Arial, sans-serif';

        $this->assertSame('Arial, sans-serif', $brand->FontStack());

        $brand->BodyFont = 'Inter';
        $this->assertSame("'Inter', Arial, sans-serif", $brand->BodyFontStack());

        $brand->HeadingFont = 'Playfair Display';
        $this->assertSame("'Playfair Display', Arial, sans-serif", $brand->HeadingFontStack());
    }

    public function testHeadingFontFallsBackToBody(): void
    {
        $brand = NewsletterBrand::create();
        $brand->FontFamily = 'Arial, sans-serif';
        $brand->BodyFont = 'Inter';

        // No heading font set → heading stack equals the body stack.
        $this->assertSame($brand->BodyFontStack(), $brand->HeadingFontStack());
    }

    public function testGoogleFontImport(): void
    {
        $brand = NewsletterBrand::create();
        $this->assertSame('', $brand->GoogleFontImport());

        $brand->HeadingFont = 'Playfair Display';
        $brand->BodyFont = 'Inter';
        $import = $brand->GoogleFontImport();

        $this->assertStringStartsWith('@import', $import);
        $this->assertStringContainsString('family=Playfair+Display', $import);
        $this->assertStringContainsString('family=Inter', $import);
    }

    public function testHeadingSizes(): void
    {
        $brand = NewsletterBrand::create();
        $brand->H1Size = 40;
        $brand->ParagraphSize = 18;

        $sizes = $brand->headingSizes();
        $this->assertSame(40, $sizes[1]);
        $this->assertSame(18, $sizes[0]);
        // Unset H2 falls back to the sensible default.
        $this->assertSame(26, $sizes[2]);
    }

    public function testHexColorFieldRoundTrip(): void
    {
        $field = HexColorField::create('Colour');

        $field->setValue('#9c1d44');
        $this->assertSame('9c1d44', $field->Value(), 'picker holds bare hex');
        $this->assertSame('#9c1d44', $field->dataValue(), 're-adds # on save');

        $field->setValue('');
        $this->assertSame('', $field->dataValue(), 'empty stays empty (inherit)');
    }
}
