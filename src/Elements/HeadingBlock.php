<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\FieldType\DBHTMLText;

class HeadingBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_HeadingBlock';

    private static string $icon = 'font-icon-header';

    private static string $singular_name = 'Heading';

    private static string $plural_name = 'Headings';

    private static array $db = [
        'Kicker' => 'Varchar(100)',
        'Content' => 'Varchar(255)',
        'Level' => "Enum('h1,h2,h3,h4,h5,h6','h2')",
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Heading');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('Kicker')
            ?->setTitle(_t(__CLASS__ . '.KICKER', 'Kicker (optional)'))
            ?->setDescription(_t(
                __CLASS__ . '.KICKER_DESCRIPTION',
                'Short label shown above the heading in small capitals, e.g. "October update".'
            ));

        return $fields;
    }

    public function HeadingTag(): string
    {
        return in_array($this->Level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $this->Level : 'h2';
    }

    /**
     * Render the real <hN> with the brand's heading font/size/weight/colour
     * (font and colour overridable per block). Emitting an actual heading tag
     * also lets the brand type-scale rules apply consistently.
     */
    public function RenderedHeading(): DBHTMLText
    {
        $tag = $this->HeadingTag();
        $brand = $this->getRenderBrand();

        $level = (int) substr($tag, 1);
        $size = $brand ? $brand->headingSizes()[$level] : [1 => 32, 2 => 26, 3 => 22, 4 => 18, 5 => 16, 6 => 14][$level];
        $weight = $brand ? $brand->HeadingWeight() : 700;
        $font = Convert::raw2att($this->EffectiveHeadingFont());

        $html = '';
        if (trim((string) $this->Kicker) !== '') {
            $html .= sprintf(
                '<p style="font-family:%s;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.14em;'
                . 'text-transform:uppercase;color:%s;margin:0 0 10px;">%s</p>',
                Convert::raw2att($this->EffectiveFont()),
                $this->EffectiveAccentColor(),
                Convert::raw2xml($this->Kicker)
            );
        }

        $html .= sprintf(
            '<%1$s class="nl-%1$s" style="font-family:%2$s;font-size:%3$dpx;font-weight:%4$d;color:%5$s;'
            . 'line-height:1.2;letter-spacing:-0.01em;margin:0;mso-line-height-rule:exactly;">%6$s</%1$s>',
            $tag,
            $font,
            $size,
            $weight,
            $this->EffectiveHeadingColor(),
            Convert::raw2xml($this->Content)
        );

        return DBHTMLText::create()->setValue($html);
    }
}
