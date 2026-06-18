<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;

class ImageCaptionBlock extends NewsletterBlockElemental
{
    use ScaledImageTrait;

    private static string $table_name = 'Newsletter_ImageCaptionBlock';

    private static string $icon = 'font-icon-block-image';

    private static string $singular_name = 'Image + caption';

    private static string $plural_name = 'Image + caption blocks';

    private static array $db = [
        'Alt' => 'Varchar(255)',
        'Caption' => 'Varchar(255)',
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
        return _t(__CLASS__ . '.TYPE', 'Image + caption');
    }
}
