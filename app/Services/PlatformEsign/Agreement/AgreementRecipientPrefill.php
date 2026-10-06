<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\Agency;
use App\Models\User;

/**
 * Who the agency's Subscription Agreement is most likely addressed to (spec §11.13): the agency's principal practitioner,
 * falling back to the agency's own email and phone. Only pre-fills the send form — the owner can correct every value.
 */
class AgreementRecipientPrefill
{
    /** @param int[] $agencyIds @return array<int,array{name:string,email:string,cell:string}> */
    public static function forAgencies(array $agencyIds): array
    {
        if (!$agencyIds) {
            return [];
        }
        $principals = User::withoutGlobalScopes()->whereIn('agency_id', $agencyIds)->where('is_active', true)->where('is_principal_practitioner', true)
            ->orderBy('id')->get(['id', 'agency_id', 'name', 'email', 'cell', 'phone'])->groupBy('agency_id');
        $out = [];
        foreach (Agency::withoutGlobalScopes()->whereIn('id', $agencyIds)->get(['id', 'name', 'email', 'phone']) as $a) {
            $p = $principals->get($a->id)?->first();
            $out[$a->id] = [
                'name' => trim((string) ($p?->name ?? '')),
                'email' => trim((string) ($p?->email ?: $a->email)),
                'cell' => trim((string) ($p?->cell ?: $p?->phone ?: $a->phone)),
            ];
        }

        return $out;
    }
}
