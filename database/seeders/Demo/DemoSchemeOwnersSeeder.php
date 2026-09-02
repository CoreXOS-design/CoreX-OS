<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\MarketReports\SchemeOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3h Step 5 — synthetic scheme owners.
 *
 * Per suburb's 12 schemes:
 *   - 1 fake "Scheme Owners List" report (cma_info_scheme_owners_list)
 *   - 8-15 owner records with plausible SA names
 *   - All owners of a scheme share the building GPS (joined through
 *     market_reports.subject_scheme_name → subject_lat/lng for the
 *     map service to pick up)
 *
 * 6 suburbs × 12 schemes = 72 schemes total = 72 owners-list reports.
 *
 * Architectural call-out: scheme_owners has lat/lng columns natively (Phase 3a
 * migration added them), so we populate them directly here AND via the
 * inheriting join in MapPinService. Either path produces the right pin.
 */
final class DemoSchemeOwnersSeeder
{
    /** @return array{reports:int, owners:int} */
    public function run(int $agencyId): array
    {
        $gazetteer = require database_path('seeders/data/kzn_south_coast_suburbs.php');

        $uploader = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['agent', 'admin', 'branch_manager'])
            ->orderBy('id')
            ->value('id');
        if (!$uploader) {
            return ['reports' => 0, 'owners' => 0, 'note' => "Skipped — agency {$agencyId} has no agents."];
        }

        $ownersTypeId = DB::table('market_report_types')
            ->where('key', 'cma_info_scheme_owners_list')
            ->value('id');
        if (!$ownersTypeId) {
            return ['reports' => 0, 'owners' => 0, 'note' => 'Skipped — scheme owners type missing.'];
        }

        $reportsInserted = 0;
        $ownersInserted = 0;

        foreach ($gazetteer as $suburbKey => $suburb) {
            foreach ($suburb['schemes'] as $schemeName) {
                $schemeGps = $this->seededGps($schemeName . '|scheme', $suburb['bounds']);
                $reportDate = Carbon::now()->subDays(random_int(7, 540));

                // Create the owners-list report. subject_scheme_name is the
                // hinge field — MapPinService's join uses it.
                //
                // 2026-09-02 — this was a blind insertGetId(), so a re-run (e.g.
                // after market_report_types came back populated) duplicated all
                // 72 owners-list reports every time, even though the owners
                // themselves stayed clean via the firstOrCreate below. Natural
                // key: (agency_id, subject_scheme_name, source_suburb) — scheme
                // names are the gazetteer's own stable identity for a scheme.
                $existingReportId = DB::table('market_reports')
                    ->where('agency_id', $agencyId)
                    ->where('subject_scheme_name', $schemeName)
                    ->where('source_suburb', $suburb['name'])
                    ->whereNull('deleted_at')
                    ->value('id');

                if ($existingReportId) {
                    $reportId = $existingReportId;
                } else {
                    $uuid = (string) Str::uuid();
                    $reportId = DB::table('market_reports')->insertGetId([
                        'agency_id'           => $agencyId,
                        'uploaded_by_user_id' => $uploader,
                        'report_type_id'      => $ownersTypeId,
                        'file_path'           => 'demo/owners/' . $uuid . '.pdf',
                        'file_name'           => 'demo_owners_' . $schemeName . '_' . $uuid . '.pdf',
                        'file_hash'           => hash('sha256', 'owners:' . $uuid),
                        'source_suburb'       => $suburb['name'],
                        'source_town'         => $suburb['town'],
                        'report_date'         => $reportDate->toDateString(),
                        'parse_status'        => 'parsed',
                        'parse_completed_at'  => $reportDate,
                        'parser_version'      => 'demo_v1',
                        'raw_extracted_json'  => json_encode(['note' => 'Demo-seeded owners list']),
                        'spot_check_status'   => 'passed',
                        'subject_scheme_name' => $schemeName,
                        'subject_latitude'    => $schemeGps['lat'],
                        'subject_longitude'   => $schemeGps['lng'],
                        'is_demo'             => true,
                        'created_at'          => $reportDate,
                        'updated_at'          => $reportDate,
                    ]);
                    $reportsInserted++;
                }

                // 2026-09-02 — both random_int() calls here were non-deterministic
                // per RUN (not just per owner), so a re-run generated a different
                // owner count AND different section groupings for the "same" scheme
                // — the firstOrCreate key below (scheme+section+owner_name) could
                // never match a prior run's rows, so every re-run added a full new
                // batch alongside the old one. Seeded from scheme+suburb — same
                // scheme always gets the same count and section grouping.
                $schemeSeed = crc32($schemeName . '|' . $suburbKey . '|ownercount');
                $ownerCount = 8 + ($schemeSeed % 8); // 8-15, deterministic
                $sectionSpan = 5 + (intdiv($schemeSeed, 8) % 3); // 5-7, deterministic
                for ($i = 0; $i < $ownerCount; $i++) {
                    // Some duplicates for joint ownership — every Nth owner shares a
                    // section with the previous one (N fixed per scheme, not per run).
                    $section = (string) (intdiv($i, $sectionSpan) + 1);
                    $ownerSeed = $schemeName . '|' . $suburbKey . '|' . $i;
                    // owner_name is deterministic (seeded from scheme+suburb+index), so a
                    // second additive run without --fresh would otherwise re-generate the
                    // SAME name for the SAME (agency, scheme, section) and crash on
                    // scheme_owners' uq_scheme_owners_agency_scheme_section_owner unique
                    // key. firstOrCreate makes re-runs idempotent instead of crashing.
                    $ownerKey = [
                        'agency_id'      => $agencyId,
                        'scheme_name'    => $schemeName,
                        'section_number' => $section,
                        'owner_name'     => DemoNames::name($ownerSeed),
                    ];
                    // 2026-09-02 — belt-and-braces around firstOrCreate: a genuine
                    // unique-constraint hit here (uq_scheme_owners_agency_scheme_
                    // section_owner) on a re-run means the SELECT half missed a row
                    // the INSERT then collided with (observed once; root cause not
                    // fully chased under time pressure — this makes the outcome
                    // correct regardless: reuse the existing row, never crash the
                    // whole reset over one race).
                    try {
                        $owner = SchemeOwner::firstOrCreate($ownerKey, [
                            'market_report_id' => $reportId,
                            'extent_m2'         => random_int(60, 170),
                            'property_type'     => 'Sectional Title',
                            'latitude'          => $schemeGps['lat'],
                            'longitude'         => $schemeGps['lng'],
                            'is_demo'           => true,
                        ]);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        $owner = SchemeOwner::where($ownerKey)->first();
                    }
                    if ($owner && $owner->wasRecentlyCreated) {
                        $ownersInserted++;
                    }
                }
            }
        }

        return ['reports' => $reportsInserted, 'owners' => $ownersInserted];
    }

    private function seededGps(string $seed, array $bounds): array
    {
        $hash = crc32($seed);
        $cellX = $hash % 4;
        $cellY = intdiv($hash, 4) % 4;
        $cellWidth  = ($bounds['east']  - $bounds['west'])  / 4;
        $cellHeight = ($bounds['north'] - $bounds['south']) / 4;
        $jitterX = (($hash >> 8)  & 0xFF) / 0xFF;
        $jitterY = (($hash >> 16) & 0xFF) / 0xFF;
        $lng = $bounds['west']  + ($cellX * $cellWidth)  + ($jitterX * $cellWidth);
        $lat = $bounds['south'] + ($cellY * $cellHeight) + ($jitterY * $cellHeight);
        return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
    }
}
