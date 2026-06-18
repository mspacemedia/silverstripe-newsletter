<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Core\Convert;
use SilverStripe\ORM\FieldType\DBHTMLText;

class HeadingBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_HeadingBlock';

    private static string $icon = 'font-icon-header';

    private static string $singular_name = 'Heading';

    private static string $plural_name = 'Headings';

    private static array $db = [
        'Content' => 'Varchar(255)',
        'Level' => "Enum('h1,h2,h3,h4,h5,h6','h2')",
    ];

    public function getType(): string
    {
        return 'Heading';
    }

    public function HeadingTag(): string
    {
        return in_array($this->Level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $this->Level : 'h2';
    }

    /**
     * Render the real <hN> with the brand's heading font/size/colour (each
     * overridable per block via FontFamily / TextColor). Emitting an actual
     * heading tag also lets the brand type-scale rules apply consistently.
     */
    public function RenderedHeading(): DBHTMLText
    {
        $tag = $this->HeadingTag();
        $brand = $this->getRenderBrand();

        $level = (int) substr($tag, 1);
        $size = $brand ? $brand->headingSizes()[$level] : [1 => 32, 2 => 26, 3 => 22, 4 => 18, 5 => 16, 6 => 14][$level];
        $font = $this->FontFamily ?: ($brand ? $brand->HeadingFontStack() : 'Arial, Helvetica, sans-serif');
        $color = $this->safeColor($this->TextColor) ?: ($brand && $brand->HeadingColor ? $brand->HeadingColor : '#111111');

        $style = sprintf(
            'font-family:%s;font-size:%dpx;color:%s;line-height:1.25;font-weight:700;margin:0;',
            $font,
            $size,
            $color
        );

        $html = '<' . $tag . ' style="' . $style . '">' . Convert::raw2xml($this->Content) . '</' . $tag . '>';

        return DBHTMLText::create()->setValue($html);
    }
}
