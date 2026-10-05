<?php

namespace App\Services\Compliance;

use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase C/E (v3) — items (c)/(f), .ai/specs/ppra-inspection-pack.md §6.6.
 *
 * v3 replaces Phase B's AgentFfcRosterService for this module: the roster is
 * role-filtered rather than "every non-assistant user" (Johan's ruling, 2026-09-28 —
 * which roles qualify became a Role Manager setting on 2026-10-05, see ROSTER_PERMISSION), and the FFC certificate/expiry is
 * sourced from UserDocument (document_type=ffc_certificate), not the legacy
 * users.ffc_certificate_path / AgentApplication.ffc_expiry columns. The FFC
 * NUMBER itself still lives on users.ffc_number — that field is distinct from
 * the certificate file and is unaffected by this ruling.
 *
 * Phase E (2026-09-28, Johan) — item (c)'s principal roster now sources from
 * the real `users.is_principal_practitioner` flag (admin-edited, audit-logged
 * via AgentPrincipalPractitionerFlagChanged), replacing the earlier
 * `designation LIKE '%Principal%'` heuristic. principalsFor() queries the
 * flag directly rather than filtering rosterFor()'s role-restricted list,
 * since the flag is agency-scoped and independent of role.
 *
 * AgentFfcRosterService is left untouched — it still backs /compliance/agents,
 * which is a different screen with a different roster definition (all active
 * non-assistant staff, legacy FFC columns) that this spec does not touch.
 */
class PractitionerFfcRosterService
{
    /**
     * Role Manager permission that puts a role's users on the Inspection Pack staff roster (item f).
     * Per role, per agency — Johan, 2026-10-05: never per user, never a hardcoded role list.
     */
    public const ROSTER_PERMISSION = 'ppra_inspection_pack.roster';

    /**
     * Role Manager permission that makes a role's users eligible for a PPRA Employment Letter — listed in
     * the admin New-letter picker and shown the letter tab in their own My Portal. Per role, per agency —
     * Johan, 2026-10-05: never a hardcoded role list or FFC-number rule.
     */
    public const LETTER_PERMISSION = 'ppra_employment_letters.receive';

    private const AMBER_WINDOW_DAYS = 60; // matches AgentFfcRosterService's existing window

    /**
     * @return Collection<int, array{id:int,name:string,role:string,designation:?string,ffc_number:?string,ffc:array}>
     */
    public function rosterFor(int $agencyId): Collection
    {
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereIn('role', $this->rosterRolesFor($agencyId))
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->buildRoster($users);
    }

    /**
     * The role names this agency has ticked for the staff roster in Role Manager. Reads this
     * agency's own grant rows directly (soft-deleted = unticked), not userHasPermission(): owner
     * roles bypass that check and would otherwise appear on every roster.
     *
     * @return string[]
     */
    public function rosterRolesFor(int $agencyId): array
    {
        return RolePermission::where('agency_id', $agencyId)
            ->where('permission_key', self::ROSTER_PERMISSION)
            ->pluck('role')
            ->all();
    }

    /**
     * The role names this agency has ticked for "Can receive PPRA employment letter" in Role Manager.
     * Same direct-row read as rosterRolesFor() (soft-deleted = unticked; no owner bypass).
     *
     * @return string[]
     */
    public function letterRolesFor(int $agencyId): array
    {
        return RolePermission::where('agency_id', $agencyId)
            ->where('permission_key', self::LETTER_PERMISSION)
            ->pluck('role')
            ->all();
    }

    /**
     * Everyone who can receive a Confirmation of Employment letter, for the admin agent picker
     * (.ai/specs/ppra-ffc-employment-letter.md §16/§17).
     *
     * Eligibility is the agency's own Role Manager setting (LETTER_PERMISSION), not a role list or an
     * FFC-number rule. Still never an assistant (AT-267 §10), never inactive/deleted, same agency.
     * rosterFor()'s Inspection Pack roster is a separate permission and is not touched here.
     */
    public function letterCandidatesFor(int $agencyId): Collection
    {
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where('is_assistant', false)
            ->where('role', '!=', 'assistant')
            ->whereNull('deleted_at')
            ->whereIn('role', $this->letterRolesFor($agencyId))
            ->orderBy('name')
            ->get();

        return $this->buildRoster($users);
    }

    /**
     * Item (c): every active user flagged is_principal_practitioner=true
     * for this agency (Phase E, v3 — real flag, not a designation guess).
     * Independent of rosterFor()'s role restriction, since the flag itself
     * is the source of truth for "who is a principal" regardless of role.
     */
    public function principalsFor(int $agencyId): Collection
    {
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where('is_principal_practitioner', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->buildRoster($users);
    }

    private function buildRoster(Collection $users): Collection
    {
        return $users->map(fn (User $user) => [
            'id'          => $user->id,
            'name'        => $user->name,
            'role'        => $user->role,
            'designation' => $user->designation,
            'ffc_number'  => $user->ffc_number,
            'ffc'         => $this->ffcStatusFor($user),
        ])->values();
    }

    public function ffcStatusFor(User $user): array
    {
        $document = $this->currentFfcDocument($user->id);

        if (! $document) {
            return ['status' => 'red', 'label' => 'Not on file', 'expiry_date' => null, 'document' => null];
        }

        $expiryDate = $document->expiry_date; // Carbon, from the UserDocument cast

        if ($document->status === 'rejected') {
            return ['status' => 'red', 'label' => 'Rejected', 'expiry_date' => $expiryDate, 'document' => $document];
        }

        if ($document->status === 'pending') {
            return ['status' => 'amber', 'label' => 'Pending verification', 'expiry_date' => $expiryDate, 'document' => $document];
        }

        // status === 'verified' (or a legacy/other value with an expiry to judge) from here.
        if (! $expiryDate) {
            return ['status' => 'green', 'label' => 'On file (no expiry recorded)', 'expiry_date' => null, 'document' => $document];
        }

        $daysRemaining = (int) now()->diffInDays(Carbon::parse($expiryDate), false);

        if ($daysRemaining < 0 || $document->status === 'expired') {
            return ['status' => 'red', 'label' => 'Expired ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry_date' => $expiryDate, 'document' => $document];
        }

        if ($daysRemaining <= self::AMBER_WINDOW_DAYS) {
            return ['status' => 'amber', 'label' => 'Expiring ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry_date' => $expiryDate, 'document' => $document];
        }

        return ['status' => 'green', 'label' => 'Valid until ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry_date' => $expiryDate, 'document' => $document];
    }

    /** Prefer the current verified certificate; fall back to whatever's most recent otherwise. */
    private function currentFfcDocument(int $userId): ?UserDocument
    {
        $verified = UserDocument::where('user_id', $userId)
            ->where('document_type', UserDocument::DOCUMENT_TYPE_FFC_CERTIFICATE)
            ->verified()
            ->latest('created_at')
            ->first();

        return $verified ?: UserDocument::where('user_id', $userId)
            ->where('document_type', UserDocument::DOCUMENT_TYPE_FFC_CERTIFICATE)
            ->latest('created_at')
            ->first();
    }
}
