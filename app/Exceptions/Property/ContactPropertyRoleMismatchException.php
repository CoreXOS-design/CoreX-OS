<?php

declare(strict_types=1);

namespace App\Exceptions\Property;

/**
 * Thrown by ContactPropertyLinker::unlink() when the caller asserts a role
 * that no longer matches the row's actual current role. Deliberately a loud
 * failure, not a silent no-op: the conductor's ruling, 2026-09-13 — a
 * mismatch means the relationship changed under the caller's feet since
 * they last read it, and silently doing nothing would leave a live link
 * the caller believes it removed (e.g. a tenant staying attached to a
 * property after they've moved out, because the role had since changed to
 * something else and the unlink call quietly matched no row at all).
 */
class ContactPropertyRoleMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly int $contactId,
        public readonly int $propertyId,
        public readonly string $expectedRole,
        public readonly string $actualRole,
    ) {
        parent::__construct(
            "contact_property row for contact {$contactId} / property {$propertyId} has role "
            . "'{$actualRole}', not the expected '{$expectedRole}' — refusing to unlink silently."
        );
    }
}
