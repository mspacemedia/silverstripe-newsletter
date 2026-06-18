<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

/**
 * Row of "follow us" links. Only the networks with a URL set are rendered.
 */
class SocialFollowBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_SocialFollowBlock';

    private static string $icon = 'font-icon-share';

    private static string $singular_name = 'Social follow';

    private static string $plural_name = 'Social follow blocks';

    private static array $db = [
        'FacebookURL' => 'Varchar(2083)',
        'InstagramURL' => 'Varchar(2083)',
        'TwitterURL' => 'Varchar(2083)',
        'LinkedInURL' => 'Varchar(2083)',
        'YouTubeURL' => 'Varchar(2083)',
    ];

    private static array $defaults = [
        'Alignment' => 'center',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Social follow');
    }

    /**
     * Present networks as {Label, URL} rows for the template to loop.
     */
    public function Links(): ArrayList
    {
        $networks = [
            'Facebook' => $this->FacebookURL,
            'Instagram' => $this->InstagramURL,
            'Twitter' => $this->TwitterURL,
            'LinkedIn' => $this->LinkedInURL,
            'YouTube' => $this->YouTubeURL,
        ];

        $list = ArrayList::create();
        foreach ($networks as $label => $url) {
            if (trim((string) $url) !== '') {
                $list->push(ArrayData::create(['Label' => $label, 'URL' => $url]));
            }
        }

        return $list;
    }
}
