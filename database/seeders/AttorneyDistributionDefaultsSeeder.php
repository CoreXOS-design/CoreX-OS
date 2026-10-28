<?php

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Models\DocumentType;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/attorney-portal.md §7.4 (R5) - sensible distribution-matrix defaults for the three attorney roles, so the
 * attorney portal (and the agent's "email the attorney" button, which reads the same matrix) has something to offer from day one.
 *
 * A default is added ONLY where the agency has NEVER had a rule for that exact (document type, party role) at type level,
 * soft-deleted ones included - so an agency that deliberately removed a document from an attorney is never overridden by a
 * later deploy. Idempotent. Edit the table below to change the shipped defaults; agencies edit their own matrix afterwards.
 */
class AttorneyDistributionDefaultsSeeder extends Seeder implements SyncableReferenceSeeder
{
    /** party role => document-type slugs. FOR JOHAN'S SIGN-OFF (spec 7.4). */
    public const DEFAULTS = [
        'transfer_attorney' => ['otp', 'sale_agreement', 'mandate', 'fica', 'ids', 'por', 'rates_taxes', 'levy_statement'],
        'bond_attorney'     => ['otp', 'sale_agreement', 'fica', 'ids', 'por', 'bond_grant'],
        'conveyancer'       => ['otp', 'sale_agreement', 'mandate', 'fica', 'ids', 'por', 'rates_taxes', 'levy_statement'],
    ];

    public function run(): void
    {
        $this->ensureBondGrantType();

        $typeIds = DocumentType::pluck('id', 'slug');
        $added   = 0;

        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            foreach (self::DEFAULTS as $role => $slugs) {
                foreach ($slugs as $slug) {
                    $typeId = $typeIds[$slug] ?? null;
                    if (! $typeId) {
                        continue;
                    }
                    $everHad = DealStageDocumentRule::withTrashed()->withoutGlobalScope(AgencyScope::class)
                        ->where('agency_id', $agencyId)->whereNull('pipeline_step_id')
                        ->where('document_type_id', $typeId)->where('party_role', $role)->exists();
                    if ($everHad) {
                        continue;
                    }
                    DealStageDocumentRule::create([
                        'agency_id'          => $agencyId,
                        'pipeline_step_id'   => null,
                        'document_type_id'   => $typeId,
                        'party_role'         => $role,
                        'delivery_mode'      => DealStageDocumentRule::MODE_SECURE_LINK,
                        'auto_on_stage_tick' => false,
                        'is_active'          => true,
                    ]);
                    $added++;
                }
            }
        }

        $this->command?->info("AttorneyDistributionDefaultsSeeder - {$added} default rule(s) added.");
    }

    /** "Bond Grant" - the bank's grant letter. Global type; created here too because seeders run in name order. */
    private function ensureBondGrantType(): void
    {
        if (DocumentType::withTrashed()->where('slug', 'bond_grant')->exists()) {
            return;
        }
        DocumentType::create([
            'slug' => 'bond_grant', 'label' => 'Bond Grant', 'sort_order' => 36, 'is_active' => true,
            'grouping' => 'shared', 'contact_roles' => null, 'fica_slot' => 'none', 'buyer_pack_eligible' => false,
        ]);
    }
}
