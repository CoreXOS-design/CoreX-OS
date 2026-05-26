<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an action would leave an agency with zero Admins.
 * See .ai/specs/agency-admin-rule.md (R3 — Last-Admin protection).
 */
class LastAdminException extends RuntimeException
{
    public static function forAgency(int $agencyId, string $context = 'remove'): self
    {
        return new self(
            "Cannot {$context} the only Admin for agency #{$agencyId}. "
            . 'Assign another Admin first, then retry.'
        );
    }
}
