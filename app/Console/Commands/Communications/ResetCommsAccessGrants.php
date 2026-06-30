<?php

namespace App\Console\Commands\Communications;

use App\Services\Communications\CommsAccessGrantService;
use Illuminate\Console\Command;

/**
 * AT-118 — nightly 00:00 reset of all active communications access grants.
 *
 * Closes the never-closing-session loophole: a transient grant must not survive
 * a session left open overnight. Revokes every live grant + logs a
 * 'midnight_reset' event per grant into comms_access_audit_log. Also expires any
 * stale pending requests. Scheduled dailyAt('00:00') (Africa/Johannesburg).
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.3
 */
class ResetCommsAccessGrants extends Command
{
    protected $signature   = 'comms-access:reset';
    protected $description = 'Revoke all active communications access grants at midnight (POPIA-logged) and expire stale pending requests.';

    public function handle(CommsAccessGrantService $grants): int
    {
        $revoked = $grants->revokeAllActive('midnight_reset');
        $expired = $grants->expireStalePending();

        $this->info("Midnight reset: revoked {$revoked} active grant(s), expired {$expired} stale pending request(s).");

        return self::SUCCESS;
    }
}
