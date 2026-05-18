<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * "Seller Onboarding" web pack — bundles, for the e-sign wizard:
 *   1. Marketing Permission V6        (MarketingPermissionV6Seeder)
 *   2. Letting Mandatory Disclosure V7 (WebTemplateSeeder)
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

        $v6Id = DB::table('docuperfect_templates')
            ->where('name', MarketingPermissionV6Seeder::TEMPLATE_NAME)->value('id');
        $disclosureId = DB::table('docuperfect_templates')
            ->where('name', 'Letting Mandatory Disclosure V7')->value('id');

        if (! $v6Id || ! $disclosureId) {
            throw new \RuntimeException(
                'SellerOnboardingPackSeeder needs both templates seeded first '
                . '(Marketing Permission V6 + Letting Mandatory Disclosure V7).'
            );
        }

        DB::table('web_packs')->updateOrInsert(
            ['agency_id' => $agencyId, 'name' => self::PACK_NAME],
            [
                'created_by'  => $createdBy,
                'description' => 'Marketing Permission + Mandatory Disclosure for a new seller. FICA via the per-recipient toggle.',
                'updated_at'  => now(),
            ]
        );

        $packId = DB::table('web_packs')
            ->where('agency_id', $agencyId)->where('name', self::PACK_NAME)->value('id');

        $items = [
            ['template_id' => $v6Id,         'sort_order' => 0,  'slot_label' => 'Marketing Permission V6'],
            ['template_id' => $disclosureId, 'sort_order' => 10, 'slot_label' => 'Letting Mandatory Disclosure V7'],
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
