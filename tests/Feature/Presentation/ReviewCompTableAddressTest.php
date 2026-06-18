<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review-screen comp table — regression coverage for the blank-address
 * bug.
 *
 * Pre-fix the review controller built `$compRows[*]['address']` from
 * `raw_row_json.address` directly with a `?? '—'` fallback, bypassing
 * the never-blank CompLabel helper that the PDF + analysis tab use.
 * Sectional comps that carry `scheme_name` + `section_number` but no
 * street address (a common CMA-Info ingestion pattern) rendered as
 * blank on the review screen while displaying correctly elsewhere.
 *
 * This test pins the fix: the review screen must route through
 * CompLabel so review + PDF + analysis tab show identical labels.
 * Existing CompLabel unit tests cover the chain itself; this is the
 * controller-wiring coverage that was missing.
 */
final class ReviewCompTableAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    public function test_review_screen_renders_section_label_for_comp_with_no_street_address(): void
    {
        // Seed agency + user + presentation + version.
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'RevAddr ' . Str::random(4),
            'slug' => 'revaddr-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        $property = Property::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title' => 'Subject', 'property_type' => 'Sectional Title',
            'category' => 'Residential', 'suburb' => 'Uvongo',
            'price' => 1_200_000, 'beds' => 2,
            'address' => '1 Subject Way', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'property_id' => $property->id,
            'created_by_user_id' => $user->id, 'title' => 'Review Addr Test',
            'property_address' => '1 Subject Way', 'suburb' => 'Uvongo',
            'property_type' => 'sectional', 'asking_price_inc' => 1_200_000,
            'status' => 'draft', 'currency' => 'ZAR',
        ]);
        $version = PresentationVersion::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'blueprint_version' => 'test',
            'data_snapshot_json' => json_encode(['note' => 'review-addr-test']),
            'compiled_at' => now(),
        ]);

        // Seed three comps — one with street address (control), one
        // sectional with scheme + section (Step 2 of the CompLabel chain),
        // one with bare section + suburb (Step 3). All three must
        // render a non-blank Address cell on the review screen.
        PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentation->id,
            'property_type'   => 'sectional_title',
            'suburb'          => 'Uvongo',
            'sold_price_inc'  => 1_300_000,
            'sold_date'       => now()->subMonths(3),
            'size_m2'         => 85,
            'raw_row_json'    => json_encode(['address' => '63 GARDEN AVENUE UVONGO']),
            'parser_version'  => 'test',
        ]);
        PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentation->id,
            'property_type'   => 'sectional_title',
            'suburb'          => 'Uvongo',
            'sold_price_inc'  => 1_150_000,
            'sold_date'       => now()->subMonths(5),
            'size_m2'         => 78,
            // Scheme + section, NO street address — pre-fix rendered '—'.
            'raw_row_json'    => json_encode([
                'scheme_name'    => 'Seeskulp',
                'section_number' => '8',
            ]),
            'parser_version'  => 'test',
        ]);
        PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentation->id,
            'property_type'   => 'sectional_title',
            'suburb'          => 'Uvongo',
            'sold_price_inc'  => 1_090_000,
            'sold_date'       => now()->subMonths(8),
            'size_m2'         => 72,
            // Bare section + the comp row's suburb — Step 3 of CompLabel.
            'raw_row_json'    => json_encode(['section_number' => '10']),
            'parser_version'  => 'test',
        ]);

        // Visit the review screen as the agency super admin.
        $this->actingAs($user);
        $response = $this->get(route('presentations.review.show', $version));
        $response->assertOk();

        $html = (string) $response->getContent();

        // Control: street-address comp renders verbatim.
        $this->assertStringContainsString('63 GARDEN AVENUE UVONGO', $html,
            'Street-address comp still renders its address.');

        // Bug pin 1: scheme + section produces "Unit 8, Seeskulp"
        // (Unit-first, matching the subject identity) — pre-fix this
        // would have been a blank cell ("—").
        $this->assertStringContainsString('Unit 8, Seeskulp', $html,
            'Sectional comp with no street address now shows CompLabel Unit+scheme output.');

        // Bug pin 2: bare section + suburb produces "Section 10, Uvongo".
        $this->assertStringContainsString('Section 10, Uvongo', $html,
            'Bare-section comp now shows CompLabel Step 3 output (was blank pre-fix).');

        // Sanity: the dashlike fallback that the bug used to produce
        // should NOT appear in the Address cells (the fallback we
        // replaced was `?? '—'`; an em-dash in a comp Address column
        // would indicate the old code path is still active).
        // Other dashes (e.g. sale-date "—" placeholder for nulls) are
        // OK, so we just confirm the three labels exist; we don't
        // assert "no dashes anywhere".
    }
}
