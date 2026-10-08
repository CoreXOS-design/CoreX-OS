<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\User;
use App\Services\Compliance\FicaReferralService;
use Illuminate\Support\Collection;

/**
 * Request-scoped (really: render-scoped) memo for FicaSubmission::ownReviewBlockFor().
 *
 * A FICA list or contact tab asks the own-review question once per row. Every answer needs
 * the same per-agency facts about the same viewer - "is this user an appointed officer / the
 * primary CO here, and who are the other active officers" - so they are loaded ONCE per agency
 * and reused, instead of 2-3 queries per row. An instance is created by the caller for one
 * page render and thrown away with it, so nothing is cached across requests or tests
 * (appointments that change between requests are always re-read).
 */
final class FicaOwnReviewContext
{
    /** @var array<int, Collection<int, FicaOfficerAppointment>> agencyId => active appointments */
    private array $appointments = [];

    /** @var array<int, int|null> agencyId => resolved referral recipient user id */
    private array $recipients = [];

    public function __construct(private readonly User $user)
    {
    }

    public function user(): User
    {
        return $this->user;
    }

    /** @return Collection<int, FicaOfficerAppointment> every ACTIVE appointment in the agency (user_id, role). */
    public function appointments(int $agencyId): Collection
    {
        return $this->appointments[$agencyId] ??= FicaOfficerAppointment::where('agency_id', $agencyId)
            ->active()
            ->get(['id', 'agency_id', 'user_id', 'role']);
    }

    /** Same answer as User::isComplianceOfficer($agencyId). */
    public function isOfficer(int $agencyId): bool
    {
        return $this->appointments($agencyId)->contains(fn ($a) => (int) $a->user_id === (int) $this->user->id);
    }

    /** Same answer as User::isPrimaryComplianceOfficer($agencyId). */
    public function isPrimary(int $agencyId): bool
    {
        return $this->appointments($agencyId)->contains(
            fn ($a) => (int) $a->user_id === (int) $this->user->id && $a->role === FicaOfficerAppointment::ROLE_PRIMARY
        );
    }

    /** The user a referred pack is routed to (FicaReferralService::resolveRecipient), memoised per agency. */
    public function referralRecipientId(int $agencyId): ?int
    {
        if (! array_key_exists($agencyId, $this->recipients)) {
            $this->recipients[$agencyId] = app(FicaReferralService::class)->resolveRecipient($agencyId)?->id;
        }

        return $this->recipients[$agencyId];
    }
}
