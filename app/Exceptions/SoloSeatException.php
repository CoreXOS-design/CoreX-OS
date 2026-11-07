<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a second person would be put into a single-agent account.
 * A solo agency has exactly one seat — .ai/specs/solo-agent-accounts.md §2/§5.
 */
class SoloSeatException extends RuntimeException
{
    public static function forAgency(int $agencyId): self
    {
        return new self(
            'A single-agent account has one login only, so no one else can be added to it. '
            . "To add a second person, the account must first be converted to a full agency (agency #{$agencyId})."
        );
    }
}
