<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use App\Contracts\SyncableReferenceSeeder;
use Illuminate\Database\Seeder;

/**
 * Company-document type cards (FFC / Bank Confirmation / BEE / CIPC / VAT) for the
 * Compliance → Agency Documents card grid.
 *
 * Registered in deploy:sync-reference-data (AT-162 class): these are reference rows
 * migrations do NOT carry, and seeders never run on a git-pull deploy — which is
 * exactly why a fresh DB (the June cutover) landed with an EMPTY card grid and the
 * agency's uploaded company docs had nowhere to attach.
 *
 * IDEMPOTENT + SAFE: firstOrCreate per (agency, slug) — creates missing cards, and
 * NEVER overwrites an agency's own tuning (renewal_days / required / is_active). A
 * card an agency edited or de-activated stays as they left it; only genuinely
 * missing cards are (re)created. Runs for every agency (multi-tenant), not just HFC.
 */
class AgencyDocumentTypeConfigSeeder extends Seeder implements SyncableReferenceSeeder
{
    /** The canonical default card set every agency starts with. */
    private function defaults(): array
    {
        return [
            ['name' => 'FFC Certificate',              'slug' => 'ffc_certificate',    'has_expiry' => true,  'renewal_days' => 60,   'required' => true,  'sort_order' => 1],
            ['name' => 'Bank Confirmation Letter',     'slug' => 'bank_confirmation',  'has_expiry' => true,  'renewal_days' => 14,   'required' => true,  'sort_order' => 2],
            ['name' => 'BEE Certificate',              'slug' => 'bee_certificate',    'satisfies_group' => 'bee', 'has_expiry' => true,  'renewal_days' => 30,   'required' => false, 'sort_order' => 3],
            ['name' => 'Company Registration (CIPC)',  'slug' => 'cipc_registration',  'has_expiry' => false, 'renewal_days' => null, 'required' => true,  'sort_order' => 4],
            ['name' => 'VAT Registration Certificate', 'slug' => 'vat_certificate',    'has_expiry' => false, 'renewal_days' => null, 'required' => false, 'sort_order' => 5],
            // PPRA Inspection Pack (checklist items h / e) — mirrors migration 2026_09_28_130100 exactly.
            ['name' => 'BEE Sworn Affidavit',          'slug' => 'bee_affidavit',      'satisfies_group' => 'bee', 'description' => 'Alternative to a BEE certificate — a sworn affidavit is acceptable where the agency holds no formal certificate.', 'has_expiry' => true, 'renewal_days' => 365, 'required' => false, 'sort_order' => 6],
            ['name' => 'Trial Balance (latest)',       'slug' => 'trial_balance',      'satisfies_group' => null,  'description' => 'The agency\'s latest trial balance, provided by its accountant. CoreX does not keep trust accounting records — this is a manual upload.', 'has_expiry' => true, 'renewal_days' => 90, 'required' => false, 'sort_order' => 7],
        ];
    }

    public function run(): void
    {
        // withoutGlobalScopes: a console/deploy context has no agency, and we seed for
        // ALL agencies deliberately — every agency needs the same statutory card set.
        $agencyIds = Agency::withoutGlobalScopes()->pluck('id');

        foreach ($agencyIds as $agencyId) {
            foreach ($this->defaults() as $card) {
                AgencyDocumentTypeConfig::withoutGlobalScopes()->firstOrCreate(
                    ['agency_id' => $agencyId, 'slug' => $card['slug']],
                    array_merge($card, ['agency_id' => $agencyId, 'is_active' => true]),
                );
            }

            // Existing bee_certificate cards join the 'bee' alternatives group
            // (only where it is still unset — never overwrites an agency's own value).
            AgencyDocumentTypeConfig::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('slug', 'bee_certificate')
                ->whereNull('satisfies_group')
                ->update(['satisfies_group' => 'bee']);
        }
    }
}
