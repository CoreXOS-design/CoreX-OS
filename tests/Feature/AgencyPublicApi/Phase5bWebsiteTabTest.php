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
            'website_url'             => 'https://coastal.example',
            'website_tagline'         => 'Your coast, your home',
            'website_about'           => 'We sell the KZN South Coast.',
            'website_contact_email'   => 'hello@coastal.example',
            'website_social_facebook' => 'coastalrealty',
            'website_show_agents'     => '1',
            // website_show_listings intentionally omitted → should become false
        ])->assertRedirect();

        $a = $this->agency->fresh();
        $this->assertSame('https://coastal.example', $a->website_url);
        $this->assertSame('Your coast, your home', $a->website_tagline);
        $this->assertSame('hello@coastal.example', $a->website_contact_email);
        $this->assertSame('coastalrealty', $a->website_social_facebook);
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

    public function test_invalid_url_and_email_are_rejected(): void
    {
        $this->actingAs($this->user)->put(route('admin.company-settings.website.update', $this->agency), [
            'website_url'           => 'not-a-url',
            'website_contact_email' => 'not-an-email',
        ])->assertSessionHasErrors(['website_url', 'website_contact_email']);
    }

    public function test_website_settings_surface_in_the_public_api(): void
    {
        $this->agency->update(['website_enabled' => true, 'website_tagline' => 'Coast life']);
        $minted = \App\Models\AgencyApiKey::mintSecret();
        \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [\App\Models\AgencyApiKey::SCOPE_AGENCY_READ],
        ]);

        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/agency')
            ->assertOk()->assertJsonPath('data.tagline', 'Coast life');
    }
}
