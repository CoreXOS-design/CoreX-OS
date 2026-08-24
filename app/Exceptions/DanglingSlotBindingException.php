<?php

namespace App\Exceptions;

use Exception;

/**
 * A recipient-template slot binding points at a Contact or recipient that no
 * longer resolves — the recipient was removed or moved to a different role
 * on the recipient screen after the binding was made, but before
 * finalisation. Blocks generation with a message naming the specific slot,
 * rather than freezing a document with a dangling or blank clause.
 */
class DanglingSlotBindingException extends Exception
{
    public static function forSlot(string $slotKey, string $label): self
    {
        return new self("\"{$label}\" was removed or changed — re-link it before sending.", 0, null);
    }
}
