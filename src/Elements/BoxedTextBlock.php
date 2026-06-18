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
    ];

    private static array $defaults = [
        'PaddingTop' => 10,
        'PaddingRight' => 20,
        'PaddingBottom' => 10,
        'PaddingLeft' => 20,
        'BoxColor' => '#f4f4f4',
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
        ));

        return $fields;
    }

    public function usesBlockAlignment(): bool
    {
        return false;
    }

    public function BoxColorSafe(): string
    {
        return $this->safeColor($this->BoxColor) ?: '#f4f4f4';
    }
}
