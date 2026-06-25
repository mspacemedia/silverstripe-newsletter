<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test-only anchor model: a donor with a has_many of donations, used to exercise
 * relation traversal and aggregates in MergeFieldEngineTest.
 *
 * @property string $FirstName
 * @property string $Surname
 * @property string $Email
 * @method \SilverStripe\ORM\HasManyList Donations()
 */
class TestDonor extends DataObject implements TestOnly
{
    private static string $table_name = 'Newsletter_TestDonor';

    private static array $db = [
        'FirstName' => 'Varchar',
        'Surname' => 'Varchar',
        'Email' => 'Varchar',
    ];

    private static array $has_many = [
        'Donations' => TestDonation::class,
    ];
}
