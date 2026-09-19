<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The mobile app needs to know whether the signed-in user is an assistant so
 * it can hide "share with my details" on the property live-preview chooser
 * (same rule as web AT-267 — assistants never front a listing).
 *
 * 1. GET /api/v1/profile and GET /api/v1/logged-user now carry `is_assistant`.
 * 2. PropertyController::livePreview() now also closes the ?agent=<id> path
 *    for an assistant, not just ?agent=me.
 */
final class AssistantIsAssistantExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_profile_reports_is_assistant_true_for_an_assistant(): void
    {
        $agencyId = $this->makeAgency();
        $assistant = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'assistant', 'is_assistant' => true,
        ]);
        Sanctum::actingAs($assistant);

        $this->getJson(route('v1.profile'))->assertOk()->assertJson(['is_assistant' => true]);
    }

    public function test_v1_profile_reports_is_assistant_false_for_a_regular_agent(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'is_assistant' => false,
        ]);
        Sanctum::actingAs($agent);

        $this->getJson(route('v1.profile'))->assertOk()->assertJson(['is_assistant' => false]);
    }

    /**
     * REPORTED, NOT FIXED (rule 2 — out of this task's exact scope, found while
     * completing it): the route named `v1.logged-user` (routes/api.php:371-390,
     * the closure this task added `is_assistant` to) is UNREACHABLE. routes/web.php:380-381
     * registers a second route at the exact same URI+method — GET api/v1/logged-user,
     * named `api.v1.logged-user` -> Api\V1\MeController::show — and Laravel's
     * RouteCollection keys its URI lookup table by method+URI, so whichever of the two
     * is registered later silently overwrites the other's slot; refreshNameLookups()
     * then rebuilds the NAME table from that same de-duplicated list, so the loser's
     * name disappears too, not just its URI slot. Confirmed at runtime:
     * `app('router')->getRoutes()->getByName('v1.logged-user')` is null, and every real
     * GET /api/v1/logged-user request is actually served by MeController::show(), whose
     * response has a completely different shape (nested under `user`, no `is_assistant`,
     * `role`, `branch`, `ffc_status` or top-level `agency` key at all — see MeController.php).
     * This means the `is_assistant` field added to the `v1.logged-user` closure in this
     * task is dead code today: no real caller of that URL can ever observe it. Needs
     * Johan's call on which registration is canonical — not decided here.
     */
    public function test_logged_user_route_name_is_shadowed_by_a_duplicate_web_php_registration(): void
    {
        $this->assertNull(
            app('router')->getRoutes()->getByName('v1.logged-user'),
            'routes/api.php:371-390 registers a route named v1.logged-user, but '
            . 'routes/web.php:380-381 registers a second route at the identical URI+method '
            . '(GET api/v1/logged-user), which wins the RouteCollection URI-keyed slot — so '
            . 'v1.logged-user is entirely absent from the name lookup table. If this assertion '
            . 'ever fails, the shadow has been resolved: replace this pin with real coverage of '
            . "GET /api/v1/logged-user's is_assistant field."
        );

        $agencyId = $this->makeAgency();
        $assistant = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'assistant', 'is_assistant' => true,
        ]);
        Sanctum::actingAs($assistant);

        // The URL itself is reachable — it is just served by the OTHER (web.php)
        // registration, which carries no is_assistant field at all.
        $this->getJson('/api/v1/logged-user')->assertOk()->assertJsonMissing(['is_assistant' => true]);
    }

    public function test_live_preview_with_assistant_agent_id_falls_back_to_the_listing_agent(): void
    {
        $agencyId = $this->makeAgency();
        $listingAgent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'name' => 'Listing Owner',
        ]);
        $assistant = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'assistant',
            'is_assistant' => true, 'name' => 'Behind The Scenes Assistant',
        ]);

        $property = $this->property($agencyId, $listingAgent, 'ZZZ-Assistant-Link-Preview', [
            'listing_type' => 'sale',
            'price' => 1_200_000,
        ]);

        $res = $this->get($this->previewUrl($property) . '?agent=' . $assistant->id)->assertOk();

        $res->assertSee('Listing Owner', false);
        $res->assertDontSee('Behind The Scenes Assistant', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function previewUrl(Property $p): string
    {
        return route('corex.properties.preview', [$p, Str::slug((string) $p->title)]);
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'agent_id' => $agent->id,
            'title' => $title,
            'status' => 'active',
            'property_type' => 'apartment',
            'suburb' => 'Uvongo',
            'city' => 'Margate',
            'province' => 'KwaZulu-Natal',
            // Preview is gated on marketing readiness; a snapshot makes it
            // marketable without standing up the whole compliance fixture.
            'compliance_snapshot_at' => now(),
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
