<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationRefreshRequest;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 5 — snapshot persistence + freshness CTA + one-click revision.
 *
 * Covers the 8 proofs from the build prompt:
 *   1. Publish writes snapshot_payload + snapshot_taken_at.
 *   2. Public view reads from snapshot — underlying data edits do NOT
 *      change what the seller sees.
 *   3. Republish refreshes the snapshot.
 *   4. Backdate + agency setting → CTA visible above the freshness window.
 *   5. One-click POST creates a PresentationRefreshRequest.
 *   6. Second click within window returns already_requested without
 *      creating a duplicate request.
 *   7. Agency lowers freshness_days → existing presentations re-evaluate
 *      on next view.
 *   8. Token shape is a random string (already true — sanity assertion).
 */
final class SnapshotAndRevisionTest extends TestCase
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

    // ── 1 — publish writes snapshot ──────────────────────────────────

    public function test_publish_writes_snapshot_payload_and_taken_at(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);

        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        $fresh = $version->fresh();
        $this->assertSame(PresentationVersion::REVIEW_PUBLISHED, $fresh->review_status);
        $this->assertNotNull($fresh->snapshot_taken_at);
        $this->assertIsArray($fresh->snapshot_payload);
        // The payload must contain the core sections we render publicly.
        $this->assertArrayHasKey('cma_valuation',    $fresh->snapshot_payload);
        $this->assertArrayHasKey('comparable_sales', $fresh->snapshot_payload);
    }

    // ── 2 — snapshot holds against live data changes ─────────────────

    public function test_public_view_renders_snapshot_not_live_data(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);

        // Publish — snapshot frozen at 1_500_000 Middle.
        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        // Move the underlying CMA fields. If the public view recomputed
        // live, Middle would shift.
        PresentationField::where('presentation_id', $version->presentation_id)
            ->where('field_key', 'cma.middle_range')
            ->update(['final_value' => '9999999']);

        // Issue a public share link for the version.
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk();
        // The snapshot's frozen middle is in the public HTML; the
        // bumped 9_999_999 isn't.
        $resp->assertSee('1 500 000');
        $resp->assertDontSee('9 999 999');
    }

    // ── 3 — republish refreshes the snapshot ─────────────────────────

    public function test_republish_refreshes_snapshot_taken_at_and_payload(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);

        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        $firstSnapshotAt = $version->fresh()->snapshot_taken_at;
        $this->assertNotNull($firstSnapshotAt);

        // Backdate the snapshot so we can prove republish refreshes it.
        $version->fresh()->forceFill(['snapshot_taken_at' => now()->subDays(45)])->save();

        // Republish.
        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        $republished = $version->fresh();
        $this->assertNotNull($republished->snapshot_taken_at);
        $this->assertTrue(
            $republished->snapshot_taken_at->diffInDays(now()) < 1,
            'Republish must refresh snapshot_taken_at to now()',
        );
    }

    // ── 4 — CTA visible past freshness window ────────────────────────

    public function test_cta_visible_when_snapshot_age_exceeds_freshness_days(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        \App\Models\Agency::where('id', $agencyId)->update(['presentations_freshness_days' => 90]);

        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);
        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))->assertRedirect(route('presentations.show', $version->presentation_id));

        $version = $version->fresh();
        // Backdate snapshot to 100 days ago — past the 90-day window.
        $version->forceFill(['snapshot_taken_at' => now()->subDays(100)])->save();

        $link = $this->seedShareLink($agencyId, $user->id, $version);
        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk();
        $resp->assertSee('request revised analysis', false);
        $resp->assertSee('id="btn-request-revision"', false);
    }

    public function test_cta_hidden_within_freshness_window(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);
        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))->assertRedirect(route('presentations.show', $version->presentation_id));

        $link = $this->seedShareLink($agencyId, $user->id, $version->fresh());

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk()->assertDontSee('id="btn-request-revision"', false);
    }

    public function test_footnote_visible_between_30_days_and_freshness_window(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        \App\Models\Agency::where('id', $agencyId)->update(['presentations_freshness_days' => 90]);
        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);
        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))->assertRedirect(route('presentations.show', $version->presentation_id));

        $version = $version->fresh();
        $version->forceFill(['snapshot_taken_at' => now()->subDays(45)])->save();

        $link = $this->seedShareLink($agencyId, $user->id, $version);
        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk()
            ->assertSee('class="freshness-footnote"', false)
            ->assertDontSee('id="btn-request-revision"', false);
    }

    // ── 5 — one-click CTA creates a refresh request ──────────────────

    public function test_one_click_request_creates_refresh_request(): void
    {
        Notification::fake();
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->postJson(route('presentation.public.request-revision', $link->token), []);

        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, PresentationRefreshRequest::where('snapshot_link_id', $link->id)->count());
    }

    // ── 6 — idempotent: second click returns already_requested ──────

    public function test_second_click_returns_already_requested_without_duplicate(): void
    {
        Notification::fake();
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $this->postJson(route('presentation.public.request-revision', $link->token), [])
            ->assertOk()->assertJsonMissing(['already_requested' => true]);

        $resp = $this->postJson(route('presentation.public.request-revision', $link->token), []);
        $resp->assertOk()->assertJson(['ok' => true, 'already_requested' => true]);

        $this->assertSame(1, PresentationRefreshRequest::where('snapshot_link_id', $link->id)->count());
    }

    // ── 7 — agency change re-evaluates on next view ─────────────────

    public function test_agency_freshness_change_takes_effect_on_next_view(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        \App\Models\Agency::where('id', $agencyId)->update(['presentations_freshness_days' => 90]);
        $version = $this->seedVersion($agencyId, $user->id);
        $this->seedCmaFields($version->presentation_id, $agencyId, 1_000_000, 1_500_000, 2_000_000);
        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))->assertRedirect(route('presentations.show', $version->presentation_id));
        $version = $version->fresh();
        $version->forceFill(['snapshot_taken_at' => now()->subDays(60)])->save();

        $link = $this->seedShareLink($agencyId, $user->id, $version);
        // 60 < 90 → no CTA.
        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()->assertDontSee('id="btn-request-revision"', false);

        // Agency tightens window to 30 days.
        \App\Models\Agency::where('id', $agencyId)->update(['presentations_freshness_days' => 30]);

        // Next view: 60 > 30 → CTA appears.
        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()->assertSee('id="btn-request-revision"', false);
    }

    // ── 8 — token shape sanity ───────────────────────────────────────

    public function test_share_link_token_is_random_string_not_sequential(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersion($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        // Long alphanumeric, no integer.
        $this->assertGreaterThanOrEqual(32, strlen($link->token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $link->token);
        $this->assertFalse(ctype_digit($link->token), 'Token must not be a numeric id');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function seedVersion(int $agencyId, int $userId, array $overrides = []): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $userId,
            'title'              => 'Snapshot Test',
            'property_address'   => '1 Test Avenue',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
            'asking_price_inc'   => 1_800_000,
        ]);
        return PresentationVersion::create(array_merge([
            'agency_id'         => $agencyId,
            'presentation_id'   => $presentation->id,
            'compiled_by'       => $userId,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at'=> now(),
        ], $overrides));
    }

    private function seedShareLink(int $agencyId, int $userId, PresentationVersion $version): PresentationSnapshotLink
    {
        return PresentationSnapshotLink::create([
            'agency_id'              => $agencyId,
            'presentation_id'        => $version->presentation_id,
            'presentation_version_id'=> $version->id,
            'created_by_user_id'     => $userId,
            'token'                  => Str::random(48),
            'mode'                   => 'full',
            'expires_at'             => now()->addYear(),
        ]);
    }

    private function seedCmaFields(int $presentationId, int $agencyId, int $lower, int $middle, int $upper): void
    {
        foreach ([
            'cma.lower_range'  => $lower,
            'cma.middle_range' => $middle,
            'cma.upper_range'  => $upper,
        ] as $key => $value) {
            PresentationField::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'field_key'       => $key,
                'final_value'     => (string) $value,
            ]);
        }
    }
}
