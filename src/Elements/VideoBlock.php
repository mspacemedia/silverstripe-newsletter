<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;

/**
 * Email can't embed video, so this renders a clickable thumbnail that links out
 * to the hosted video (YouTube/Vimeo/etc.).
 */
class VideoBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_VideoBlock';

    private static string $icon = 'font-icon-block-media';

    private static string $singular_name = 'Video';

    private static string $plural_name = 'Videos';

    private static array $db = [
        'VideoURL' => 'Varchar(2083)',
        'Alt' => 'Varchar(255)',
        'MaxWidth' => 'Int',
    ];

    private static array $has_one = [
        'Thumbnail' => Image::class,
    ];

    private static array $owns = [
        'Thumbnail',
    ];

    private static array $defaults = [
        'Alignment' => 'center',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Video');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('VideoURL')
            ?->setDescription(_t(
                __CLASS__ . '.VIDEO_URL_DESCRIPTION',
                'The watch URL the thumbnail links to (YouTube, Vimeo, etc.).'
            ));

        return $fields;
    }

    public function ScaledThumbnail()
    {
        $image = $this->Thumbnail();

        if (!$image || !$image->exists()) {
            return null;
        }

        if ((int) $this->MaxWidth > 0) {
            return $image->ScaleWidth((int) $this->MaxWidth);
        }

        return $image;
    }
}
