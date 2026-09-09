<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile Profile screen — FFC/cell/WhatsApp/socials view+edit, same `users`
 * row the web My Portal → Profile tab edits, plus the public agent-page
 * preview URL and the role fields added to /api/v1/profile.
 *
 * Spec: .ai/specs/mobile-agent-profile.md
 */
final class MobileAgentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_editable_fields_role_and_preview_url(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role' => 'agent',
            'name' => 'Andre Agent',
            'cell' => '0821234567',
            'whatsapp_number' => '0827654321',
            'ffc_number' => 'FF123456',
            'website_social_facebook' => 'https://facebook.com/andre.agent',
            'website_social_instagram' => 'https://instagram.com/andre.agent',
        ]);
        Sanctum::actingAs($agent);

        $this->getJson(route('v1.mobile.profile.show'))
            ->assertOk()
            ->assertJson([
                'role' => 'agent',
                'cell' => '0821234567',
                'whatsapp_number' => '0827654321',
                'ffc_number' => 'FF123456',
                'website_social_facebook' => 'https://facebook.com/andre.agent',
                'website_social_instagram' => 'https://instagram.com/andre.agent',
                'can_edit' => true,
            ])
            ->assertJsonPath('public_profile_url', $agent->fresh()->publicProfileUrl());
    }

    public function test_update_persists_to_same_row_web_edits_and_is_visible_on_web(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'cell' => '0821111111',
        ]);
        Sanctum::actingAs($agent);

        $this->patchJson(route('v1.mobile.profile.update'), [
            'cell' => '0822222222',
            'whatsapp_number' => '0823333333',
            'ffc_number' => 'FF999',
            'website_social_facebook' => 'https://facebook.com/new.handle',
            'website_social_instagram' => 'https://instagram.com/new.handle',
        ])->assertOk()->assertJsonPath('cell', '0822222222');

        $this->assertDatabaseHas('users', [
            'id' => $agent->id,
            'cell' => '0822222222',
            'whatsapp_number' => '0823333333',
            'ffc_number' => 'FF999',
            'website_social_facebook' => 'https://facebook.com/new.handle',
            'website_social_instagram' => 'https://instagram.com/new.handle',
        ]);

        // Web reads the exact same column, so My Portal renders the mobile edit.
        $this->actingAs($agent)
            ->get(route('agent.portal'))
            ->assertOk()
            ->assertSee('0822222222');
    }

    public function test_cell_is_required(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'cell' => '0821111111',
        ]);
        Sanctum::actingAs($agent);

        $this->patchJson(route('v1.mobile.profile.update'), ['cell' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cell']);

        $this->assertDatabaseHas('users', ['id' => $agent->id, 'cell' => '0821111111']);
    }

    public function test_whatsapp_number_rejects_non_sa_mobile_shape(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'cell' => '0821111111',
        ]);
        Sanctum::actingAs($agent);

        $this->patchJson(route('v1.mobile.profile.update'), [
            'cell' => '0821111111',
            'whatsapp_number' => 'not-a-number',
        ])->assertStatus(422)->assertJsonValidationErrors(['whatsapp_number']);
    }

    public function test_logged_user_profile_endpoint_now_includes_role(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        Sanctum::actingAs($agent);

        $this->getJson(route('v1.profile'))
            ->assertOk()
            ->assertJson(['role' => 'agent'])
            ->assertJsonStructure(['role_label']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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
