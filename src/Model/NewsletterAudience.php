<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use ilateral\SilverStripe\ImportExport\GridField\GridFieldImporter;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * A CMS-defined mailing list. Subscribers are attached manually, via CSV import,
 * or refreshed dynamically from a registered AudienceSourceProvider matched by
 * SourceKey (see NewsletterAudienceRefreshTask).
 */
class NewsletterAudience extends DataObject
{
    use NewsletterPermissions;

    private static string $table_name = 'Newsletter_Audience';

    private static string $singular_name = 'Audience';

    private static string $plural_name = 'Audiences';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Description' => 'Text',
        // Optional: links this audience to a registered source provider for
        // dynamic refresh. Empty = manual / CSV-only audience.
        'SourceKey' => 'Varchar(100)',
    ];

    private static array $many_many = [
        'Subscribers' => NewsletterSubscriber::class,
    ];

    private static array $belongs_many_many = [
        'Issues' => NewsletterIssue::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'SourceKey' => 'Source',
        'ActiveSubscriberCount' => 'Active subscribers',
    ];

    private static string $default_sort = 'Title ASC';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        if ($this->exists()) {
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'SubscriberCount',
                $this->subscriberCountHTML()->forTemplate()
            ));

            $this->addImportExportToGrid($fields->dataFieldByName('Subscribers'));
        }

        return $fields;
    }

    private function subscriberCountHTML(): DBHTMLText
    {
        $message = _t(
            __CLASS__ . '.SUBSCRIBER_COUNT',
            '{count} active subscriber(s) of {total} total.',
            [
                'count' => $this->ActiveSubscribers()->count(),
                'total' => $this->Subscribers()->count(),
            ]
        );

        return DBHTMLText::create()->setValue(
            '<p class="message notice">' . Convert::raw2xml($message) . '</p>'
        );
    }

    /**
     * Add CSV import (attaching rows to THIS audience, deduped by email) and CSV
     * export to the Subscribers grid. Import requires i-lateral/importexport; the
     * module degrades gracefully (export only) when it is absent.
     */
    private function addImportExportToGrid(?GridField $grid): void
    {
        if (!$grid) {
            return;
        }

        $config = $grid->getConfig();
        $config->addComponent(GridFieldExportButton::create('buttons-before-left'));

        if (!class_exists(GridFieldImporter::class)) {
            return;
        }

        $importer = new GridFieldImporter('buttons-before-left');
        $config->addComponent($importer);

        $audience = $this;
        $loader = $importer->getLoader($grid);
        $loader->recordCallback = function ($subscriber, $row) use ($audience) {
            $existing = NewsletterSubscriber::get()
                ->filter('Email', $subscriber->Email)
                ->first();

            if ($existing) {
                $audience->Subscribers()->add($existing);
                return $existing;
            }

            $subscriber->write();
            $audience->Subscribers()->add($subscriber);
            return $subscriber;
        };
    }

    /**
     * Find (or create) the audience linked to a given source key. Used by the
     * refresh task and the subscription manager so dynamic audiences are created
     * on first use.
     */
    public static function getOrCreateBySourceKey(string $key, ?string $title = null): self
    {
        $audience = self::get()->filter('SourceKey', $key)->first();

        if (!$audience) {
            $audience = self::create();
            $audience->Title = $title ?: ucfirst($key);
            $audience->SourceKey = $key;
            $audience->write();
        }

        return $audience;
    }

    /**
     * Active (non-suppressed) subscribers in this audience.
     */
    public function ActiveSubscribers(): DataList
    {
        return $this->Subscribers()->filter('Status', 'Active');
    }

    public function getActiveSubscriberCount(): int
    {
        return $this->ActiveSubscribers()->count();
    }
}
