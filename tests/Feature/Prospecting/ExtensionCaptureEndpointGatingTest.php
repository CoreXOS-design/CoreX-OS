<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-449 permission audit (2026-10-12). The Chrome extension's capture endpoints authenticated the
 * token but never checked what the user may DO, so a user who could not open the screen the
 * capture feeds could still push records into it:
 *   - POST /api/v1/deeds-capture, /tva-contact-capture, /tva-company-directors -> now need the same
 *     permission as the Deeds Capture screen (deeds_capture.access);
 *   - POST /portal-captures/ingest -> now needs access_presentations + the presentations feature,
 *     and a presentation_id must belong to the caller's OWN agency (it only had to exist).
 *
 * Fixtures seed SOME grants so PermissionService's unseeded "allow all" test fallback does not apply.
 */
final class ExtensionCaptureEndpointGatingTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = $this->makeAgency();
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ]);
        // Unrelated grant only: "seeded", but holds neither deeds_capture.access nor access_presentations.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->agencyId]);
    }

    private function makeAgency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function grant(string $key): void
    {
        RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        \App\Services\PermissionService::clearCache();
    }

    /** @return array<string,array{0:string}> */
    public static function deedsStyleRoutes(): array
    {
        return [
            'deeds-capture'      => ['v1.deeds-capture'],
            'tva-contact'        => ['v1.tva-contact-capture'],
            'tva-company-dirs'   => ['v1.tva-company-directors'],
        ];
    }

    /** @dataProvider deedsStyleRoutes */
    public function test_a_user_without_deeds_capture_access_is_refused(string $routeName): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(route($routeName), [])->assertForbidden();
    }

    /** @dataProvider deedsStyleRoutes */
    public function test_a_user_with_deeds_capture_access_gets_past_the_gate(string $routeName): void
    {
        $this->grant('deeds_capture.access');
        Sanctum::actingAs($this->user);

        // Empty body: it must reach the controller's own validation (422), not be stopped at the gate (403).
        $this->assertNotSame(403, $this->postJson(route($routeName), [])->getStatusCode());
    }

    public function test_portal_capture_ingest_is_refused_without_presentations_access(): void
    {
        $this->actingAs($this->user)->postJson(route('portal-captures.ingest'), [])->assertForbidden();
    }

    public function test_portal_capture_ingest_rejects_another_agencys_presentation(): void
    {
        $this->grant('access_presentations');

        $otherAgency = $this->makeAgency();
        $otherUser = User::factory()->create(['agency_id' => $otherAgency, 'branch_id' => $otherAgency, 'role' => 'agent']);
        $foreignPresentation = (int) DB::table('presentations')->insertGetId([
            'agency_id' => $otherAgency, 'branch_id' => $otherAgency, 'created_by_user_id' => $otherUser->id,
            'title' => 'Other agency presentation', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = [
            'source_site' => 'property24', 'page_type' => 'property',
            'source_url' => 'https://www.property24.com/x', 'final_url' => 'https://www.property24.com/x',
            'captured_at' => now()->toIso8601String(), 'extractor_version' => 'test',
            'html' => '<html></html>', 'parse_status' => 'parsed',
            'presentation_id' => $foreignPresentation,
        ];

        $this->actingAs($this->user)->postJson(route('portal-captures.ingest'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('presentation_id');
    }
}
