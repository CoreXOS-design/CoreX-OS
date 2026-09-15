<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AT-392, 2026-09-08 — Johan approved mark ownership + "a version check on
 * save so a genuine collision is visible and recoverable rather than
 * silent." Thrown by RentalApplicationDocumentHighlightService::applyMarks()
 * when the client's `base_version` no longer matches the document's current
 * `marks_version` — someone else's save (agent, authoriser, or the same
 * user in another tab) landed first. Caught explicitly in
 * HandlesRentalApplicationDocumentMarks::applyHighlight() and turned into a
 * 409 the client can react to (reload, don't silently overwrite).
 */
final class RentalApplicationMarkVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Marks have changed since this document was loaded — reload and reapply.');
    }
}
