<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;

class ImageBlock extends NewsletterBlockElemental
{
    use ScaledImageTrait {
        ScaledImage as protected scaledImageFromTrait;
    }

    private static string $table_name = 'Newsletter_ImageBlock';

    private static string $icon = 'font-icon-block-image';

    private static string $singular_name = 'Image';

    private static string $plural_name = 'Images';

    private static array $db = [
        'Alt' => 'Varchar(255)',
        'LinkURL' => 'Varchar(2083)',
        'MaxWidth' => 'Int',
    ];

    private static array $has_one = [
        'Image' => Image::class,
    ];

    private static array $owns = [
        'Image',
    ];

    public function getType(): string
    {
        return 'Image';
    }

    /**
     * Edge-to-edge images scale to the brand content width (so a smaller upload
     * isn't stretched blurry); otherwise use the shared MaxWidth behaviour.
     */
    public function ScaledImage()
    {
        if ($this->FullWidth && (int) $this->MaxWidth === 0) {
            $image = $this->Image();
            if (!$image || !$image->exists()) {
                return null;
            }
            $width = $this->getRenderBrand() ? (int) $this->getRenderBrand()->ContentWidth : 600;
            return $image->ScaleWidth($width ?: 600);
        }

        return $this->scaledImageFromTrait();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->dataFieldByName('Alt')
            ?->setDescription('Alternative text shown when images are blocked.');
        $fields->dataFieldByName('LinkURL')
            ?->setTitle('Link URL (optional)');
        $fields->dataFieldByName('MaxWidth')
            ?->setDescription('Optional maximum display width in pixels (e.g. 600).');

        return $fields;
    }
}
