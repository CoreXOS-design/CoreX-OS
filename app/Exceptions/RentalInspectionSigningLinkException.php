<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * .ai/specs/rental-inspections.md §46 — a refusal to act on a signing link, with a stable `reason` the controllers and
 * tests branch on and a plain-language message the person (or the agent) can be shown as-is.
 */
class RentalInspectionSigningLinkException extends RuntimeException
{
    public const NOT_ENABLED = 'not_enabled';
    public const NOT_READY = 'not_ready';
    public const UNAVAILABLE = 'unavailable';
    public const ALREADY_USED = 'already_used';
    public const ALREADY_RECORDED = 'already_recorded';
    public const AGENT_LINK = 'agent_link';
    public const INVALID = 'invalid';

    /** @param array<string, mixed> $details extra structured facts for the screen (e.g. the rooms with items still to record) */
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
