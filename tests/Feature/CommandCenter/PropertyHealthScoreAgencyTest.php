<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\PropertyHealthScore;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\PropertyHealthCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guard for the nightly property-health data loss.
 *
 * PropertyHealthCalculator runs from the command-center:health console job,
 * where AgencyScope is inert and BelongsToAgency's creating() hook cannot
 * infer the tenant. With more than one agency on the box its single-agency
 * fallback yields 0, so agency_id stayed unset and every insert failed with
 * "Field 'agency_id' doesn't have a default value" — health scores silently
 * never persisted for the whole portfolio after agency_id became NOT NULL.
 *
 * The fix stamps agency_id from the property (the tenant anchor) at the write
 * site. These tests lock that the score persists AND carries the property's
 * own agency_id, in a genuine multi-agency, no-auth (console) context.
 */
final class PropertyHealthScoreAgencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_score_persists_with_property_agency_id_in_console_context(): void
    {
        // Two agencies exist -> the single-agency fallback cannot rescue the
        // insert; the write site must supply agency_id itself.
        $agencyA = $this->seedAgency();
        $this->seedAgency();

        $property = $this->makeProperty($agencyA);

        // No Auth::user() — mirrors the nightly console job exactly.
        $this->assertNull(auth()->user());

        $score = app(PropertyHealthCalculator::class)->calculate($property);

        $this->assertTrue($score->exists, 'health score row must persist');
        $this->assertSame($agencyA, (int) $score->agency_id, 'score must inherit the property\'s agency_id');

        $this->assertDatabaseHas('property_health_scores', [
            'property_id' => $property->id,
            'agency_id'   => $agencyA,
        ]);
    }

    public function test_scores_do_not_cross_agency_boundaries(): void
    {
        $agencyA = $this->seedAgency();
        $agencyB = $this->seedAgency();

        $propA = $this->makeProperty($agencyA);
        $propB = $this->makeProperty($agencyB);

        $calc = app(PropertyHealthCalculator::class);
        $calc->calculate($propA);
        $calc->calculate($propB);

        $this->assertSame(
            $agencyA,
            (int) PropertyHealthScore::withoutGlobalScopes()->where('property_id', $propA->id)->value('agency_id')
        );
        $this->assertSame(
            $agencyB,
            (int) PropertyHealthScore::withoutGlobalScopes()->where('property_id', $propB->id)->value('agency_id')
        );
    }

    public function test_orphan_property_without_agency_is_flagged_not_silently_broken(): void
    {
        // A property with no agency is an upstream data defect; the calculator
        // must surface it clearly rather than emit a confusing NOT-NULL error.
        $property = $this->makeProperty($this->seedAgency());
        DB::table('properties')->where('id', $property->id)->update(['agency_id' => null]);
        $property->refresh();

        $this->expectException(\RuntimeException::class);
        app(PropertyHealthCalculator::class)->calculate($property);
    }

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }

    private function makeProperty(int $agencyId): Property
    {
        // seedAgency() creates a branch whose id == agencyId.
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        // Use the model so generated columns (external_id) populate. With no
        // Auth::user() BelongsToAgency trusts the explicit agency_id.
        $p = new Property();
        $p->forceFill([
            'title'     => 'Listing ' . Str::random(5),
            'address'   => '12 Test Road',
            'agent_id'  => $agent->id,
            'branch_id' => $agencyId,
            'agency_id' => $agencyId,
            'status'    => 'active',
        ])->save();

        return $p->refresh();
    }
}
