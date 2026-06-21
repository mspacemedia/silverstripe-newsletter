<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Forms\FieldList;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;

/**
 * A multi-column layout row (2 or 3 columns of HTML), rendered as table cells
 * that stack on mobile via the wrapper's media query.
 */
class ColumnsBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_ColumnsBlock';

    private static string $icon = 'font-icon-columns';

    private static string $singular_name = 'Columns';

    private static string $plural_name = 'Column blocks';

    private static array $db = [
        'ColumnCount' => "Enum('2,3','2')",
        'Column1' => 'HTMLText',
        'Column2' => 'HTMLText',
        'Column3' => 'HTMLText',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Columns');
    }

    public function usesBlockAlignment(): bool
    {
        return false;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('Column3')
            ?->setDescription(_t(__CLASS__ . '.COLUMN3_DESCRIPTION', 'Only shown when "Column count" is 3.'));

        return $fields;
    }

    /**
     * The columns to render, honouring ColumnCount.
     */
    public function Columns(): ArrayList
    {
        $count = (int) $this->ColumnCount ?: 2;
        $list = ArrayList::create();

        foreach (['Column1', 'Column2', 'Column3'] as $i => $name) {
            if ($i >= $count) {
                break;
            }
            $list->push(ArrayData::create(['Content' => $this->renderedRichTextField($name)]));
        }

        return $list;
    }

    public function ColumnWidthPercent(): int
    {
        $count = (int) $this->ColumnCount ?: 2;

        return (int) floor(100 / $count);
    }
}
