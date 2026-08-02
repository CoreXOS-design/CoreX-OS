<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AiCopyUnavailableException;
use App\Http\Controllers\Tools\AdManagerController;
use App\Models\Property;
use App\Models\User;
use App\Services\MarketingCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ad Manager — "how many ads have been created for this property, and when
 * was the last one?" A running counter + timestamp, stamped only for
 * properties that actually got an asset generated (not ones skipped by the
 * scope check), across both the custom-template/pre-built path
 * (AdManagerController::generate()) and the Printable Brochure path
 * (AdManagerController::generateBrochures()).
 *
 * Calls the controller method directly rather than the HTTP route — the
 * `tools.ad-manager*` routes carry a `feature:ad-manager` flag middleware
 * that isn't enabled for a fresh test agency by default (a pre-existing,
 * unrelated fixture gap — see AdManagerScopeTest, which hits the same 404
 * on the plain index route). Calling the controller method directly
 * exercises the real business logic (the DB increment, the scope check)
 * without depending on that unrelated flag.
 */
final class AdManagerGeneratedCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never let a test reach the real Anthropic API — slow, non-deterministic,
        // and burns real AI budget. The AI copy step is orthogonal to what these
        // tests assert (the counter); the controller already treats an AI failure
        // as non-fatal (sets `ai_error`, still appends the row to `$results`), so
        // making it fail fast is the correct fake, not a workaround.
        $this->mock(MarketingCopyService::class, function ($mock) {
            $mock->shouldReceive('generateAdCopy')
                ->andThrow(new AiCopyUnavailableException('faked for test'));
        });
    }

    public function test_generate_stamps_the_counter_and_timestamp_for_a_prebuilt_template(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch);
        $property = $this->property($agency, $branch, $agent, 'ZZZ-Counter-House');

        // A freshly created model's in-memory attributes reflect only what was
        // explicitly set — Eloquent never re-fetches DB-side column defaults
        // after an INSERT, so `refresh()` is needed to see the true starting
        // state (default 0 / null) before asserting it.
        $property->refresh();
        $this->assertSame(0, $property->ad_generated_count);
        $this->assertNull($property->ad_last_generated_at);

        $this->actingAs($agent);
        $request = Request::create('/tools/ad-manager/generate', 'POST', [
            'property_ids' => [$property->id],
            'template'     => 'power',
        ]);

        $response = app(AdManagerController::class)->generate($request);
        $data     = json_decode($response->getContent(), true);

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['results']);

        $property->refresh();
        $this->assertSame(1, $property->ad_generated_count);
        $this->assertNotNull($property->ad_last_generated_at);
    }

    public function test_generate_increments_across_multiple_calls(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch);
        $property = $this->property($agency, $branch, $agent, 'ZZZ-Counter-Twice');

        $this->actingAs($agent);
        $make = fn () => app(AdManagerController::class)->generate(Request::create('/tools/ad-manager/generate', 'POST', [
            'property_ids' => [$property->id],
            'template'     => 'power',
        ]));

        $make();
        $make();

        $property->refresh();
        $this->assertSame(2, $property->ad_generated_count);
    }

    public function test_a_property_skipped_by_the_scope_check_is_never_counted(): void
    {
        [$agency, $branchA] = $this->agencyWithBranch();
        $branchB = $this->branch($agency, 'Other Branch');
        $agent   = $this->agencyUser($agency, $branchA);
        $other   = $this->agencyUser($agency, $branchB);

        $ownProperty   = $this->property($agency, $branchA, $agent, 'ZZZ-Own');
        $otherProperty = $this->property($agency, $branchB, $other, 'ZZZ-Other');

        $this->actingAs($agent); // default scope is 'own' — cannot advertise $otherProperty
        $request = Request::create('/tools/ad-manager/generate', 'POST', [
            'property_ids' => [$ownProperty->id, $otherProperty->id],
            'template'     => 'power',
        ]);

        $response = app(AdManagerController::class)->generate($request);
        $data     = json_decode($response->getContent(), true);

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['results'], 'only the own listing should have been generated');

        $ownProperty->refresh();
        $otherProperty->refresh();
        $this->assertSame(1, $ownProperty->ad_generated_count);
        $this->assertSame(0, $otherProperty->ad_generated_count, 'a property skipped by the scope check must never be counted');
    }

    public function test_the_brochure_path_also_stamps_the_counter(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch);
        $property = $this->property($agency, $branch, $agent, 'ZZZ-Brochure-House');

        $this->actingAs($agent);
        $request = Request::create('/tools/ad-manager/generate', 'POST', [
            'property_ids' => [$property->id],
            'template'     => 'brochure',
        ]);

        $response = app(AdManagerController::class)->generate($request);
        $data     = json_decode($response->getContent(), true);

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['results']);

        $property->refresh();
        $this->assertSame(1, $property->ad_generated_count);
        $this->assertNotNull($property->ad_last_generated_at);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, defaultBranchId] */
    private function agencyWithBranch(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = $this->branch($agencyId, 'Default');

        return [$agencyId, $branchId];
    }

    private function branch(int $agencyId, string $name): int
    {
        return (int) DB::table('branches')->insertGetId([
            'agency_id'  => $agencyId,
            'name'       => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agencyUser(int $agencyId, int $branchId, string $role = 'agent'): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, int $branchId, User $agent, string $title): Property
    {
        return Property::create([
            'agency_id'              => $agencyId,
            'branch_id'              => $branchId,
            'agent_id'               => $agent->id,
            'title'                  => $title,
            'status'                 => 'active',
            'listing_type'           => 'sale',
            'property_type'          => 'house',
            'p24_ref'                => 'P24-' . Str::random(6),
            'p24_syndication_status' => 'active',
        ]);
    }
}
