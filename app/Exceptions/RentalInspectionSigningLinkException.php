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
    public const NOT_SIGNABLE_TYPE = 'not_signable_type';
    public const NOT_READY = 'not_ready';
    public const UNAVAILABLE = 'unavailable';
    public const ALREADY_USED = 'already_used';
    public const ALREADY_RECORDED = 'already_recorded';
    public const AGENT_LINK = 'agent_link';
    public const INVALID = 'invalid';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
