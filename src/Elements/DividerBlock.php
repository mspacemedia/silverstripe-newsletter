<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use MSpaceMedia\Newsletter\Forms\HexColorField;
use SilverStripe\Forms\FieldList;

class DividerBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_DividerBlock';

    private static string $icon = 'font-icon-menu-modal';

    private static string $singular_name = 'Divider';

    private static string $plural_name = 'Dividers';

    private static array $db = [
        'LineColor' => 'Varchar(7)',
        'Thickness' => 'Int',
    ];

    private static array $defaults = [
        'PaddingTop' => 10,
        'PaddingRight' => 20,
        'PaddingBottom' => 10,
        'PaddingLeft' => 20,
        'Thickness' => 1,
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Divider');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('LineColor', HexColorField::create(
            'LineColor',
            _t(__CLASS__ . '.LINE_COLOUR', 'Line colour')
        )->setDescription(_t(
            __CLASS__ . '.LINE_COLOUR_DESCRIPTION',
            'Leave blank to inherit the brand divider colour.'
        )));

        return $fields;
    }

    public function EffectiveLineColor(): string
    {
        return $this->safeColor($this->LineColor)
            ?: $this->safeColor($this->getRenderBrand()?->DividerColor)
            ?: '#dddddd';
    }

    public function LineThickness(): int
    {
        return max(1, (int) $this->Thickness);
    }

    /**
     * Inline CSS for an <hr> line. Kept for templates overridden in host
     * projects; the module's template draws a table cell instead, which
     * Outlook renders without its default bevelled rule.
     */
    public function LineStyle(): string
    {
        $color = $this->EffectiveLineColor();
        $thickness = $this->LineThickness();

        return sprintf(
            'border:0;border-top:%dpx solid %s;height:0;line-height:0;width:100%%',
            $thickness,
            $color
        );
    }
}
