<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\SuggestedActionThresholds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC tile-count cache window — the setting must be REACHABLE.
 *
 * Audit, 2026-08-27: `mic_counts_cache_fresh_seconds` / `_stale_seconds` shipped
 * as columns, model casts, and an allow-list entry in
 * ProspectingConfigurationService, with the MIC spec calling them
 * "agency-configurable" — but no control existed on any page and neither caller
 * of updateSuggestedActionThresholds() passed the keys, so nothing could ever
 * set them. A setting nobody can reach is not a setting (CLAUDE.md #10a).
 *
 * These tests exist so that can't silently regress: they drive the real form on
 * Settings → Prospecting Setup → Stale-claim rules, not the service directly.
 */
final class MicCountsCacheWindowSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_form_renders_the_cache_window_fields(): void
    {
        [$agencyId, $owner] = $this->seedAgencyOwner();

        $this->actingAs($owner)
            ->get(route('settings.prospecting.stale-rules.edit'))
            ->assertOk()
            ->assertSee('mic_counts_cache_fresh_seconds', false)
            ->assertSee('mic_counts_cache_stale_seconds', false);
    }

    public function test_the_form_saves_the_cache_window(): void
    {
        [$agencyId, $owner] = $this->seedAgencyOwner();

        $this->actingAs($owner)
            ->put(route('settings.prospecting.stale-rules.update'), [
                'claim_warn_days'                => 7,
                'claim_release_days'             => 10,
                'mic_counts_cache_fresh_seconds' => 15,
                'mic_counts_cache_stale_seconds' => 120,
            ])
            ->assertSessionHasNoErrors();

        $row = SuggestedActionThresholds::getOrCreateForAgency($agencyId);
        $this->assertSame(15, (int) $row->mic_counts_cache_fresh_seconds);
        $this->assertSame(120, (int) $row->mic_counts_cache_stale_seconds);
    }

    /** The stale >= fresh invariant the service enforces must surface on the form, not 500. */
    public function test_a_stale_window_below_the_fresh_window_is_rejected(): void
    {
        [$agencyId, $owner] = $this->seedAgencyOwner();

        $this->actingAs($owner)
            ->put(route('settings.prospecting.stale-rules.update'), [
                'claim_warn_days'                => 7,
                'claim_release_days'             => 10,
                'mic_counts_cache_fresh_seconds' => 300,
                'mic_counts_cache_stale_seconds' => 60,
            ])
            ->assertSessionHasErrors('mic_counts_cache_stale_seconds');

        $row = SuggestedActionThresholds::getOrCreateForAgency($agencyId);
        $this->assertSame(60, (int) $row->mic_counts_cache_fresh_seconds, 'a rejected save must leave the defaults intact');
        $this->assertSame(300, (int) $row->mic_counts_cache_stale_seconds);
    }

    /** Defaults match the [60, 300] the window replaced — no behaviour change on upgrade. */
    public function test_defaults_match_the_previously_hardcoded_window(): void
    {
        [$agencyId] = $this->seedAgencyOwner();

        $row = SuggestedActionThresholds::getOrCreateForAgency($agencyId);
        $this->assertSame(60, (int) $row->mic_counts_cache_fresh_seconds);
        $this->assertSame(300, (int) $row->mic_counts_cache_stale_seconds);
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyOwner(): array
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
        $owner = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return [$agencyId, $owner];
    }
}
