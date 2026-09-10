<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AT-392, 2026-09-08 — Johan approved mark ownership: "a user may edit
 * their own marks and never another person's." Thrown by
 * RentalApplicationDocumentHighlightService::applyMarks() when an incoming
 * save would remove or alter a mark that belongs to a different, identified
 * author. Should never fire from the normal UI (which already hides the
 * remove control for marks you don't own) — this is the server-side
 * backstop for a stale client or a bypassed control, not the primary UX.
 * Legacy marks with no author (author_user_id null) are NOT protected —
 * nothing to guess, so anyone may still edit them.
 *
 * AT-401, 2026-09-10 — widened from "different user" to "different user, OR
 * an authoriser touching an agent's mark regardless of user id." An agent's
 * mark is evidence; the same person acting as authoriser on a different
 * application must not be able to remove their own past agent-side work
 * either — that is exactly how an agent's mark was destroyed on application
 * 66 under the old per-user-only check.
 */
final class RentalApplicationMarkOwnershipException extends RuntimeException
{
    public function __construct(public readonly string $markId)
    {
        parent::__construct("Mark {$markId} was created by the agent, or belongs to a different user, and cannot be changed or removed here.");
    }
}
