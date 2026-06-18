<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\PermissionProvider;

/**
 * A single mailing recipient. Subscribers are globally unique by email and may
 * belong to many audiences. Suppression (Unsubscribed/Bounced) is global — a
 * suppressed subscriber is excluded from every audience's sends.
 */
class NewsletterSubscriber extends DataObject implements PermissionProvider
{
    use NewsletterPermissions;

    private static string $table_name = 'Newsletter_Subscriber';

    private static string $singular_name = 'Subscriber';

    private static string $plural_name = 'Subscribers';

    private static array $db = [
        'Email' => 'Varchar(254)',
        'FirstName' => 'Varchar(255)',
        'Surname' => 'Varchar(255)',
        'Status' => "Enum('Active,Unsubscribed,Bounced','Active')",
        // Arbitrary merge fields (MailChimp-style *|TAG|*) stored as JSON.
        'MergeData' => 'Text',
        'UnsubscribeToken' => 'Varchar(64)',
    ];

    private static array $belongs_many_many = [
        'Audiences' => NewsletterAudience::class,
    ];

    private static array $indexes = [
        'Email' => ['type' => 'unique', 'columns' => ['Email']],
        'UnsubscribeToken' => true,
    ];

    private static array $summary_fields = [
        'Email' => 'Email',
        'getDisplayName' => 'Name',
        'Status' => 'Status',
    ];

    private static array $searchable_fields = [
        'Email',
        'FirstName',
        'Surname',
        'Status',
    ];

    private static string $default_sort = 'Email ASC';

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->UnsubscribeToken) {
            $this->UnsubscribeToken = bin2hex(random_bytes(16));
        }
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['MergeData', 'UnsubscribeToken']);

        return $fields;
    }

    /**
     * Decode the JSON merge field blob into an associative array of tag => value.
     *
     * @return array<string, string>
     */
    public function getMergeArray(): array
    {
        $decoded = json_decode((string) $this->MergeData, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $data
     */
    public function setMergeArray(array $data): self
    {
        $this->MergeData = json_encode($data);

        return $this;
    }

    public function getDisplayName(): string
    {
        $name = trim($this->FirstName . ' ' . $this->Surname);

        return $name !== '' ? $name : (string) $this->Email;
    }

    /**
     * Suppressed subscribers (unsubscribed or bounced) never receive sends.
     */
    public function isSuppressed(): bool
    {
        return $this->Status !== 'Active';
    }

    public function providePermissions(): array
    {
        return [
            'MANAGE_NEWSLETTERS' => [
                'name' => _t(__CLASS__ . '.MANAGE_NEWSLETTERS', 'Manage newsletters, audiences and subscribers'),
                'category' => _t(__CLASS__ . '.PERMISSION_CATEGORY', 'Newsletters'),
            ],
        ];
    }
}
