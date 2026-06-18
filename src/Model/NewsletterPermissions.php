<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Model;

use SilverStripe\Security\Permission;

/**
 * Shared CMS access control for newsletter models — everything is gated behind
 * the single MANAGE_NEWSLETTERS permission (provided by NewsletterSubscriber).
 */
trait NewsletterPermissions
{
    public function canView($member = null): bool
    {
        return Permission::check('MANAGE_NEWSLETTERS', 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('MANAGE_NEWSLETTERS', 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('MANAGE_NEWSLETTERS', 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('MANAGE_NEWSLETTERS', 'any', $member);
    }
}
