<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;

/**
 * A card: image, heading, body text and an optional call-to-action button.
 */
class ImageCardBlock extends NewsletterBlockElemental
{
    use ScaledImageTrait;

    private static string $table_name = 'Newsletter_ImageCardBlock';

    private static string $icon = 'font-icon-block-promo';

    private static string $singular_name = 'Image card';

    private static string $plural_name = 'Image cards';

    private static array $db = [
        'Heading' => 'Varchar(255)',
        'Content' => 'HTMLText',
        'ButtonLabel' => 'Varchar(100)',
        'ButtonURL' => 'Varchar(2083)',
        'ButtonColor' => 'Varchar(7)',
        'ButtonRadius' => 'Int',
        'MaxWidth' => 'Int',
    ];

    private static array $has_one = [
        'Image' => Image::class,
    ];

    private static array $owns = [
        'Image',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Image card');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('ButtonColor', HexColorField::create(
            'ButtonColor',
            _t(__CLASS__ . '.BUTTON_COLOUR', 'Button colour')
        )->setDescription(_t(
            __CLASS__ . '.BUTTON_COLOUR_DESCRIPTION',
            'Leave blank to inherit the brand default.'
        )));

        return $fields;
    }

    public function ButtonStyle(): string
    {
        $brand = $this->getRenderBrand();
        $bg = $this->safeColor($this->ButtonColor) ?: ($brand?->ButtonColor ?: '#1a73e8');
        $color = $brand?->ButtonTextColor ?: '#ffffff';
        $radius = (int) $this->ButtonRadius ?: ($brand ? (int) $brand->ButtonRadius : 4);

        return implode(';', [
            'background-color:' . $bg,
            'color:' . $color,
            'display:inline-block',
            'padding:10px 20px',
            'border-radius:' . $radius . 'px',
            'text-decoration:none',
            'font-weight:bold',
        ]);
    }
}
