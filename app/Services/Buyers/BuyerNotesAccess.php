<?php

namespace App\Services\Buyers;

use App\Models\Contact;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Who may READ the notes recorded on a buyer — Johan, 2026-10-07: "role manager setting … own / branch /
 * agency … agency decides how they want it to show".
 *
 * ONE rule, used by every surface that shows a buyer's notes read-only (the Intelligence tab's Buyer Interest
 * Signals and Core Matches): the Role Manager data scope on `buyer_notes.view`, read through the standard
 * PermissionService::getDataScope($user, 'buyer_notes') path — no parallel mechanism.
 *
 *   own     buyers whose PRIMARY agent (contacts.agent_id) is the viewer (an assistant: their agent's, via
 *           User::dataIdentityIds(), exactly as every other 'own' resolves for assistants)
 *   branch  buyers whose primary agent is in the viewer's branch (a buyer with no primary agent falls back
 *           to the contact's own branch)
 *   all     any buyer in the viewer's agency (AgencyScope — another agency is never reachable)
 *
 * It deliberately does NOT go through ContactScope: the contact's own visibility rule decides who may open
 * the contact; this setting decides who may read its notes (the two can differ, by the agency's choice).
 * View only — nothing here writes. Never used by the seller's public live link.
 */
class BuyerNotesAccess
{
    public const KEY = 'buyer_notes.view';

    /** The viewer's effective scope: 'own' | 'branch' | 'all', or null = may not read buyer notes at all. */
    public static function scopeFor(?User $user): ?string
    {
        if (! $user || ! $user->hasPermission(self::KEY)) {
            return null;
        }

        $scope = PermissionService::getDataScope($user, 'buyer_notes');

        return in_array($scope, ['own', 'branch', 'all'], true) ? $scope : null;
    }

    /**
     * Of these contact ids, the ones whose notes the viewer may read.
     *
     * @param  iterable<int|string>  $contactIds
     * @return array<int,int>
     */
    public function visibleContactIds(?User $user, iterable $contactIds): array
    {
        $ids = collect($contactIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $scope = self::scopeFor($user);
        if ($ids === [] || $scope === null) {
            return [];
        }

        // AgencyScope stays on (another agency's buyer is unreachable); ContactScope is the contact's
        // own visibility rule and is replaced by this setting for NOTES only.
        $query = Contact::query()->withoutGlobalScope(ContactScope::class)->whereIn('contacts.id', $ids);

        if ($scope === 'own') {
            $query->whereIn('contacts.agent_id', $user->dataIdentityIds());
        } elseif ($scope === 'branch') {
            $branchId = $user->effectiveBranchId() ?? $user->branch_id;
            if (! $branchId) {
                return [];
            }
            $query->where(function ($q) use ($branchId) {
                $q->whereIn('contacts.agent_id', fn ($sub) => $sub->select('id')->from('users')->where('branch_id', $branchId))
                  ->orWhere(fn ($q2) => $q2->whereNull('contacts.agent_id')->where('contacts.branch_id', $branchId));
            });
        }

        return $query->pluck('contacts.id')->map(fn ($id) => (int) $id)->all();
    }

    public function canView(?User $user, int $contactId): bool
    {
        return $this->visibleContactIds($user, [$contactId]) !== [];
    }
}
