<?php

declare(strict_types=1);

namespace App\Models\Communications;

use Illuminate\Database\Eloquent\Model;

/**
 * 2026-09-08/09 (Johan, circuit breaker) — per-HOST (not per-agency, not
 * per-mailbox) breaker state. See the creating migration's docblock for the
 * full rationale. Deliberately NOT agency-scoped (no BelongsToAgency) —
 * the failure mode this guards against (a provider blocking our server's
 * IP) is a property of the host, shared by whichever agencies happen to
 * point mailboxes at it.
 */
class CommunicationHostCircuitBreaker extends Model
{
    protected $table = 'communication_host_circuit_breakers';

    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';

    protected $fillable = [
        'host', 'state', 'opened_at', 'last_probe_at', 'consecutive_probe_failures',
        // 2026-09-09 (Johan, auth-lock safeguard) — a SEPARATE mechanism from
        // state/opened_at above; see the creating migration's docblock.
        'auth_failure_count', 'auth_locked_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'last_probe_at' => 'datetime',
        'consecutive_probe_failures' => 'integer',
        'auth_failure_count' => 'integer',
        'auth_locked_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    /** 2026-09-09 (Johan, auth-lock safeguard) — never self-heals; only an explicit human reset clears this. */
    public function isAuthLocked(): bool
    {
        return $this->auth_locked_at !== null;
    }
}
