<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-227 defaults — the Johan-locked examples of the distribution matrix, per agency,
 * reproducible via config (agency-editable afterwards). Type-level (null-stage) rules.
 *
 *   Signed OTP        → seller, buyer, bond_originator, transfer_attorney
 *   ID                → bond_originator, transfer_attorney
 *   Proof of residence→ bond_originator, transfer_attorney
 *
 * Idempotent (setTypeDistribution restores/soft-deletes to match). Only agencies that
 * have the doc types are touched; missing slugs are skipped.
 */
class DocumentDistributionMatrixSeeder extends Seeder
{
    /** slug => party roles that receive it by default. */
    private const DEFAULTS = [
        'otp' => ['seller', 'buyer', 'bond_originator', 'transfer_attorney'],
        'ids' => ['bond_originator', 'transfer_attorney'],
        'por' => ['bond_originator', 'transfer_attorney'],
    ];

    public function run(): void
    {
        $matrix   = app(DocumentDistributionMatrix::class);
        $typeIds  = DocumentType::pluck('id', 'slug');   // shared, global doc types
        $agencies = DB::table('agencies')->pluck('id');

        foreach ($agencies as $agencyId) {
            foreach (self::DEFAULTS as $slug => $roles) {
                $typeId = $typeIds[$slug] ?? null;
                if (! $typeId) {
                    continue;
                }
                // Only seed a default when the agency has not configured this type yet
                // (preserve agency customisation).
                if (! $matrix->isDistributable((int) $agencyId, (int) $typeId)) {
                    $matrix->setTypeDistribution((int) $agencyId, (int) $typeId, $roles);
                }
            }
        }

        $this->command?->info('DocumentDistributionMatrixSeeder — locked-example defaults applied per agency.');
    }
}
