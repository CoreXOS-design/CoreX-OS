<?php

namespace Tests\Feature\CoreX;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-240 — Core Matches must expose an Edit door on every render surface.
 *
 * The edit FLOW already existed (_match-form edit mode → PUT matches.update);
 * the gap was the missing entry point. These tests assert the door renders on
 * both named surfaces (contact record + Core Matches page), is permission-aware
 * (hidden without access_core_matches), that the edit page pre-fills, and that
 * the underlying update persists — i.e. the door opens the real edit flow.
 */
class CoreMatchEditDoorTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        PermissionService::clearCache();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        RolePermission::insert([
            // agent = full matches user (sees the Edit door)
            ['role' => 'agent', 'permission_key' => 'access_contacts',     'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent', 'permission_key' => 'access_core_matches', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            // viewer = can reach the Core Matches page but NOT edit criteria (door hidden)
            ['role' => 'viewer', 'permission_key' => 'access_contacts',    'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thabo', 'last_name' => 'Nkosi', 'phone' => '0821234567',
        ]);
    }

    private function match(Contact $contact, User $creator): ContactMatch
    {
        return ContactMatch::create([
            'agency_id' => $this->agencyId, 'contact_id' => $contact->id,
            'created_by_user_id' => $creator->id, 'status' => 'active',
            'listing_type' => 'sale', 'name' => 'Three-bed Margate', 'price_max' => 1500000,
            'suburb' => 'Margate',
        ]);
    }

    private function editUrlFragment(Contact $c, ContactMatch $m): string
    {
        return "/contacts/{$c->id}/matches/{$m->id}/edit";
    }

    /** SURFACE A — contact record renders the Edit door. */
    public function test_contact_record_surface_renders_edit_door(): void
    {
        $agent = $this->user('agent');
        $contact = $this->contact();
        $m = $this->match($contact, $agent);

        $resp = $this->actingAs($agent)->get(route('corex.contacts.show', $contact));
        $resp->assertStatus(200);
        $resp->assertSee($this->editUrlFragment($contact, $m), false);
    }

    /** SURFACE B — Core Matches page renders the Edit door. */
    public function test_core_matches_page_renders_edit_door(): void
    {
        $agent = $this->user('agent');
        $contact = $this->contact();
        $m = $this->match($contact, $agent);

        $resp = $this->actingAs($agent)->get(route('corex.core-matches.index'));
        $resp->assertStatus(200);
        $resp->assertSee($this->editUrlFragment($contact, $m), false);
    }

    /** Permission-aware — a user without access_core_matches never sees the door. */
    public function test_edit_door_hidden_without_core_matches_permission(): void
    {
        $agent  = $this->user('agent');           // creator (so the match exists + shows for its owner)
        $viewer = $this->user('viewer');
        $contact = $this->contact();
        $m = $this->match($contact, $viewer);     // created by viewer so it renders on their index

        $resp = $this->actingAs($viewer)->get(route('corex.core-matches.index'));
        $resp->assertStatus(200);
        $resp->assertDontSee($this->editUrlFragment($contact, $m), false);
    }

    /** The door opens the existing edit flow — pre-filled form, PUT to update. */
    public function test_edit_page_renders_prefilled_edit_form(): void
    {
        $agent = $this->user('agent');
        $contact = $this->contact();
        $m = $this->match($contact, $agent);

        $resp = $this->actingAs($agent)->get(route('corex.contacts.matches.edit', [$contact, $m]));
        $resp->assertStatus(200);
        $resp->assertSee('Three-bed Margate', false);                                   // pre-filled name
        $resp->assertSee('value="PUT"', false);                                         // edit mode → PUT
        $resp->assertSee(route('corex.contacts.matches.update', [$contact, $m]), false); // posts to update
    }

    /** End-to-end — editing through the flow persists. */
    public function test_update_persists_edited_criteria(): void
    {
        $agent = $this->user('agent');
        $contact = $this->contact();
        $m = $this->match($contact, $agent);

        $resp = $this->actingAs($agent)->put(route('corex.contacts.matches.update', [$contact, $m]), [
            'listing_type' => 'sale',
            'name'         => 'Four-bed Ramsgate',
            'price_max'    => 2250000,
            'beds_min'     => 4,
        ]);
        $resp->assertRedirect(route('corex.contacts.matches.results', [$contact, $m]));

        $this->assertDatabaseHas('contact_matches', [
            'id' => $m->id, 'name' => 'Four-bed Ramsgate', 'price_max' => 2250000, 'beds_min' => 4,
        ]);
    }
}
