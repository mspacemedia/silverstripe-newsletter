<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Assets\Image;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

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
        return _t(__CLASS__ . '.TYPE', 'Image group');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('Images');

        if ($this->exists()) {
            $sortableClass = 'Bummzack\\SortableFile\\Forms\\SortableUploadField';
            $upload = class_exists($sortableClass)
                ? $sortableClass::create('Images', _t(__CLASS__ . '.IMAGES', 'Images'))
                : UploadField::create('Images', _t(__CLASS__ . '.IMAGES', 'Images'));
            $upload->setFolderName('newsletter');
            $fields->addFieldToTab('Root.Main', $upload);
        }

        return $fields;
    }

    public function SortedImages()
    {
        return $this->Images()->sort('SortOrder');
    }

    /**
     * Images resized to their column, with explicit widths: Outlook ignores
     * CSS max-width and would otherwise show each image at its native size.
     */
    public function ScaledImages(): ArrayList
    {
        $width = $this->ColumnImageWidth();
        $list = ArrayList::create();

        foreach ($this->SortedImages() as $image) {
            if (!$image->exists()) {
                continue;
            }

            $scaled = (int) $image->getWidth() > $width ? $image->ScaleWidth($width) : $image;
            if (!$scaled) {
                continue;
            }

            $list->push(ArrayData::create([
                'URL' => $scaled->getAbsoluteURL(),
                'Width' => (int) $scaled->getWidth(),
                'Alt' => $image->Title,
            ]));
        }

        return $list;
    }

    public function ColumnImageWidth(): int
    {
        $brand = $this->getRenderBrand();
        $available = ($brand ? (int) $brand->ContentWidth : 600) ?: 600;
        $available -= max(0, (int) $this->PaddingLeft) + max(0, (int) $this->PaddingRight);
        $count = (int) $this->ColumnCount ?: 3;

        // Each cell has 4px padding on both sides.
        return max(1, (int) floor($available / $count) - 8);
    }

    public function ColumnWidthPercent(): int
    {
        $count = (int) $this->ColumnCount ?: 3;

        return (int) floor(100 / $count);
    }
}
