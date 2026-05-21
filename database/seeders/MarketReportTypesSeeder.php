<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * MIC Phase A2 — seed the supported report types into `market_report_types`.
 *
 * Source of truth: .ai/specs/mic-complete-spec.md §3.2.3.
 *
 * Parser FQCNs reference classes that land in Phase F — seeded now so the
 * lookup is forward-compatible. `auto_approve` follows the spec's accuracy
 * gating: CMA Info parsers are pre-flagged true (proven format); everything
 * else stays manual-review for V1 until the parser proves itself on the
 * accuracy dashboard.
 *
 * Idempotent: updateOrInsert keyed on `key`. Re-running the seeder updates
 * any drifted parser_class / expected_fields / auto_approve / display_name
 * to match this file. No model is used (the MarketReportType model lands in
 * Phase A3) — raw DB writes via the table directly.
 */
class MarketReportTypesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->rows();
        $now = now();
        $added = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $existing = DB::table('market_report_types')->where('key', $row['key'])->first();
            $payload = [
                'display_name'         => $row['display_name'],
                'parser_class'         => $row['parser_class'],
                'expected_fields_json' => json_encode($row['expected_fields']),
                'auto_approve'         => $row['auto_approve'] ? 1 : 0,
                'sample_file_path'     => null,
                'updated_at'           => $now,
            ];

            if ($existing) {
                DB::table('market_report_types')->where('id', $existing->id)->update($payload);
                $updated++;
            } else {
                DB::table('market_report_types')->insert(array_merge(
                    ['key' => $row['key'], 'created_at' => $now],
                    $payload,
                ));
                $added++;
            }
        }

        $this->command?->info("  Seeded market_report_types: {$added} added, {$updated} updated (target = " . count($rows) . " total)");
    }

    /**
     * The 11 V1 report types per spec §3.2.3.
     *
     * @return array<int, array{key:string, display_name:string, parser_class:string, auto_approve:bool, expected_fields:array<int,string>}>
     */
    private function rows(): array
    {
        return [
            [
                'key'             => 'cma_info_market_analysis',
                'display_name'    => 'CMA Info — Market Analysis',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\CmaInfoMarketAnalysisParser',
                'auto_approve'    => true,
                'expected_fields' => [
                    'subject_property.address', 'subject_property.erf',
                    'subject_property.municipal_valuation', 'subject_property.gps',
                    'cma_valuation.lower', 'cma_valuation.mid', 'cma_valuation.upper',
                    'suburb_stats.median_price', 'suburb_stats.total_sales',
                    'suburb_stats.absorption_months', 'comparable_sales[]',
                ],
            ],
            [
                'key'             => 'cma_info_median_sales_analysis',
                'display_name'    => 'CMA Info — Median Sales Analysis',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\CmaInfoMedianSalesAnalysisParser',
                'auto_approve'    => true,
                'expected_fields' => [
                    'suburb', 'year', 'no_of_sales', 'median_price',
                    'annual_change_pct', 'index_value', 'title_type',
                ],
            ],
            [
                'key'             => 'cma_info_property_valuation',
                'display_name'    => 'CMA Info — Property Valuation (Full)',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\CmaInfoPropertyValuationParser',
                'auto_approve'    => true,
                'expected_fields' => [
                    'subject_property', 'municipal_valuation', 'sale_history[]',
                    'comparative_properties[]', 'cma_value_range',
                    'comparative_municipal_valuations[]', 'comparative_accommodation[]',
                    'scheme_recent_sales[]', 'price_distribution', 'sold_properties_dom[]',
                    'for_sale_in_vicinity[]',
                ],
            ],
            [
                'key'             => 'cma_info_sectional_title_sales',
                'display_name'    => 'CMA Info — Sectional Title Sales (Radius)',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\CmaInfoSectionalTitleSalesParser',
                'auto_approve'    => true,
                'expected_fields' => [
                    'subject_property', 'radius_meters', 'price_range_min', 'price_range_max',
                    'sales[]', 'price_ranges_distribution', 'map_image',
                ],
            ],
            [
                'key'             => 'lightstone_avm',
                'display_name'    => 'Lightstone — AVM',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\LightstoneAvmParser',
                'auto_approve'    => false,
                'expected_fields' => [
                    'subject_property', 'avm_value', 'avm_confidence',
                    'value_range', 'comparable_sales[]',
                ],
            ],
            [
                'key'             => 'lightstone_suburb_report',
                'display_name'    => 'Lightstone — Suburb Report',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\LightstoneSuburbReportParser',
                'auto_approve'    => false,
                'expected_fields' => [
                    'suburb', 'median_price', 'price_movement', 'sales_volume',
                    'buyer_demographics', 'time_on_market',
                ],
            ],
            [
                'key'             => 'agent_built_cma',
                'display_name'    => 'Agent-Built CMA (Word/PDF)',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\AgentBuiltCmaParser',
                'auto_approve'    => false,
                'expected_fields' => ['free_text_extraction'],
            ],
            [
                'key'             => 'deeds_office_print',
                'display_name'    => 'Deeds Office — Property Print',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\DeedsOfficePrintParser',
                'auto_approve'    => false,
                'expected_fields' => [
                    'title_deed_number', 'registration_date', 'purchase_price',
                    'owner_names', 'previous_owner_names', 'bond_holder', 'bond_amount',
                ],
            ],
            [
                'key'             => 'ooba_bond_report',
                'display_name'    => 'ooba — Bond Originator Report',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\OobaBondReportParser',
                'auto_approve'    => false,
                'expected_fields' => [
                    'applicant_profile', 'application_outcome', 'bond_amount', 'interest_rate',
                ],
            ],
            [
                'key'             => 'betterbond_report',
                'display_name'    => 'BetterBond — Originator Report',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\BetterbondReportParser',
                'auto_approve'    => false,
                'expected_fields' => ['applicant_profile', 'application_outcome', 'bond_amount'],
            ],
            [
                'key'             => 'other',
                'display_name'    => 'Other / Unknown',
                'parser_class'    => 'App\\Services\\MarketReports\\Parsers\\GenericFallbackParser',
                'auto_approve'    => false,
                'expected_fields' => ['raw_text_only'],
            ],
        ];
    }
}
