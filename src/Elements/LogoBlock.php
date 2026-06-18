<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;

class LogoBlock extends NewsletterBlockElemental
{
    use ScaledImageTrait;

    private static string $table_name = 'Newsletter_LogoBlock';

    private static string $icon = 'font-icon-block-image';

    private static string $singular_name = 'Logo';

    private static string $plural_name = 'Logos';

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

    private static array $defaults = [
        'PaddingTop' => 20,
        'PaddingRight' => 20,
        'PaddingBottom' => 20,
        'PaddingLeft' => 20,
        'Alignment' => 'center',
        'MaxWidth' => 200,
    ];

    public function getType(): string
    {
        return 'Logo';
    }
}
