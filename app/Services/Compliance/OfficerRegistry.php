<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\OfficerAppointment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one place that answers "who is an officer for module X in agency Y".
 *
 * Spec: .ai/specs/esign-compliance-approval-gate.md §5.1 / §6.6 / §7.
 *
 * Every read is agency-explicit (no reliance on a request-bound agency context), so it is safe
 * from queued listeners, console commands and owner accounts with no active agency.
 */
class OfficerRegistry
{
    public const ESIGN_ROUTE_FULL_STATUS = 'full_status';
    public const ESIGN_ROUTE_RO_CO       = 'ro_co';

    // ── Reads ──

    public function currentCo(?int $agencyId, string $module): ?OfficerAppointment
    {
        return OfficerAppointment::currentCo($agencyId, $module);
    }

    public function activeRos(?int $agencyId, string $module): Collection
    {
        return OfficerAppointment::activeRosFor($agencyId, $module);
    }

    /** CO + ROs as User models (active users only), CO first. */
    public function officers(?int $agencyId, string $module): Collection
    {
        if (! $agencyId) {
            return collect();
        }

        $rows = OfficerAppointment::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->forModule($module)
            ->active()
            ->whereNotNull('user_id')
            ->orderByRaw("CASE WHEN role = 'co' THEN 0 ELSE 1 END")
            ->get();

        $ids = $rows->pluck('user_id')->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        $users = User::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn ($id) => $users->get($id))->filter()->values();
    }

    public function isCo(User $user, string $module, ?int $agencyId = null): bool
    {
        $agencyId = $agencyId ?: (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return false;
        }
        $co = $this->currentCo($agencyId, $module);

        return $co !== null && (int) $co->user_id === (int) $user->id;
    }

    public function isRo(User $user, string $module, ?int $agencyId = null): bool
    {
        $agencyId = $agencyId ?: (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return false;
        }

        return OfficerAppointment::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->forModule($module)
            ->ro()
            ->active()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isOfficer(User $user, string $module, ?int $agencyId = null): bool
    {
        return $this->isCo($user, $module, $agencyId) || $this->isRo($user, $module, $agencyId);
    }

    public function esignRoute(?int $agencyId): string
    {
        if (! $agencyId) {
            return self::ESIGN_ROUTE_FULL_STATUS;
        }
        $route = (string) (Agency::withoutGlobalScopes()->whereKey($agencyId)->value('esign_approval_route') ?? '');

        return $route === self::ESIGN_ROUTE_RO_CO ? self::ESIGN_ROUTE_RO_CO : self::ESIGN_ROUTE_FULL_STATUS;
    }

    public function esignRouteIsRoCo(?int $agencyId): bool
    {
        return $this->esignRoute($agencyId) === self::ESIGN_ROUTE_RO_CO;
    }

    public function whistleblowRosMaySubmit(?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }

        return (bool) Agency::withoutGlobalScopes()->whereKey($agencyId)->value('whistleblow_ro_can_submit');
    }

    // ── Writes ──

    /**
     * Appoint (or re-confirm) the module's CO. The model auto-ends a different incumbent.
     * Re-appointing the same person is a no-op that returns the live row.
     */
    public function appointCo(int $agencyId, string $module, int $userId, ?int $appointedBy, ?string $notes = null): OfficerAppointment
    {
        $this->assertModule($module);

        $current = $this->currentCo($agencyId, $module);
        if ($current && (int) $current->user_id === $userId) {
            return $current;
        }

        $user = User::withoutGlobalScopes()->whereKey($userId)->where('agency_id', $agencyId)->first();
        if (! $user) {
            throw ValidationException::withMessages(['co_user_id' => 'That person is not a member of this agency.']);
        }

        // An RO promoted to CO stops being an RO (one role per person per module).
        OfficerAppointment::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->forModule($module)->ro()->active()
            ->where('user_id', $userId)
            ->update(['ended_on' => now()->toDateString()]);

        return OfficerAppointment::create([
            'agency_id'    => $agencyId,
            'branch_id'    => $user->branch_id,
            'user_id'      => $user->id,
            'module'       => $module,
            'role'         => OfficerAppointment::ROLE_CO,
            'full_name'    => $user->name,
            'email'        => $user->email,
            'appointed_on' => now()->toDateString(),
            'appointed_by' => $appointedBy,
            'notes'        => $notes,
        ]);
    }

    /**
     * End the module's CO with no replacement. Refused for e-sign while the RO / CO route is on
     * (ruling 4 — the route never runs without a CO).
     */
    public function endCo(int $agencyId, string $module): void
    {
        $this->assertModule($module);

        if ($module === OfficerAppointment::MODULE_ESIGN && $this->esignRouteIsRoCo($agencyId)) {
            throw ValidationException::withMessages([
                'co_user_id' => 'The e-sign approval route needs a Compliance Officer. Appoint a replacement first, or switch the route back to "full-status practitioners send without approval".',
            ]);
        }

        $current = $this->currentCo($agencyId, $module);
        $current?->update(['ended_on' => now()->toDateString()]);
    }

    /** Diff-set the RO list: ends removed, creates new, never deletes. */
    public function saveRos(int $agencyId, string $module, array $userIds, ?int $appointedBy): void
    {
        $this->assertModule($module);

        $newIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        // The CO can never also be an RO — drop them silently from the list.
        $co = $this->currentCo($agencyId, $module);
        if ($co) {
            $newIds = array_values(array_filter($newIds, fn ($id) => $id !== (int) $co->user_id));
        }

        $current = OfficerAppointment::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->forModule($module)->ro()->active()->get();

        foreach ($current as $row) {
            if ($row->user_id && ! in_array((int) $row->user_id, $newIds, true)) {
                $row->update(['ended_on' => now()->toDateString()]);
            }
        }

        $existing = $current->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();
        foreach ($newIds as $userId) {
            if (in_array($userId, $existing, true)) {
                continue;
            }
            $user = User::withoutGlobalScopes()->whereKey($userId)->where('agency_id', $agencyId)->first();
            if (! $user) {
                continue; // agency-scoped validation already refused foreign ids; belt and braces
            }
            OfficerAppointment::create([
                'agency_id'    => $agencyId,
                'branch_id'    => $user->branch_id,
                'user_id'      => $user->id,
                'module'       => $module,
                'role'         => OfficerAppointment::ROLE_RO,
                'full_name'    => $user->name,
                'email'        => $user->email,
                'appointed_on' => now()->toDateString(),
                'appointed_by' => $appointedBy,
            ]);
        }
    }

    /**
     * Switch the e-sign route. 'ro_co' is refused without an e-sign CO (ruling 4 — prevent, never
     * let an agency strand itself).
     */
    public function setEsignRoute(int $agencyId, string $route): void
    {
        if (! in_array($route, [self::ESIGN_ROUTE_FULL_STATUS, self::ESIGN_ROUTE_RO_CO], true)) {
            throw ValidationException::withMessages(['esign_approval_route' => 'Choose one of the two approval routes.']);
        }

        if ($route === self::ESIGN_ROUTE_RO_CO && ! $this->currentCo($agencyId, OfficerAppointment::MODULE_ESIGN)) {
            throw ValidationException::withMessages([
                'esign_approval_route' => 'Appoint an e-sign Compliance Officer before switching to the Reporting Officer route — the route cannot run without one.',
            ]);
        }

        Agency::withoutGlobalScopes()->whereKey($agencyId)->update(['esign_approval_route' => $route]);
    }

    public function setWhistleblowRosMaySubmit(int $agencyId, bool $allowed): void
    {
        Agency::withoutGlobalScopes()->whereKey($agencyId)->update(['whistleblow_ro_can_submit' => $allowed]);
    }

    private function assertModule(string $module): void
    {
        if (! in_array($module, OfficerAppointment::MODULES, true)) {
            throw new \InvalidArgumentException("Unknown officer module '{$module}'.");
        }
    }
}
