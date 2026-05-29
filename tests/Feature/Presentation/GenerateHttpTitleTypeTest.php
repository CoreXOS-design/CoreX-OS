<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\TitleTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keystone — verify the title_type wiring through the LIVE generator
 * HTTP path (POST /corex/properties/{property}/generate-presentation),
 * not via a directly-created PresentationVersion row.
 *
 * This test closes the exact gap that hid the $agencyId hotfix for six
 * builds: every Build 1-6 feature test created PresentationVersion
 * rows directly, bypassing the controller → service → MicSnapshotHydrator
 * chain. Anything broken in that chain went undetected. This file
 * exercises the chain.
 *
 * Coverage:
 *   (a) Sectional property → subject title_type = sectional_title,
 *       review-screen badge renders "Sectional Title".
 *   (b) Sectional comp seeded into market_report_comp_rows is NOT
 *       flagged cross-type when the subject is sectional.
 *   (c) Property with blank property_type → 422 with the exact
 *       user-facing message specified in the Phase B* plan.
 */
final class GenerateHttpTitleTypeTest extends TestCase
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

    public function test_sectional_property_resolves_to_sectional_title_via_live_generator(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createSectionalProperty($agencyId, $user->id);

        // Sanity: the observer + classifier wrote sectional_title to the
        // column on insert (via Property::create which fires observer).
        $this->assertSame('sectional_title', $property->fresh()->title_type);

        // Go through the HTTP route — this is the gap-closing call.
        $resp = $this->actingAs($user)
            ->postJson(route('corex.properties.generate-presentation', $property->id), [
                'asking_price' => 1_900_000,
            ]);

        $resp->assertStatus(201);
        $json = $resp->json();
        $versionId = $json['version_id'] ?? null;
        $this->assertNotNull($versionId, 'live generator must return a version_id');

        // Confirm the review screen renders the sectional badge for the
        // subject — meaning the subject title_type resolved to sectional.
        $view = $this->actingAs($user)
            ->get(route('presentations.review.show', $versionId));
        $view->assertOk()
            ->assertSee('tt-badge sectional_title', false)
            ->assertSee('Sectional Title', false);
    }

    public function test_sectional_comp_is_not_cross_flagged_when_subject_is_sectional(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createSectionalProperty($agencyId, $user->id);

        // Generate via the live route.
        $resp = $this->actingAs($user)
            ->postJson(route('corex.properties.generate-presentation', $property->id), [
                'asking_price' => 1_900_000,
            ])->assertStatus(201);
        $versionId = (int) $resp->json('version_id');

        // Manually seed a sectional comp on the presentation so the
        // review screen has data to classify. The live generator hydrates
        // from market_report_comp_rows; for the test we want a known
        // sectional comp to exist on the presentation itself.
        $version = PresentationVersion::find($versionId);
        \App\Models\PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $version->presentation_id,
            'property_type'   => 'Sectional Title',
            'sold_date'       => now()->subMonths(2)->toDateString(),
            'sold_price_inc'  => 1_800_000,
            'suburb'          => 'Testville',
            'size_m2'         => 120,
            'raw_row_json'    => json_encode(['address' => '7 Sectional Way', 'latitude' => -30.84, 'longitude' => 30.39]),
            'parser_version'  => 'test',
        ]);

        $view = $this->actingAs($user)
            ->get(route('presentations.review.show', $versionId));
        $view->assertOk();
        $html = $view->getContent();

        // Locate the sectional comp's row in the HTML and verify the
        // is_cross_type marker is NOT set on it. The Blade renders
        // data-cross-type="0" when the flag is false.
        $this->assertMatchesRegularExpression(
            '/data-comp-id="\d+"[^>]+data-cross-type="0"/',
            $html,
            'sectional comp must not carry data-cross-type="1" when subject is sectional',
        );
    }

    public function test_blank_property_type_returns_422_with_exact_message(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createPropertyWithBlankType($agencyId, $user->id);

        $resp = $this->actingAs($user)
            ->postJson(route('corex.properties.generate-presentation', $property->id), [
                'asking_price' => 1_500_000,
            ]);

        $resp->assertStatus(422);
        $this->assertSame(
            'No property type selected — please select a property type to continue.',
            $resp->json('error'),
        );

        // No presentation, no version was created.
        $this->assertSame(0, Presentation::where('property_id', $property->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Gen-HTTP-Test ' . Str::random(6),
            'slug' => 'gen-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Seed a Residential category so the subject has SOMETHING to
        // resolve through if title_type column ever falls through.
        DB::table('property_setting_items')->insert([
            'agency_id'  => $agencyId,
            'group'      => 'category',
            'name'       => 'Residential',
            'title_type' => 'full_title',
            'sort_order' => 0,
            'is_default' => true,
            'active'     => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function createSectionalProperty(int $agencyId, int $userId): Property
    {
        // Property::create fires PropertyObserver::saving → derives
        // title_type via TitleTypeClassifier from property_type.
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'title'         => 'Brock Manor 17',
            'property_type' => 'Sectional Title',
            'category'      => 'Residential', // miscalibrated category — the keystone test
            'suburb'        => 'Testville',
            'size_m2'       => 124,
            'beds'          => 1,
            'baths'         => 1,
            'price'         => 1_900_000,
            'address'       => 'Unit 17, Brock Manor',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
    }

    private function createPropertyWithBlankType(int $agencyId, int $userId): Property
    {
        // We want a blank property_type to test the gate. The properties
        // column is NOT NULL with default 'house' — bypass the default
        // by inserting an empty string explicitly. The controller's
        // trim check fires on '' just as it would on null.
        $id = DB::table('properties')->insertGetId([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'external_id'   => 'BLANK-' . Str::random(8),
            'title'         => 'Untyped',
            'address'       => '1 Vague Lane',
            'property_type' => '',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => 1_500_000,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
            'is_demo'       => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        return Property::find($id);
    }
}
