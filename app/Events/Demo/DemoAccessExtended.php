<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;

/**
 * An owner added time to an existing demo access grant.
 *
 * Spec: .ai/specs/demo-access-control.md §7, §9.1
 *
 * Fired once per applied extension — never for a rejected one, never for a
 * double-submit that was absorbed. The grant keeps its code, its terms
 * acceptance and its session history; only the clock moves, so a prospect
 * does not need a new invitation.
 *
 * `basis` says which clock moved, because "added 3 days" means three different
 * things depending on where the grant was:
 *   - before_start   the trial had not begun — their sign-in length grew
 *   - from_deadline  access was still running — the existing end date moved on
 *   - from_now       access had already ended — it restarts today for the period
 *
 * The change reaches the demo host within the gate cache TTL (≤60s) — not
 * instantly. The admin UI says so on the confirmation.
 *
 * The audit row is also what the grant page reads to list "Time added", so the
 * facts a person needs to read back live in context(), not only in the payload.
 */
class DemoAccessExtended extends AbstractDomainEvent
{
    public const BASIS_BEFORE_START  = 'before_start';
    public const BASIS_FROM_DEADLINE = 'from_deadline';
    public const BASIS_FROM_NOW      = 'from_now';

    public function __construct(
        public readonly DemoAccessGrant $grant,
        public readonly int $hoursAdded,
        public readonly string $basis,
        public readonly ?string $previousExpiresAt,
        public readonly ?string $newExpiresAt,
        public readonly ?int $previousExpiryHours,
        public readonly ?int $newExpiryHours,
        public readonly int $byUserId,
        public readonly ?string $note = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function actorUserId(): ?int
    {
        return $this->byUserId;
    }

    public function subject(): ?array
    {
        return [DemoAccessGrant::class, $this->grant->getKey()];
    }

    public function context(): array
    {
        return [
            'company_name'          => $this->grant->company_name,
            'hours_added'           => $this->hoursAdded,
            'basis'                 => $this->basis,
            'previous_expires_at'   => $this->previousExpiresAt,
            'new_expires_at'        => $this->newExpiresAt,
            'previous_expiry_hours' => $this->previousExpiryHours,
            'new_expiry_hours'      => $this->newExpiryHours,
            'note'                  => $this->note,
        ];
    }
}
