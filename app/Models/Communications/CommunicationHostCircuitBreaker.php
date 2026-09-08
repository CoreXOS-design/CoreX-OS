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
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'last_probe_at' => 'datetime',
        'consecutive_probe_failures' => 'integer',
    ];

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }
}
