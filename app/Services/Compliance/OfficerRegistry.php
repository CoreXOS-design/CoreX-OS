<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\OfficerAppointment;
use App\Models\User;
use App\Services\Docuperfect\EsignApprovalService;
use App\Services\PermissionService;
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

        $this->assertCanServeAsCo($user, $module, $agencyId);
        if ($module === OfficerAppointment::MODULE_ESIGN && $this->esignRouteIsRoCo($agencyId)) {
            $future = $this->activeRos($agencyId, $module)->pluck('user_id')->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === $userId)->push($userId)->all();
            $this->assertFullStatusOfficerRemains($agencyId, $future, 'co_user_id');
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

        if ($module === OfficerAppointment::MODULE_WHISTLEBLOW) {
            $this->assertWhistleblowRosMayDecide($agencyId, $newIds);
        }
        if ($module === OfficerAppointment::MODULE_ESIGN && $this->esignRouteIsRoCo($agencyId)) {
            $future = $co && $co->user_id ? array_merge([(int) $co->user_id], $newIds) : $newIds;
            $this->assertFullStatusOfficerRemains($agencyId, $future, 'ro_user_ids');
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

        if ($route === self::ESIGN_ROUTE_RO_CO) {
            $co = $this->currentCo($agencyId, OfficerAppointment::MODULE_ESIGN);
            if (! $co) {
                throw ValidationException::withMessages([
                    'esign_approval_route' => 'Appoint an e-sign Compliance Officer before switching to the Reporting Officer route — the route cannot run without one.',
                ]);
            }

            // The CO appointed before this rule existed must still be able to reach every document.
            $coUser = $co->user_id ? User::withoutGlobalScopes()->find($co->user_id) : null;
            if ($coUser) {
                $this->assertCanServeAsCo($coUser, OfficerAppointment::MODULE_ESIGN, $agencyId, 'esign_approval_route');
            }

            // A candidate's document can only be authorised by a FULL-STATUS officer on this route
            // (ruling 10) — switching on with none would strand every candidate.
            $officerIds = $this->officers($agencyId, OfficerAppointment::MODULE_ESIGN)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->assertFullStatusOfficerRemains($agencyId, $officerIds, 'esign_approval_route');
        }

        Agency::withoutGlobalScopes()->whereKey($agencyId)->update(['esign_approval_route' => $route]);
    }

    // ── Guards (prevent, never let an agency strand itself — ruling 4 / BUILD_STANDARD §2) ──

    /**
     * A Compliance Officer must be able to REACH everything they are the officer for: every held
     * document (e-sign) or every report (compliance reporting) in the agency, and, for reports, be
     * allowed to decide them at all. Otherwise a document or report can be held with nobody able to
     * act on it — the queue, the badge, the toast and the notification all read the same scope.
     */
    public function assertCanServeAsCo(User $user, string $module, int $agencyId, string $field = 'co_user_id'): void
    {
        $name = $user->name ?: 'That person';

        if ($module === OfficerAppointment::MODULE_ESIGN) {
            if (! $user->isOwnerRole() && PermissionService::getDataScope($user, EsignApprovalService::SCOPE_MODULE) !== 'all') {
                throw ValidationException::withMessages([$field =>
                    "{$name} does not see every e-sign document in the agency, so a held document from another branch or agent would never reach them. "
                    . 'The e-sign Compliance Officer must see the whole agency — appoint an administrator, or give their role agency-wide access to Documents › Approvals.',
                ]);
            }

            return;
        }

        if (! $user->hasPermission('compliance.whistleblow.approve')) {
            throw ValidationException::withMessages([$field =>
                "{$name}'s role cannot approve or reject compliance reports. Give the role the \"Approve / Reject Complaints\" permission first, or appoint someone whose role already has it.",
            ]);
        }

        $seesAll = $user->isOwnerRole()
            || PermissionService::userHasExplicitPermission($user, 'compliance.whistleblow.view_all_agency')
            || PermissionService::getDataScope($user, 'compliance.whistleblow') === 'all';
        if (! $seesAll) {
            throw ValidationException::withMessages([$field =>
                "{$name} would not see every compliance report in the agency, so a report filed elsewhere would sit with nobody to decide it. "
                . 'Appoint an administrator, or tick "View All Agency Complaints" for their role.',
            ]);
        }
    }

    /** Every compliance-reporting RO must be allowed to decide, or the RO appointment means nothing. */
    private function assertWhistleblowRosMayDecide(int $agencyId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $cannot = User::withoutGlobalScopes()->whereIn('id', $userIds)->where('agency_id', $agencyId)->get()
            ->reject(fn (User $u) => $u->hasPermission('compliance.whistleblow.approve'))
            ->pluck('name');

        if ($cannot->isNotEmpty()) {
            throw ValidationException::withMessages(['ro_user_ids' =>
                'These people cannot decide compliance reports until their role has the "Approve / Reject Complaints" permission: '
                . $cannot->implode(', ') . '.',
            ]);
        }
    }

    /**
     * On the RO / CO route the candidate authoriser pool is the FULL-STATUS e-sign officers
     * (ruling 10). Any change to the officer set that would leave none is refused.
     */
    private function assertFullStatusOfficerRemains(int $agencyId, array $officerUserIds, string $field): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $officerUserIds))));
        if ($ids !== []) {
            $candidateService = app(\App\Services\CandidatePractitionerService::class);
            $officers = User::withoutGlobalScopes()->whereIn('id', $ids)->where('agency_id', $agencyId)->get();
            if ($officers->contains(fn (User $u) => $candidateService->isFullStatus($u))) {
                return;
            }
        }

        throw ValidationException::withMessages([$field =>
            'No full-status Property Practitioner would be left among the e-sign officers, so a candidate\'s document could never be authorised. '
            . 'Keep at least one Reporting Officer (or the Compliance Officer) who is a full-status practitioner.',
        ]);
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
