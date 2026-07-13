<?php

namespace App\Services\Proforma;

use App\Models\Proforma\AgencyProformaSettings;
use Illuminate\Support\Facades\DB;

/**
 * Atomic per-agency sequence allocation. A void never frees a number for reuse;
 * two concurrent generations always get distinct consecutive numbers because the
 * settings row is locked FOR UPDATE inside the caller's transaction.
 */
class ProformaNumberService
{
    /**
     * Allocate the next sequence for an agency. MUST run inside a DB transaction
     * (the generation service opens one). Returns [sequence_no, formatted_number].
     *
     * @return array{0:int,1:string}
     */
    public function allocate(int $agencyId): array
    {
        // Ensure a settings row exists (default start number = 1).
        AgencyProformaSettings::forAgency($agencyId);

        // Lock the row so concurrent allocations serialise.
        $row = DB::table('agency_proforma_settings')
            ->where('agency_id', $agencyId)
            ->lockForUpdate()
            ->first();

        $sequence = (int) $row->next_number;
        $number   = $row->number_prefix . str_pad((string) $sequence, (int) $row->number_padding, '0', STR_PAD_LEFT);

        DB::table('agency_proforma_settings')
            ->where('agency_id', $agencyId)
            ->update(['next_number' => $sequence + 1, 'updated_at' => now()]);

        return [$sequence, $number];
    }
}
