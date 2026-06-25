<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

/**
 * Row of "follow us" links. Only the networks with a URL set are rendered.
 *
 * The IconStyle picks how each network is shown: a monochrome logo (black on
 * white or white on black) or a plain text link. Logos are PNGs bundled in the
 * module's exposed client/dist and referenced by absolute URL so they resolve in
 * an email client. There is no colour set, so only the two mono styles + text.
 */
class SocialFollowBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_SocialFollowBlock';

    private static string $icon = 'font-icon-share';

    private static string $singular_name = 'Social follow';

    private static string $plural_name = 'Social follow blocks';

    private static array $db = [
        'IconStyle' => "Enum('black-on-white,white-on-black,text','black-on-white')",
        'FacebookURL' => 'Varchar(2083)',
        'InstagramURL' => 'Varchar(2083)',
        'TwitterURL' => 'Varchar(2083)',
        'LinkedInURL' => 'Varchar(2083)',
        'YouTubeURL' => 'Varchar(2083)',
        'TikTokURL' => 'Varchar(2083)',
        'WhatsAppURL' => 'Varchar(2083)',
    ];

    private static array $defaults = [
        'Alignment' => 'center',
        'IconStyle' => 'black-on-white',
    ];

    /**
     * Pixel size each logo is rendered at. Outlook ignores CSS sizing on images,
     * so width/height attributes carry it; the source PNGs are ~119px square.
     */
    private const ICON_SIZE = 32;

    /**
     * IconStyle value => folder under client/dist/social-icons. A style absent
     * from this map (i.e. "text") renders as a text link with no logo.
     */
    private const ICON_FOLDERS = [
        'black-on-white' => 'black-on-white',
        'white-on-black' => 'white-on-black',
    ];

    /**
     * Network key => {URL field, icon PNG basename}. The icon basename matches
     * the files in each social-icons folder.
     */
    private const NETWORKS = [
        'Facebook' => ['field' => 'FacebookURL', 'icon' => 'Facebook'],
        'Instagram' => ['field' => 'InstagramURL', 'icon' => 'Instagram'],
        'X' => ['field' => 'TwitterURL', 'icon' => 'X'],
        'LinkedIn' => ['field' => 'LinkedInURL', 'icon' => 'LinkedIn'],
        'YouTube' => ['field' => 'YouTubeURL', 'icon' => 'YouTube'],
        'TikTok' => ['field' => 'TikTokURL', 'icon' => 'TikTok'],
        'WhatsApp' => ['field' => 'WhatsAppURL', 'icon' => 'WhatsApp'],
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Social follow');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->addFieldToTab('Root.Main', DropdownField::create(
            'IconStyle',
            _t(__CLASS__ . '.ICON_STYLE', 'Icon style'),
            [
                'black-on-white' => _t(__CLASS__ . '.STYLE_LIGHT', 'Black logo on white'),
                'white-on-black' => _t(__CLASS__ . '.STYLE_DARK', 'White logo on black'),
                'text' => _t(__CLASS__ . '.STYLE_TEXT', 'Text only'),
            ]
        ), 'FacebookURL');

        $fields->dataFieldByName('TwitterURL')
            ?->setTitle(_t(__CLASS__ . '.X_URL', 'X (Twitter) URL'));

        return $fields;
    }

    /**
     * Present networks with a URL set as {Label, URL, IconURL} rows for the
     * template to loop. IconURL is null when the text-only style is selected.
     */
    public function Links(): ArrayList
    {
        $iconSize = self::ICON_SIZE;

        $list = ArrayList::create();
        foreach (self::NETWORKS as $label => $info) {
            $url = $this->getField($info['field']);
            if (trim((string) $url) === '') {
                continue;
            }

            $list->push(ArrayData::create([
                'Label' => $label,
                'URL' => $url,
                'IconURL' => $this->iconURL($info['icon']),
                'IconSize' => $iconSize,
            ]));
        }

        return $list;
    }

    /**
     * Absolute URL to a network's logo for the current IconStyle, or null for
     * the text-only style. Absolute because the email is rendered out of the
     * browser request context and the client fetches images cross-origin.
     */
    public function iconURL(string $icon): ?string
    {
        $folder = self::ICON_FOLDERS[$this->IconStyle] ?? null;
        if ($folder === null) {
            return null;
        }

        $resource = ModuleResourceLoader::singleton()->resolveURL(
            'mspacemedia/silverstripe-newsletter:client/dist/social-icons/' . $folder . '/' . $icon . '.png'
        );

        return Director::absoluteURL($resource);
    }
}
