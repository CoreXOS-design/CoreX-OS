<?php

namespace App\Exceptions\Rentals;

use RuntimeException;

/**
 * A portal-access action that cannot go ahead, with a plain-words reason an agent can read on screen.
 * `reason` is a stable machine key (no_email, belongs_to_someone_else, …) for tests and callers.
 */
class PortalAccessException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }
}
