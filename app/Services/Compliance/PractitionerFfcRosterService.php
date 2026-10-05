<?php

namespace App\Services\Compliance;

use App\Models\User;
use App\Models\UserDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase C/E (v3) — items (c)/(f), .ai/specs/ppra-inspection-pack.md §6.6.
 *
 * v3 replaces Phase B's AgentFfcRosterService for this module: the roster is
 * role-filtered (agent/branch_manager/admin only — Johan's ruling, 2026-09-28)
 * rather than "every non-assistant user", and the FFC certificate/expiry is
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
    /** Active users with role agent/branch_manager/admin — the complete PPRA practitioner list (item f). */
    public const ROLES = ['agent', 'branch_manager', 'admin'];

    private const AMBER_WINDOW_DAYS = 60; // matches AgentFfcRosterService's existing window

    /**
     * @return Collection<int, array{id:int,name:string,role:string,designation:?string,ffc_number:?string,ffc:array}>
     */
    public function rosterFor(int $agencyId): Collection
    {
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereIn('role', self::ROLES)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->buildRoster($users);
    }

    /**
     * Everyone who can hold an FFC, for the Confirmation of Employment letter's
     * agent picker (.ai/specs/ppra-ffc-employment-letter.md §16).
     *
     * rosterFor()'s role whitelist is Johan's 2026-09-28 ruling for the
     * Inspection Pack and is deliberately left alone. For the letter it
     * silently dropped any practitioner whose role is not agent/BM/admin —
     * e.g. an office_admin who is a Candidate Property Practitioner with her
     * own FFC number. A role is a poor proxy for "holds an FFC", so a user
     * qualifies here by EITHER being in a practitioner role OR having an FFC
     * number on file (covers office_admin and any agency-defined custom role).
     * Assistants are never practitioners (AT-267 §10) and are always excluded.
     */
    public function letterCandidatesFor(int $agencyId): Collection
    {
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where('is_assistant', false)
            ->where('role', '!=', 'assistant')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereIn('role', self::ROLES)
                    ->orWhere(fn ($has) => $has->whereNotNull('ffc_number')->where('ffc_number', '!=', ''));
            })
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
