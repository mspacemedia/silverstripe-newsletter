<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * Raw custom HTML, emitted verbatim. Use with care — it is not sanitised, so it
 * is for trusted admins composing email-safe snippets.
 */
class CodeBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_CodeBlock';

    private static string $icon = 'font-icon-code';

    private static string $singular_name = 'Custom HTML';

    private static string $plural_name = 'Custom HTML blocks';

    private static array $db = [
        'RawHTML' => 'Text',
    ];

    public function getType(): string
    {
        return _t(__CLASS__ . '.TYPE', 'Custom HTML');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('RawHTML');

        $editorClass = 'NathanCox\\CodeEditorField\\Forms\\CodeEditorField';
        if (class_exists($editorClass)) {
            $editor = $editorClass::create('RawHTML', _t(__CLASS__ . '.CUSTOM_HTML', 'Custom HTML'))
                ->setMode('htmlmixed');
        } else {
            $editor = TextareaField::create('RawHTML', _t(__CLASS__ . '.CUSTOM_HTML', 'Custom HTML'))
                ->setRows(12);
        }

        $fields->addFieldToTab('Root.Main', $editor);

        return $fields;
    }
}
