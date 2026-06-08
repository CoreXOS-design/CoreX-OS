<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency Public API — Phase 5b (Company Settings → Website tab).
 *
 * Spec: .ai/specs/agency-public-api.md §3.7, §7.4.
 */
class Phase5bWebsiteTabTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
    }

    public function test_website_tab_renders_in_company_settings(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.company-settings', ['agency' => $this->agency->id]))
            ->assertOk()
            ->assertSee('Save Website Settings')
            ->assertSee('Show agents on website');
    }

    public function test_saving_website_settings_persists_fields(): void
    {
        $this->actingAs($this->user)->put(route('admin.company-settings.website.update', $this->agency), [
            'website_contact_email'   => 'hello@coastal.example',
            'website_contact_phone'   => '039 000 0000',
            'website_address'         => '12 Marina Drive, Uvongo',
            'website_social_facebook' => 'coastalrealty',
            'website_open_hours'      => [
                ['days' => 'Monday – Friday', 'hours' => '08:00 – 17:00'],
                ['days' => 'Saturday',        'hours' => '09:00 – 13:00'],
                ['days' => '',                'hours' => ''], // blank row → dropped on save
            ],
            'website_show_agents'     => '1',
            // website_show_listings intentionally omitted → should become false
        ])->assertRedirect();

        $a = $this->agency->fresh();
        $this->assertSame('hello@coastal.example', $a->website_contact_email);
        $this->assertSame('039 000 0000', $a->website_contact_phone);
        $this->assertSame('12 Marina Drive, Uvongo', $a->website_address);
        $this->assertSame('coastalrealty', $a->website_social_facebook);
        $this->assertCount(2, $a->website_open_hours); // blank row dropped
        $this->assertSame('Saturday', $a->website_open_hours[1]['days']);
        $this->assertTrue((bool) $a->website_show_agents);
        $this->assertFalse((bool) $a->website_show_listings);
    }

    public function test_website_tab_saves_agent_order_mode_and_positions(): void
    {
        $branch = \App\Models\Branch::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $this->agency->id)->first();
        $a = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'show_on_website' => true]);
        $b = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'show_on_website' => true]);

        $this->actingAs($this->user)->put(route('admin.company-settings.website.update', $this->agency), [
            'website_agent_order_mode' => 'custom',
            'agent_order' => [$a->id => 2, $b->id => 1],
        ])->assertRedirect();

        $this->assertSame('custom', $this->agency->fresh()->website_agent_order_mode);
        $this->assertSame(2, (int) $a->fresh()->website_order);
        $this->assertSame(1, (int) $b->fresh()->website_order);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->actingAs($this->user)->put(route('admin.company-settings.website.update', $this->agency), [
            'website_contact_email' => 'not-an-email',
        ])->assertSessionHasErrors(['website_contact_email']);
    }

    public function test_website_settings_surface_in_the_public_api(): void
    {
        $this->agency->update([
            'website_enabled'    => true,
            'website_address'    => '12 Marina Drive, Uvongo',
            'website_open_hours' => [['days' => 'Monday – Friday', 'hours' => '08:00 – 17:00']],
        ]);
        $minted = \App\Models\AgencyApiKey::mintSecret();
        \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [\App\Models\AgencyApiKey::SCOPE_AGENCY_READ],
        ]);

        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/agency')
            ->assertOk()
            ->assertJsonPath('data.contact.address', '12 Marina Drive, Uvongo')
            ->assertJsonPath('data.open_hours.0.hours', '08:00 – 17:00');
    }

    public function test_blank_website_fields_are_omitted_from_the_api(): void
    {
        // A freshly-created agency with no website contact/social/hours set:
        // the public payload must not carry empty contact/social/open_hours.
        $this->agency->update(['website_enabled' => true]);
        $minted = \App\Models\AgencyApiKey::mintSecret();
        \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [\App\Models\AgencyApiKey::SCOPE_AGENCY_READ],
        ]);

        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/agency')
            ->assertOk()
            ->assertJsonMissingPath('data.social')
            ->assertJsonMissingPath('data.open_hours');
    }
}
