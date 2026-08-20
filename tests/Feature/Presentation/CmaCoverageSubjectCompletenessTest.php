<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Property;
use App\Services\Presentations\CmaCoverageService;
use App\Services\Presentations\CompetitorStockMatchService;
use App\Support\Presentations\SubjectFieldCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-20 — Johan: the "Strong/Moderate/Thin data" badge on
 * properties/show.blade.php was computed purely from comp_count (market
 * comparable-sales abundance), completely independent of whether the
 * SUBJECT property's own beds/baths/price were set. Property 6112 on
 * staging scored "Strong data — 10 recent comparable sales" while the
 * pre-generate warning correctly flagged it as missing bedrooms, bathrooms
 * AND price — two independently-correct pieces of logic disagreeing on
 * screen. Not the missing-vs-zero trap (the badge never read those fields
 * at all) — a genuine blind spot. Fixed by folding
 * SubjectFieldCompleteness::missingSoftInputs() — the SAME function the
 * pre-generate warning uses — into CmaCoverageService::scoreForProperty(),
 * capping the badge state one tier down and merging both facts into one
 * sentence, while leaving comp_count itself untouched (it's a real,
 * useful number) and never gating generation (a warning, not a gate).
 */
final class CmaCoverageSubjectCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_rich_state_capped_to_moderate_when_subject_incomplete(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject(price: 0, beds: 0, baths: 0);
        // 6 real comps clears the default rich threshold on its own.
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        for ($i = 0; $i < 6; $i++) {
            $this->seedCompRow($agencyId, $reportId, "Comp $i", now()->subMonths(2)->toDateString(), 1_500_000 + $i * 10_000, 'testville');
        }

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame(6, $score['comp_count'], 'comp_count itself is never fudged');
        $this->assertNotSame('rich', $score['state'], 'must never read "Strong" while subject fields are missing');
        $this->assertSame('moderate', $score['state'], 'rich caps exactly one tier down, not further');
        $this->assertTrue($score['can_generate'], 'a warning, not a gate — generation stays available');
        $this->assertEqualsCanonicalizing(['bedrooms', 'bathrooms', 'price'], $score['missing_subject_inputs']);
    }

    public function test_moderate_state_capped_to_thin_when_subject_incomplete(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject(price: 0, beds: 3, baths: 2);
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        for ($i = 0; $i < 3; $i++) {
            $this->seedCompRow($agencyId, $reportId, "Comp $i", now()->subMonths(2)->toDateString(), 1_500_000, 'testville');
        }

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame('thin', $score['state']);
        $this->assertTrue($score['can_generate']);
        $this->assertSame(['price'], $score['missing_subject_inputs']);
    }

    public function test_thin_state_not_capped_further_stays_at_floor(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject(price: 0, beds: 0, baths: 0);
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        $this->seedCompRow($agencyId, $reportId, 'Comp 0', now()->subMonths(2)->toDateString(), 1_500_000, 'testville');

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame('thin', $score['state'], 'thin is already the floor — nothing lower except none');
        $this->assertTrue($score['can_generate']);
    }

    public function test_none_state_never_produced_by_subject_incompleteness_alone(): void
    {
        // Zero market comps AND an incomplete subject — capping logic must
        // never itself push a real thin/rich/moderate state down into
        // "none" (that would silently flip can_generate false, which
        // Johan was explicit must never happen from this fix).
        [$property] = $this->seedSubject(price: 0, beds: 0, baths: 0);
        // No comps seeded at all.

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame('none', $score['state'], 'zero market comps is a genuine, pre-existing "none" — unrelated to this fix');
        $this->assertFalse($score['can_generate'], 'the ONLY thing that gates generation is zero market comps, not subject completeness');
    }

    public function test_recommendation_merges_both_facts_into_one_sentence(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject(price: 0, beds: 0, baths: 2);
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        for ($i = 0; $i < 6; $i++) {
            $this->seedCompRow($agencyId, $reportId, "Comp $i", now()->subMonths(2)->toDateString(), 1_500_000, 'testville');
        }

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertStringContainsString('6 comparable sales found nearby', $score['recommendation']);
        $this->assertStringContainsString('missing bedrooms and price', $score['recommendation']);
        $this->assertStringNotContainsString('bathrooms', $score['recommendation'], 'baths was set — must not be named as missing');
        $this->assertStringContainsString('far less accurate', $score['recommendation']);
    }

    public function test_recommendation_unchanged_when_subject_complete(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject(price: 1_900_000, beds: 3, baths: 2);
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        for ($i = 0; $i < 6; $i++) {
            $this->seedCompRow($agencyId, $reportId, "Comp $i", now()->subMonths(2)->toDateString(), 1_500_000, 'testville');
        }

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame('rich', $score['state'], 'a complete subject is not capped — unchanged from current behaviour');
        $this->assertStringStartsWith('Strong data', $score['recommendation']);
        $this->assertSame([], $score['missing_subject_inputs']);
    }

    // ── The assertion Johan explicitly required ────────────────────────

    public function test_banner_and_badge_can_never_disagree(): void
    {
        // For every property where the pre-generate warning WOULD fire
        // (missingSoftInputs non-empty), the badge must never claim "rich"
        // ("Strong data") — regardless of how much market data exists.
        $cases = [
            ['price' => 0, 'beds' => 0, 'baths' => 0],
            ['price' => 0, 'beds' => 3, 'baths' => 2],
            ['price' => 2_000_000, 'beds' => 0, 'baths' => 2],
            ['price' => 2_000_000, 'beds' => 3, 'baths' => 0],
        ];

        foreach ($cases as $case) {
            [$property, $agencyId, $userId] = $this->seedSubject(...$case);
            $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
            // Heavily overstock comps so, absent this fix, it would score "rich".
            for ($i = 0; $i < 12; $i++) {
                $this->seedCompRow($agencyId, $reportId, "Comp $i", now()->subMonths(2)->toDateString(), 1_500_000, 'testville');
            }

            $warningFires = !empty((new CompetitorStockMatchService())->missingSoftInputs($property->fresh()));
            $this->assertTrue($warningFires, 'sanity: this fixture must actually trigger the warning');

            $score = (new CmaCoverageService())->scoreForProperty($property->fresh());
            $this->assertNotSame('rich', $score['state'],
                'banner fires for this property (missing: ' . implode(',', $case) . ') — badge must not say Strong');
        }
    }

    public function test_shared_helper_is_the_same_function_not_a_copy(): void
    {
        [$property] = $this->seedSubject(price: 0, beds: 0, baths: 0);
        $property = $property->fresh();

        $viaCompetitorService = (new CompetitorStockMatchService())->missingSoftInputs($property);
        $viaSharedHelper       = SubjectFieldCompleteness::missingSoftInputs($property);

        $this->assertSame($viaSharedHelper, $viaCompetitorService,
            'CompetitorStockMatchService must delegate, not reimplement');
    }

    // ── seed helpers ────────────────────────────────────────────────────

    /** @return array{0:Property,1:int,2:int} */
    private function seedSubject(int $price, int $beds, int $baths): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Completeness ' . Str::random(6),
            'slug' => 'completeness-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        $property = Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'title'         => 'Subject',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => $price,
            'beds'          => $beds,
            'baths'         => $baths,
            'address'       => '1 Subject Way',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);

        return [$property, $agencyId, $user->id];
    }

    private function seedReport(int $agencyId, int $userId, ?string $subjectAddress, ?string $suburb): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'file_path'           => 'reports/' . Str::random(10) . '.pdf',
            'file_name'           => Str::random(8) . '.pdf',
            'file_hash'           => Str::random(40),
            'report_date'         => now()->toDateString(),
            'subject_address'     => $subjectAddress,
            'source_suburb'       => $suburb,
            'parse_status'        => 'parsed',
            'is_demo'             => 0,
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }

    private function seedCompRow(int $agencyId, ?int $reportId, string $address, string $saleDate, int $salePrice, string $suburbNorm): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId([
            'agency_id'         => $agencyId,
            'market_report_id'  => $reportId,
            'row_index'         => 1,
            'row_type'          => 'comp',
            'address'           => $address,
            'suburb_normalised' => $suburbNorm,
            'property_type'     => 'House',
            'sale_date'         => $saleDate,
            'sale_price'        => $salePrice,
            'is_demo'           => 0,
            'created_at'        => now(), 'updated_at' => now(),
        ]);
    }
}
