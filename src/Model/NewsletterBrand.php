<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Global, CMS-editable styling tokens for newsletters (the MailChimp-style
 * "theme"): font, colours, button shape, framing and an optional header logo.
 *
 * Blocks inherit these unless they set their own override (blank = inherit), so
 * brand changes flow through every issue. The module ships generic defaults; a
 * host project overrides NewsletterBrand.defaults in YAML for its own identity.
 */
class NewsletterBrand extends DataObject
{
    use NewsletterPermissions;

    private static string $table_name = 'Newsletter_Brand';

    private static string $singular_name = 'Brand / theme';

    private static string $plural_name = 'Brands / themes';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'FontFamily' => 'Varchar(255)',
        // Google fonts (curated). Empty = use the FontFamily fallback stack only.
        'HeadingFont' => 'Varchar(100)',
        'BodyFont' => 'Varchar(100)',
        // Per-element type scale (px).
        'H1Size' => 'Int',
        'H2Size' => 'Int',
        'H3Size' => 'Int',
        'H4Size' => 'Int',
        'H5Size' => 'Int',
        'H6Size' => 'Int',
        'ParagraphSize' => 'Int',
        'PrimaryColor' => 'Varchar(7)',
        'LinkColor' => 'Varchar(7)',
        'HeadingColor' => 'Varchar(7)',
        'BodyTextColor' => 'Varchar(7)',
        'BodyBackground' => 'Varchar(7)',
        'ContentBackground' => 'Varchar(7)',
        'ContentWidth' => 'Int',
        'ButtonColor' => 'Varchar(7)',
        'ButtonTextColor' => 'Varchar(7)',
        'ButtonRadius' => 'Int',
        'ButtonPaddingY' => 'Int',
        'ButtonPaddingX' => 'Int',
        'DividerColor' => 'Varchar(7)',
        'FooterTextColor' => 'Varchar(7)',
    ];

    private static array $has_one = [
        'LogoImage' => Image::class,
    ];

    private static array $owns = [
        'LogoImage',
    ];

    /**
     * Curated, email-friendly Google fonts offered in the dropdowns. Extend via
     * config (NewsletterBrand.google_fonts). Keyed by family name.
     *
     * @config
     */
    private static array $google_fonts = [
        'Open Sans' => 'Open Sans',
        'Roboto' => 'Roboto',
        'Lato' => 'Lato',
        'Montserrat' => 'Montserrat',
        'Poppins' => 'Poppins',
        'Source Sans 3' => 'Source Sans 3',
        'Raleway' => 'Raleway',
        'Nunito' => 'Nunito',
        'Work Sans' => 'Work Sans',
        'Inter' => 'Inter',
        'Mulish' => 'Mulish',
        'Rubik' => 'Rubik',
        'Karla' => 'Karla',
        'DM Sans' => 'DM Sans',
        'Noto Sans' => 'Noto Sans',
        'PT Sans' => 'PT Sans',
        'Oswald' => 'Oswald',
        'Merriweather' => 'Merriweather',
        'Lora' => 'Lora',
        'Playfair Display' => 'Playfair Display',
    ];

    // Generic defaults — a host project overrides these via YAML for its brand.
    private static array $defaults = [
        'Title' => 'Default',
        'FontFamily' => 'Arial, Helvetica, sans-serif',
        'H1Size' => 32,
        'H2Size' => 26,
        'H3Size' => 22,
        'H4Size' => 18,
        'H5Size' => 16,
        'H6Size' => 14,
        'ParagraphSize' => 16,
        'PrimaryColor' => '#1a73e8',
        'LinkColor' => '#1a73e8',
        'HeadingColor' => '#111111',
        'BodyTextColor' => '#333333',
        'BodyBackground' => '#f4f4f4',
        'ContentBackground' => '#ffffff',
        'ContentWidth' => 600,
        'ButtonColor' => '#1a73e8',
        'ButtonTextColor' => '#ffffff',
        'ButtonRadius' => 4,
        'ButtonPaddingY' => 12,
        'ButtonPaddingX' => 24,
        'DividerColor' => '#dddddd',
        'FooterTextColor' => '#888888',
    ];

    /**
     * The active brand — the single configured record, or an unsaved instance
     * carrying defaults so rendering is never null.
     */
    public static function current(): self
    {
        return self::get()->first() ?: self::create();
    }

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        if (!self::get()->exists()) {
            $brand = self::create();
            $brand->write();
            DB::alteration_message(_t(__CLASS__ . '.DEFAULT_BRAND_CREATED', 'Default NewsletterBrand created'), 'created');
        }
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('LogoImageID');

        $color = static function (string $name, string $title): HexColorField {
            return HexColorField::create($name, $title);
        };

        $fields->removeByName([
            'FontFamily', 'HeadingFont', 'BodyFont',
            'H1Size', 'H2Size', 'H3Size', 'H4Size', 'H5Size', 'H6Size', 'ParagraphSize',
            'PrimaryColor', 'LinkColor', 'HeadingColor', 'BodyTextColor',
            'BodyBackground', 'ContentBackground', 'ContentWidth',
            'ButtonColor', 'ButtonTextColor', 'ButtonRadius', 'ButtonPaddingY', 'ButtonPaddingX',
            'DividerColor', 'FooterTextColor',
        ]);

        $googleFonts = ['' => _t(__CLASS__ . '.SYSTEM_DEFAULT', 'System default')]
            + (array) $this->config()->get('google_fonts');

        $fields->addFieldsToTab('Root.Typography', [
            DropdownField::create('HeadingFont', _t(__CLASS__ . '.HEADING_FONT_GOOGLE', 'Heading font (Google)'), $googleFonts)
                ->setDescription(_t(
                    __CLASS__ . '.HEADING_FONT_GOOGLE_DESCRIPTION',
                    'Applied to H1-H6. Web fonts render in the preview / view-online and some email clients; others fall back to the stack below.'
                )),
            DropdownField::create('BodyFont', _t(__CLASS__ . '.BODY_FONT_GOOGLE', 'Body font (Google)'), $googleFonts)
                ->setDescription(_t(__CLASS__ . '.BODY_FONT_GOOGLE_DESCRIPTION', 'Applied to body text and paragraphs.')),
            TextField::create('FontFamily', _t(__CLASS__ . '.FALLBACK_FONT_STACK', 'Fallback font stack (CSS)'))
                ->setDescription(_t(
                    __CLASS__ . '.FALLBACK_FONT_STACK_DESCRIPTION',
                    'Used where the Google font is unsupported, e.g. Arial, Helvetica, sans-serif'
                )),
            HeaderField::create('TypeScaleHeader', _t(__CLASS__ . '.TYPE_SCALE', 'Type scale (px)')),
            NumericField::create('H1Size', _t(__CLASS__ . '.H1', 'H1')),
            NumericField::create('H2Size', _t(__CLASS__ . '.H2', 'H2')),
            NumericField::create('H3Size', _t(__CLASS__ . '.H3', 'H3')),
            NumericField::create('H4Size', _t(__CLASS__ . '.H4', 'H4')),
            NumericField::create('H5Size', _t(__CLASS__ . '.H5', 'H5')),
            NumericField::create('H6Size', _t(__CLASS__ . '.H6', 'H6')),
            NumericField::create('ParagraphSize', _t(__CLASS__ . '.PARAGRAPH', 'Paragraph')),
        ]);

        $fields->addFieldsToTab('Root.Colours', [
            $color('PrimaryColor', _t(__CLASS__ . '.PRIMARY', 'Primary')),
            $color('LinkColor', _t(__CLASS__ . '.LINKS', 'Links')),
            $color('HeadingColor', _t(__CLASS__ . '.HEADINGS', 'Headings')),
            $color('BodyTextColor', _t(__CLASS__ . '.BODY_TEXT', 'Body text')),
            $color('BodyBackground', _t(__CLASS__ . '.PAGE_BACKGROUND', 'Page background')),
            $color('ContentBackground', _t(__CLASS__ . '.CONTENT_BACKGROUND', 'Content background')),
            $color('DividerColor', _t(__CLASS__ . '.DIVIDER_LINE', 'Divider line')),
            $color('FooterTextColor', _t(__CLASS__ . '.FOOTER_TEXT', 'Footer text')),
        ]);

        $fields->addFieldsToTab('Root.Buttons', [
            $color('ButtonColor', _t(__CLASS__ . '.BUTTON_BACKGROUND', 'Button background')),
            $color('ButtonTextColor', _t(__CLASS__ . '.BUTTON_TEXT', 'Button text')),
            NumericField::create('ButtonRadius', _t(__CLASS__ . '.CORNER_RADIUS', 'Corner radius (px)')),
            NumericField::create('ButtonPaddingY', _t(__CLASS__ . '.PADDING_VERTICAL', 'Padding - vertical (px)')),
            NumericField::create('ButtonPaddingX', _t(__CLASS__ . '.PADDING_HORIZONTAL', 'Padding - horizontal (px)')),
        ]);

        $fields->addFieldsToTab('Root.Layout', [
            NumericField::create('ContentWidth', _t(__CLASS__ . '.CONTENT_WIDTH', 'Content width (px)'))
                ->setDescription(_t(__CLASS__ . '.CONTENT_WIDTH_DESCRIPTION', 'Email body width, typically 600.')),
            UploadField::create('LogoImage', _t(__CLASS__ . '.HEADER_LOGO', 'Header logo'))->setFolderName('newsletter'),
        ]);

        return $fields;
    }

    /**
     * The plain fallback CSS stack (no Google font).
     */
    public function FontStack(): string
    {
        return $this->FontFamily ?: 'Arial, Helvetica, sans-serif';
    }

    /**
     * Body font stack — Google body font (if set) ahead of the fallback stack.
     */
    public function BodyFontStack(): string
    {
        return $this->BodyFont ? "'" . $this->BodyFont . "', " . $this->FontStack() : $this->FontStack();
    }

    /**
     * Heading font stack — Google heading font (if set), else the body stack.
     */
    public function HeadingFontStack(): string
    {
        return $this->HeadingFont ? "'" . $this->HeadingFont . "', " . $this->FontStack() : $this->BodyFontStack();
    }

    /**
     * @return array<int, int> Type-scale map: h1..h6 sizes plus paragraph (index 0).
     */
    public function headingSizes(): array
    {
        return [
            0 => (int) $this->ParagraphSize ?: 16,
            1 => (int) $this->H1Size ?: 32,
            2 => (int) $this->H2Size ?: 26,
            3 => (int) $this->H3Size ?: 22,
            4 => (int) $this->H4Size ?: 18,
            5 => (int) $this->H5Size ?: 16,
            6 => (int) $this->H6Size ?: 14,
        ];
    }

    /**
     * `@import` line loading the selected Google fonts, or '' if none. Stays in
     * the email's <style> (Emogrifier keeps un-inlinable at-rules) for clients
     * that support web fonts.
     */
    public function GoogleFontImport(): string
    {
        $families = [];
        foreach (array_unique(array_filter([$this->HeadingFont, $this->BodyFont])) as $font) {
            $families[] = 'family=' . str_replace(' ', '+', $font) . ':wght@400;700';
        }

        if (!$families) {
            return '';
        }

        return "@import url('https://fonts.googleapis.com/css2?" . implode('&', $families) . "&display=swap');";
    }
}
