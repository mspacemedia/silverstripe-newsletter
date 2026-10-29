<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\FieldType\DBHTMLText;

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
        'Variant' => "Enum('solid,outline','solid')",
        'FullWidthButton' => 'Boolean',
    ];

    private static array $defaults = [
        'PaddingTop' => 16,
        'PaddingRight' => 20,
        'PaddingBottom' => 16,
        'PaddingLeft' => 20,
        'Alignment' => 'center',
        'Variant' => 'solid',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Button');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $description = _t(__CLASS__ . '.INHERIT_BRAND_DEFAULT', 'Leave blank to inherit the brand default.');
        $fields->replaceField('ButtonColor', HexColorField::create(
            'ButtonColor',
            _t(__CLASS__ . '.BUTTON_COLOUR', 'Button colour')
        )->setDescription($description));
        $fields->replaceField('ButtonTextColor', HexColorField::create(
            'ButtonTextColor',
            _t(__CLASS__ . '.BUTTON_TEXT_COLOUR', 'Button text colour')
        )->setDescription($description));
        $fields->replaceField('Variant', DropdownField::create(
            'Variant',
            _t(__CLASS__ . '.VARIANT', 'Style'),
            [
                'solid' => _t(__CLASS__ . '.VARIANT_SOLID', 'Solid'),
                'outline' => _t(__CLASS__ . '.VARIANT_OUTLINE', 'Outline'),
            ]
        ));
        $fields->dataFieldByName('FullWidthButton')
            ?->setTitle(_t(__CLASS__ . '.FULL_WIDTH_BUTTON', 'Stretch button to full width'));

        return $fields;
    }

    /**
     * The complete Outlook-safe button markup.
     */
    public function RenderedButton(): DBHTMLText
    {
        return $this->renderButton((string) $this->ButtonURL, (string) $this->Label, [
            'bg' => $this->ButtonColor,
            'color' => $this->ButtonTextColor,
            'radius' => (int) $this->ButtonRadius,
            'outline' => $this->Variant === 'outline',
            'fullWidth' => (bool) $this->FullWidthButton,
        ]);
    }

    /**
     * Inline CSS for a plain <a> button. Kept for templates overridden in host
     * projects; the module's own template uses RenderedButton().
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
