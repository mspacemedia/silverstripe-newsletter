<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\Forms\FieldList;

class ButtonBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_ButtonBlock';

    private static string $icon = 'font-icon-button';

    private static string $singular_name = 'Button';

    private static string $plural_name = 'Buttons';

    private static array $db = [
        'Label' => 'Varchar(100)',
        'ButtonURL' => 'Varchar(2083)',
        'ButtonColor' => 'Varchar(7)',
        'ButtonTextColor' => 'Varchar(7)',
        'ButtonRadius' => 'Int',
    ];

    private static array $defaults = [
        'PaddingTop' => 16,
        'PaddingRight' => 20,
        'PaddingBottom' => 16,
        'PaddingLeft' => 20,
        'Alignment' => 'center',
    ];

    public function getType(): string
    {
        return 'Button';
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('ButtonColor', HexColorField::create('ButtonColor', 'Button colour')
            ->setDescription('Leave blank to inherit the brand default.'));
        $fields->replaceField('ButtonTextColor', HexColorField::create('ButtonTextColor', 'Button text colour')
            ->setDescription('Leave blank to inherit the brand default.'));

        return $fields;
    }

    /**
     * Inline CSS for the <a> button. Colour falls back to the brand when blank;
     * radius and padding come from the brand (global shape, MailChimp-style).
     */
    public function getButtonStyle(): string
    {
        $brand = $this->getRenderBrand();
        $bg = $this->safeColor($this->ButtonColor) ?: ($brand?->ButtonColor ?: '#1a73e8');
        $color = $this->safeColor($this->ButtonTextColor) ?: ($brand?->ButtonTextColor ?: '#ffffff');
        $radius = (int) $this->ButtonRadius ?: ($brand ? (int) $brand->ButtonRadius : 4);
        $py = $brand ? (int) $brand->ButtonPaddingY : 12;
        $px = $brand ? (int) $brand->ButtonPaddingX : 24;

        return implode(';', [
            'background-color:' . $bg,
            'color:' . $color,
            'display:inline-block',
            'padding:' . $py . 'px ' . $px . 'px',
            'border-radius:' . $radius . 'px',
            'text-decoration:none',
            'font-weight:bold',
        ]);
    }
}
