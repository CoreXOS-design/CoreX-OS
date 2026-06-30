<?php

namespace App\Listeners\Communications;

use App\Services\Communications\CommsAccessGrantService;
use Illuminate\Auth\Events\Logout;

/**
 * AT-118 — a session-scoped communications access grant dies at logout.
 * Revokes the logging-out user's live grants and logs a 'session_expired' event
 * (the session_id binding already closes the gate on the next session; this makes
 * the end-of-session explicit in the POPIA trail).
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.3
 */
class RevokeCommsGrantsOnLogout
{
    public function __construct(protected CommsAccessGrantService $grants) {}

    public function handle(Logout $event): void
    {
        if ($event->user) {
            $this->grants->revokeForUser($event->user, 'logout');
        }
    }
}
