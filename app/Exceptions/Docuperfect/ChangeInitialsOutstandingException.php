<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use RuntimeException;

/**
 * A wet-ink amended document cannot be finalised while any required party still owes an initial on any
 * change (esign-returned-doc-edit-flow.md — the hard completion gate). Finalising a document with unsigned
 * amendments is a legal defect: an amendment nobody initialed is not part of the agreement. Every message
 * on this exception is shown to the acting party verbatim and says exactly how many initials are outstanding.
 *
 * This is the server-side BACKSTOP: the completion/authorise endpoints pre-check and refuse cleanly before
 * any state mutation, but completeDocument() throws this as the last line of defence so NO finalisation path
 * (agent approve, external ceremony, a crafted POST) can ever bypass the gate.
 */
final class ChangeInitialsOutstandingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $outstanding = 0)
    {
        parent::__construct($message);
    }
}
