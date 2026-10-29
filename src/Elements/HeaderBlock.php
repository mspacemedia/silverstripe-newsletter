<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Branded header: renders the brand logo (NewsletterBrand.LogoImage) by default,
 * or a per-block logo override, optionally linked. Background/alignment inherit
 * the usual block appearance overrides.
 */
class HeaderBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_HeaderBlock';

    private static string $icon = 'font-icon-block-banner';

    private static string $singular_name = 'Header';

    private static string $plural_name = 'Headers';

    private static array $db = [
        'Alt' => 'Varchar(255)',
        'LinkURL' => 'Varchar(2083)',
        'MaxWidth' => 'Int',
    ];

    private static array $has_one = [
        'LogoOverride' => Image::class,
    ];

    private static array $owns = [
        'LogoOverride',
    ];

    private static array $defaults = [
        'PaddingTop' => 24,
        'PaddingRight' => 20,
        'PaddingBottom' => 24,
        'PaddingLeft' => 20,
        'Alignment' => 'center',
        'MaxWidth' => 200,
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Header');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('LogoOverrideID');

        $fields->addFieldToTab('Root.Main', UploadField::create(
            'LogoOverride',
            _t(__CLASS__ . '.LOGO_OVERRIDE', 'Logo override')
        )
            ->setFolderName('newsletter')
            ->setDescription(_t(__CLASS__ . '.LOGO_OVERRIDE_DESCRIPTION', 'Leave blank to use the brand/theme logo.')));
        $fields->dataFieldByName('Alt')
            ?->setTitle(_t(__CLASS__ . '.ALT', 'Name / alt text'))
            ?->setDescription(_t(
                __CLASS__ . '.ALT_DESCRIPTION',
                'Describes the logo for screen readers. Shown as a text wordmark when no logo is uploaded.'
            ));
        $fields->dataFieldByName('MaxWidth')?->setDescription(_t(
            __CLASS__ . '.MAX_WIDTH_DESCRIPTION',
            'Logo display width in pixels.'
        ));

        return $fields;
    }

    /**
     * The logo to show — per-block override, else the brand logo — scaled to MaxWidth.
     */
    public function EffectiveLogo()
    {
        $logo = ($this->LogoOverride() && $this->LogoOverride()->exists())
            ? $this->LogoOverride()
            : $this->getRenderBrand()?->LogoImage();

        if (!$logo || !$logo->exists()) {
            return null;
        }

        if ((int) $this->MaxWidth > 0) {
            return $logo->ScaleWidth((int) $this->MaxWidth);
        }

        return $logo;
    }

    /**
     * Text shown in place of a missing logo: the alt text, else the site name.
     */
    public function WordmarkText(): string
    {
        if ($alt = trim((string) $this->Alt)) {
            return $alt;
        }

        return class_exists(SiteConfig::class)
            ? trim((string) SiteConfig::current_site_config()->Title)
            : '';
    }
}
