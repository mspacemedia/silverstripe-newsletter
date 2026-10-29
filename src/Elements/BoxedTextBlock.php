<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\Forms\FieldList;

class BoxedTextBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_BoxedTextBlock';

    private static string $icon = 'font-icon-block-content';

    private static string $singular_name = 'Boxed text';

    private static string $plural_name = 'Boxed text blocks';

    private static array $db = [
        'Content' => 'HTMLText',
        'BoxColor' => 'Varchar(7)',
        'AccentBorderColor' => 'Varchar(7)',
    ];

    private static array $defaults = [
        'PaddingTop' => 10,
        'PaddingRight' => 20,
        'PaddingBottom' => 10,
        'PaddingLeft' => 20,
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Boxed text');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('BoxColor', HexColorField::create(
            'BoxColor',
            _t(__CLASS__ . '.BOX_BACKGROUND_COLOUR', 'Box background colour')
        )->setDescription(_t(
            __CLASS__ . '.BOX_BACKGROUND_DESCRIPTION',
            'Leave blank to use the brand accent background.'
        )));
        $fields->replaceField('AccentBorderColor', HexColorField::create(
            'AccentBorderColor',
            _t(__CLASS__ . '.ACCENT_BORDER_COLOUR', 'Accent bar colour (optional)')
        )->setDescription(_t(
            __CLASS__ . '.ACCENT_BORDER_DESCRIPTION',
            'Adds a bar down the left edge, for callouts and quotes.'
        )));

        return $fields;
    }

    public function usesBlockAlignment(): bool
    {
        return false;
    }

    /**
     * The box is always a light panel, so its text keeps normal (non-inverse)
     * colours even when the surrounding section is a primary band.
     */
    public function isInverse(): bool
    {
        return false;
    }

    public function BoxColorSafe(): string
    {
        return $this->safeColor($this->BoxColor)
            ?: $this->safeColor($this->getRenderBrand()?->AccentBackground)
            ?: '#f4f4f4';
    }

    public function BoxBorderStyle(): string
    {
        $color = $this->safeColor($this->AccentBorderColor);

        return $color ? 'border-left:4px solid ' . $color . ';' : '';
    }
}
