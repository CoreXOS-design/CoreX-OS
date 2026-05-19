<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * "Seller Onboarding" web pack — bundles, for the e-sign wizard:
 *   1. Marketing Permission Esign  (MarketingPermissionEsignSeeder — CDS)
 *   2. Sales Mandatory Disclosure  (SalesMandatoryDisclosureSeeder)
 *
 * FICA is intentionally NOT a pack item — it stays the per-recipient
 * "FICA verification required" toggle at wizard Step 6; bundling docs
 * in a web pack does not touch that toggle.
 *
 * web_packs.created_by is a NOT-NULL FK→users, so this must run AFTER
 * users exist (wired into DemoDataSeeder after Stage 1). Idempotent:
 * pack keyed by (agency_id,name); items keyed by (web_pack_id,template_id).
 */
class SellerOnboardingPackSeeder extends Seeder
{
    public const PACK_NAME = 'Seller Onboarding';

    public function run(): void
    {
        $agencyId = 1;

        $createdBy = DB::table('users')->where('agency_id', $agencyId)
                ->whereIn('role', ['admin', 'super_admin'])->whereNull('deleted_at')->value('id')
            ?? DB::table('users')->where('agency_id', $agencyId)->whereNull('deleted_at')->value('id')
            ?? DB::table('users')->whereNull('deleted_at')->value('id');

        if (! $createdBy) {
            throw new \RuntimeException('SellerOnboardingPackSeeder needs ≥1 user (web_packs.created_by is NOT NULL).');
        }

        // The working CDS Marketing Permission (Johan's builder template),
        // NOT the old blade-based V6. Match the active, non-deleted row.
        $marketingId = DB::table('docuperfect_templates')
            ->where('name', MarketingPermissionEsignSeeder::TEMPLATE_NAME)
            ->where('template_type', 'cds')
            ->whereNull('deleted_at')->value('id');
        // Sale-context disclosure (NOT the letting one — a seller discloses
        // to a purchaser, PPA s70 / Reg 36).
        $disclosureId = DB::table('docuperfect_templates')
            ->where('name', SalesMandatoryDisclosureSeeder::TEMPLATE_NAME)->value('id');

        if (! $marketingId || ! $disclosureId) {
            throw new \RuntimeException(
                'SellerOnboardingPackSeeder needs both templates seeded first '
                . '(Marketing Permission Esign + Sales Mandatory Disclosure).'
            );
        }

        // updateOrInsert applies its values on BOTH insert and update and
        // never auto-sets created_at (raw query builder, no Eloquent
        // timestamps) — a freshly-seeded pack ended up with created_at NULL
        // and crashed the /web-packs list. Set created_at only on insert.
        $values = [
            'created_by'  => $createdBy,
            'description' => 'Marketing Permission + Mandatory Disclosure for a new seller. FICA via the per-recipient toggle.',
            'updated_at'  => now(),
        ];
        $existing = DB::table('web_packs')
            ->where('agency_id', $agencyId)->where('name', self::PACK_NAME)->first();
        if ($existing) {
            DB::table('web_packs')->where('id', $existing->id)->update($values);
            $packId = $existing->id;
        } else {
            $packId = DB::table('web_packs')->insertGetId(
                $values + ['agency_id' => $agencyId, 'name' => self::PACK_NAME, 'created_at' => now()]
            );
        }

        $items = [
            ['template_id' => $marketingId,  'sort_order' => 0,  'slot_label' => 'Marketing Permission Esign'],
            ['template_id' => $disclosureId, 'sort_order' => 10, 'slot_label' => 'Sales Mandatory Disclosure'],
        ];

        foreach ($items as $item) {
            DB::table('web_pack_items')->updateOrInsert(
                ['web_pack_id' => $packId, 'template_id' => $item['template_id']],
                [
                    'sort_order' => $item['sort_order'],
                    'slot_type'  => 'required',
                    'slot_group' => null,
                    'slot_label' => $item['slot_label'],
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]
            );
        }
    }
}
