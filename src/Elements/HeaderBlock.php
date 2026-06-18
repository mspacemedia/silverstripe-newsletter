<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;

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
        return 'Header';
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('LogoOverrideID');

        $fields->addFieldToTab('Root.Main', UploadField::create('LogoOverride', 'Logo override')
            ->setFolderName('newsletter')
            ->setDescription('Leave blank to use the brand/theme logo.'));
        $fields->dataFieldByName('MaxWidth')?->setDescription('Logo display width in pixels.');

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
}
