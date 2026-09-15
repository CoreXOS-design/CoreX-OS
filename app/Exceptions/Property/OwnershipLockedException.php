<?php

declare(strict_types=1);

namespace App\Exceptions\Property;

use Exception;

/**
 * AT-398 — thrown by PropertyOwnershipGuard when something would change the
 * seller-side contacts (owner/seller/landlord/lessor) on a property that is
 * tied to an open deal (accepted_status pending or granted). Johan: "we do
 * not allow owner change" — the deal was built on those owners, they are the
 * people who must sign, and the set cannot move underneath it.
 */
class OwnershipLockedException extends Exception
{
}
