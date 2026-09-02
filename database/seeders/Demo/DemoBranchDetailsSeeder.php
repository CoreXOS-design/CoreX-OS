<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Configuration sweep (2026-09-02, webinar prep, Johan) — all 3 demo
 * branches (Margate, Shelly Beach, Port Shepstone) had only `name`/`code`
 * populated; every contact field (address, phone, email, fax, reg/vat/ffc
 * overrides) was NULL. The CDS letterhead component resolves branch-first,
 * agency-fallback per field (company-header.blade.php) — with the agency's
 * own address/phone/email also null before DemoAgencyBrandingSeeder, every
 * generated mandate/OTP for any branch-assigned agent showed blank contact
 * lines. DemoAgencyBrandingSeeder now backstops the agency-level fallback;
 * this seeder gives each branch its OWN realistic local details, matching
 * the "3 branches, each independently staffed and contactable" story a
 * multi-branch demo needs to actually tell.
 *
 * Town names reuse the existing KZN South Coast gazetteer
 * (database/seeders/data/kzn_south_coast_suburbs.php) so addresses read as
 * consistent with the rest of the demo dataset.
 *
 * IDEMPOTENT BY CONSTRUCTION — keyed by branch name, every field only set
 * when currently null/empty; reg_no/vat_no/ffc_no are deliberately left
 * null so they continue to inherit the agency's own values (one registered
 * entity, three trading addresses — the realistic shape for a single-agency
 * multi-branch setup, not three separate legal entities).
 */
class DemoBranchDetailsSeeder
{
    private const BRANCH_DETAILS = [
        'Margate' => [
            'address'       => '12 Marine Drive, Margate, KwaZulu-Natal, 4275',
            'phone'         => '039 312 1000',
            'phone_label'   => 'Office',
            'email'         => 'margate@corexdemorealty.co.za',
        ],
        'Shelly Beach' => [
            'address'       => 'Shop 4, Shelly Centre, Shelly Beach, KwaZulu-Natal, 4265',
            'phone'         => '039 315 2000',
            'phone_label'   => 'Office',
            'email'         => 'shellybeach@corexdemorealty.co.za',
        ],
        'Port Shepstone' => [
            'address'       => '45 Nelson Mandela Drive, Port Shepstone, KwaZulu-Natal, 4240',
            'phone'         => '039 682 3000',
            'phone_label'   => 'Office',
            'email'         => 'portshepstone@corexdemorealty.co.za',
        ],
    ];

    /** @return array{updated:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $branches = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->get(['id', 'name', 'address', 'phone', 'email', 'phone_label']);
        if ($branches->isEmpty()) {
            return ['updated' => 0, 'note' => 'Skipped — agency has no branches.'];
        }

        $updated = 0;
        foreach ($branches as $branch) {
            $desired = self::BRANCH_DETAILS[$branch->name] ?? null;
            if (!$desired) {
                continue; // an unrecognised branch name — don't guess fictional details for it
            }

            $update = [];
            foreach ($desired as $field => $value) {
                $current = $branch->{$field} ?? null;
                if ($current === null || trim((string) $current) === '') {
                    $update[$field] = $value;
                }
            }

            if (!empty($update)) {
                $update['updated_at'] = now();
                DB::table('branches')->where('id', $branch->id)->update($update);
                $updated++;
            }
        }

        $note = "Branch details: {$updated}/{$branches->count()} branches updated with address/phone/email.";

        return ['updated' => $updated, 'note' => $note];
    }
}
