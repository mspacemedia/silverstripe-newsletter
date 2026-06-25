<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use MSpaceMedia\Newsletter\Forms\MergeFieldBuilderField;
use MSpaceMedia\Newsletter\Service\MergeExpression\ExpressionException;
use MSpaceMedia\Newsletter\Service\MergeExpression\Parser;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;

/**
 * An editor-defined computed merge field. Each holds a Tag (used in content as
 * {{ TAG }}) and an Expression evaluated per recipient against the subscriber's
 * anchor record — e.g. Tag "TOTALDONATION", Expression
 * "Order.Sum(TotalDonation) | currency".
 *
 * Definitions are global, so any issue/block can reference {{ TAG }}.
 *
 * CMS access uses the shared NewsletterPermissions (MANAGE_NEWSLETTERS); the
 * permission itself is provided by NewsletterSubscriber.
 */
class NewsletterMergeField extends DataObject
{
    use NewsletterPermissions;

    private static string $table_name = 'Newsletter_MergeField';

    private static string $singular_name = 'Merge field';

    private static string $plural_name = 'Merge fields';

    private static array $db = [
        'Tag' => 'Varchar(100)',
        'Title' => 'Varchar(255)',
        'Expression' => 'Text',
    ];

    private static array $indexes = [
        'Tag' => ['type' => 'unique', 'columns' => ['Tag']],
    ];

    private static array $summary_fields = [
        'Tag' => 'Tag',
        'Title' => 'Description',
        'Expression' => 'Expression',
    ];

    private static string $default_sort = 'Tag ASC';

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $this->Tag = self::normaliseTag((string) $this->Tag);
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Tag) === '') {
            $result->addError(_t(__CLASS__ . '.TAG_REQUIRED', 'A tag is required.'));
        }

        $expression = trim((string) $this->Expression);
        if ($expression === '') {
            $result->addError(_t(__CLASS__ . '.EXPRESSION_REQUIRED', 'An expression is required.'));
        } else {
            try {
                (new Parser())->parse($expression);
            } catch (ExpressionException $e) {
                $result->addError(_t(
                    __CLASS__ . '.EXPRESSION_INVALID',
                    'Expression error: {message}',
                    ['message' => $e->getMessage()]
                ));
            }
        }

        return $result;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->dataFieldByName('Tag')
            ?->setDescription(_t(
                __CLASS__ . '.TAG_DESC',
                'Use in content as {{ TAG }}. Letters, numbers and underscores; stored uppercase.'
            ));

        // Replace the plain Expression textarea with the visual builder, which
        // offers relation/field pickers and a live preview against a sample
        // record. The builder writes the canonical expression string back.
        $fields->replaceField(
            'Expression',
            MergeFieldBuilderField::create('Expression', _t(__CLASS__ . '.EXPRESSION', 'Expression'))
        );

        $fields->addFieldToTab('Root.Main', LiteralField::create(
            'MergeFieldHelp',
            '<p class="message notice">' . _t(
                __CLASS__ . '.HELP',
                'Examples: <code>Order.Sum(TotalDonation) | currency</code>, '
                . '<code>Order.Count</code>, '
                . '<code>Order.Where(Status = \'Paid\').Count</code>, '
                . '<code>Concat(FirstName, \' \', Surname)</code>.'
            ) . '</p>'
        ), 'Expression');

        return $fields;
    }

    /**
     * Look up a definition by tag (case-insensitive).
     */
    public static function getByTag(string $tag): ?self
    {
        $normalised = self::normaliseTag($tag);
        if ($normalised === '') {
            return null;
        }

        return self::get()->filter('Tag', $normalised)->first();
    }

    public static function normaliseTag(string $tag): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', trim($tag)) ?? '');
    }
}
