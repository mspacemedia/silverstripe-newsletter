<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Forms\FieldList;

/**
 * A row of images (2 or 3 across), e.g. a gallery strip or logo wall.
 */
class ImageGroupBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_ImageGroupBlock';

    private static string $icon = 'font-icon-block-media';

    private static string $singular_name = 'Image group';

    private static string $plural_name = 'Image groups';

    private static array $db = [
        'ColumnCount' => "Enum('2,3','3')",
    ];

    private static array $many_many = [
        'Images' => Image::class,
    ];

    private static array $many_many_extraFields = [
        'Images' => ['SortOrder' => 'Int'],
    ];

    private static array $owns = [
        'Images',
    ];

    public function getType(): string
    {
        return 'Image group';
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('Images');

        if ($this->exists()) {
            $sortableClass = 'Bummzack\\SortableFile\\Forms\\SortableUploadField';
            $upload = class_exists($sortableClass)
                ? $sortableClass::create('Images', 'Images')
                : UploadField::create('Images', 'Images');
            $upload->setFolderName('newsletter');
            $fields->addFieldToTab('Root.Main', $upload);
        }

        return $fields;
    }

    public function SortedImages()
    {
        return $this->Images()->sort('SortOrder');
    }

    public function ColumnWidthPercent(): int
    {
        $count = (int) $this->ColumnCount ?: 3;

        return (int) floor(100 / $count);
    }
}
