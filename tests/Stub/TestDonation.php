<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test-only donation belonging to a {@see TestDonor}. "Secret" is intentionally
 * left out of the allowlist so the engine's security gate can be asserted.
 *
 * @property float $Amount
 * @property string $Status
 * @property string $Secret
 * @property int $DonorID
 */
class TestDonation extends DataObject implements TestOnly
{
    private static string $table_name = 'Newsletter_TestDonation';

    private static array $db = [
        'Amount' => 'Currency',
        'Status' => 'Varchar',
        'Secret' => 'Varchar',
    ];

    private static array $has_one = [
        'Donor' => TestDonor::class,
    ];

    // A default sort here would, without care, leak ORDER BY columns into the
    // segment fast path's GROUP BY query — SegmentServiceTest guards that.
    private static string $default_sort = 'Created DESC';
}
