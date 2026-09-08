<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Reopen/resubmit, 2026-09-08 — Johan: "agent has review screen open,
 * applicant resubmits mid-review... reuse the exact 409-conflict pattern
 * you already built and shipped for document marks." Mirrors
 * RentalApplicationMarkVersionConflictException exactly, one level up: that
 * one fires when a document's marks_version has moved since a tab loaded
 * it; this one fires when an application's current_generation has moved —
 * i.e. the applicant reopened-and-resubmitted while an agent's review
 * screen was still showing the PREVIOUS generation's content.
 */
final class RentalApplicationGenerationConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentGeneration)
    {
        parent::__construct('This application changed since you opened it — reload to see the new version.');
    }
}
