<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-161 — Communications IA re-cut. Nav re-parent + relabel only; every page keeps
 * a gate; the two borrowed whistleblow gates are fixed to proper own gates. This
 * proves the sidebar renders the new tree and the moved/relabelled items appear
 * under Communications (findability), while the URLs are unchanged.
 */
final class CommsNavIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // super_admin sees every gated item (permission checks pass).
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
    }

    public function test_communications_menu_shows_the_recut_tree(): void
    {
        $resp = $this->actingAs($this->admin())->get(route('agent.portal'));
        $resp->assertOk();

        // The consolidated home + its plainly-named sections/items.
        $resp->assertSee('Communications');
        $resp->assertSee('Message Archive');          // moved from Compliance
        $resp->assertSee('Flagged Messages');         // was "Flag Register"
        $resp->assertSee('Archive Access Requests');  // was "Access Requests"
        $resp->assertSee('Link My WhatsApp');         // AT-156, added to the menu
        $resp->assertSee('My Capture Consent');       // was "My WhatsApp Capture" — the consent Johan couldn't find
        $resp->assertSee('Capture Consent (Team)');   // was "Capture Opt-outs (Review)"
        $resp->assertSee('WhatsApp Capture (Browser Extension)');
        $resp->assertSee('Email Capture Setup');      // moved from Settings
        $resp->assertSee('Archive Mailboxes');        // moved from Compliance
        $resp->assertSee('My Communication Setup');   // was "Communication Capture"

        // Old ambiguous labels are gone.
        $resp->assertDontSee('My WhatsApp Capture');
        $resp->assertDontSee('>Communication Capture<', false);
    }

    public function test_urls_are_unchanged_no_bookmark_breaks(): void
    {
        // The moved items keep their existing URLs (nav-only re-parent).
        $this->assertSame('/corex/compliance/communication-archive', route('compliance.comm-archive.index', absolute: false));
        $this->assertSame('/corex/compliance/communication-flags', route('compliance.comm-flags.index', absolute: false));
        $this->assertSame('/corex/settings/email-setup', route('settings.email-setup.index', absolute: false));
    }
}
