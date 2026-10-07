<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactAuditLog;
use App\Models\ContactMatch;
use App\Models\ContactMatchReassignment;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Buyers\BuyerLeadCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Johan, 2026-10-07 — rulings A–D on who a buyer's primary agent is.
 *
 *  A. First agent to receive a lead = primary agent; a later lead to another
 *     agent never changes it, never reads as "moved"; Core Matches says
 *     "Also enquired with …" (information only).
 *  B. A reassignment happens only when a user does it by hand, and is logged.
 *  C. A manual move (and the primary-agent field on the contact edit screen)
 *     moves ALL the buyer's saved searches, in one transaction.
 *  D. The buyer's existing link follows the move (name, details) — no new link.
 *  + the "Move buyer to another agent" action, managers only.
 */
final class BuyerPrimaryAgentRulingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $manager;
    private User $maggie;
    private User $retha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Rulings Co', 'slug' => 'rul-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        // Real permission resolution: managers hold these, agents hold nothing.
        foreach (['core_matches.reassign', 'core_matches.view', 'core_matches.all_view', 'access_contacts', 'contacts.reassign_agent'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => null]);
        }
        foreach (['core_matches.view', 'access_contacts'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => null]);
        }

        $this->manager = $this->makeUser('branch_manager', 'Manny Manager');
        $this->maggie  = $this->makeUser('agent', 'Maggie Venter', ['cell' => '0821110000']);
        $this->retha   = $this->makeUser('agent', 'Retha Kelly', ['cell' => '0832220000', 'email' => 'retha@example.test']);
    }

    private function makeUser(string $role, string $name, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => $role,
            'name'      => $name,
            'is_active' => true,
        ], $extra));
    }

    private function makeBuyer(User $primary): Contact
    {
        return Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $primary->id,
            'agent_id'           => $primary->id,
            'first_name'         => 'Buyer' . random_int(100, 999),
            'last_name'          => 'Test',
            'is_buyer'           => true,
            'buyer_state'        => 'warm',
            'phone'              => '083' . random_int(1000000, 9999999),
        ]);
    }

    private function makeSearch(Contact $contact, User $owner, string $name = 'Search'): ContactMatch
    {
        return ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $owner->id,
            'name'               => $name,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
        ]);
    }

    private function buyerTypeId(): int
    {
        return (int) (ContactType::query()->parents()->value('id')
            ?? ContactType::create(['name' => 'Buyer', 'esign_role' => 'buyer', 'is_active' => true, 'sort_order' => 1])->id);
    }

    // ── C: all searches move ─────────────────────────────────────────────

    public function test_reassigning_moves_every_saved_search_and_the_primary_agent_together(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $s1 = $this->makeSearch($buyer, $this->maggie, 'One');
        $s2 = $this->makeSearch($buyer, $this->maggie, 'Two');
        $s3 = $this->makeSearch($buyer, $this->retha, 'Three (created by another agent)');
        $other = $this->makeSearch($this->makeBuyer($this->maggie), $this->maggie, 'Someone else');

        $s1->reassignTo($this->retha, $this->manager, 'Maggie is on leave.');

        $this->assertSame($this->retha->id, $buyer->fresh()->agent_id);
        foreach ([$s1, $s2, $s3] as $s) {
            $this->assertSame($this->retha->id, $s->fresh()->agent_id, 'every search of the buyer follows');
        }
        $this->assertSame($this->maggie->id, $other->fresh()->agent_id, "another buyer's search is untouched");

        // One reassignment record per search that changed owner (s3 was already Retha's).
        $this->assertSame(2, ContactMatchReassignment::count());
        $this->assertSame(0, ContactMatchReassignment::where('contact_match_id', $s3->id)->count());
        $this->assertSame($this->manager->id, (int) ContactMatchReassignment::where('contact_match_id', $s2->id)->value('moved_by_user_id'));
    }

    public function test_the_buyer_level_route_moves_everything_for_a_manager_and_logs_it(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $s1 = $this->makeSearch($buyer, $this->maggie);
        $s2 = $this->makeSearch($buyer, $this->maggie);

        $this->actingAs($this->manager)->post(route('corex.core-matches.reassign-buyer', $buyer), [
            'to_agent_id' => $this->retha->id,
            'reason'      => 'Handover.',
        ])->assertRedirect();

        $this->assertSame($this->retha->id, $buyer->fresh()->agent_id);
        $this->assertSame($this->retha->id, $s1->fresh()->agent_id);
        $this->assertSame($this->retha->id, $s2->fresh()->agent_id);

        $row = ContactAuditLog::where('contact_id', $buyer->id)->where('event_type', 'agent_assigned')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->manager->id, (int) $row->user_id, 'who');
        $this->assertSame($this->maggie->id, (int) $row->old_values['agent_id'], 'from');
        $this->assertSame($this->retha->id, (int) $row->new_values['agent_id'], 'to');
    }

    public function test_an_agent_cannot_move_a_buyer_by_direct_url_and_nothing_changes(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $search = $this->makeSearch($buyer, $this->maggie);

        $this->actingAs($this->maggie)->post(route('corex.core-matches.reassign-buyer', $buyer), [
            'to_agent_id' => $this->retha->id,
            'reason'      => 'Trying anyway.',
        ])->assertForbidden();

        $this->assertSame($this->maggie->id, $buyer->fresh()->agent_id);
        $this->assertSame($this->maggie->id, $search->fresh()->agent_id);
        $this->assertSame(0, ContactMatchReassignment::count());
    }

    public function test_a_buyer_from_another_agency_cannot_be_moved(): void
    {
        $other = Agency::create(['name' => 'Other Co', 'slug' => 'oth-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
        $foreign = Contact::create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'created_by_user_id' => $otherAgent->id,
            'agent_id' => $otherAgent->id, 'first_name' => 'Far', 'last_name' => 'Away', 'is_buyer' => true,
            'phone' => '083' . random_int(1000000, 9999999),
        ]);

        $this->actingAs($this->manager)->post(route('corex.core-matches.reassign-buyer', $foreign), [
            'to_agent_id' => $this->retha->id,
            'reason'      => 'Cross agency.',
        ])->assertNotFound();

        $this->assertSame($otherAgent->id, $foreign->fresh()->agent_id);
    }

    public function test_a_manager_changing_the_primary_agent_on_the_contact_screen_moves_all_searches(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $s1 = $this->makeSearch($buyer, $this->maggie);
        $s2 = $this->makeSearch($buyer, $this->maggie);

        $this->actingAs($this->manager)->put(route('corex.contacts.update', $buyer), [
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name'   => $buyer->first_name,
            'last_name'    => $buyer->last_name,
            'agent_id'     => $this->retha->id,
            'parent_type_ids' => [$this->buyerTypeId()],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->retha->id, $buyer->fresh()->agent_id);
        $this->assertSame($this->retha->id, $s1->fresh()->agent_id);
        $this->assertSame($this->retha->id, $s2->fresh()->agent_id);
        $this->assertSame(2, ContactMatchReassignment::count());
    }

    public function test_the_contact_screen_edit_without_an_agent_change_moves_nothing(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $s1 = $this->makeSearch($buyer, $this->retha, 'Created by Retha');

        $this->actingAs($this->manager)->put(route('corex.contacts.update', $buyer), [
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name'   => 'Renamed',
            'last_name'    => $buyer->last_name,
            'agent_id'     => $this->maggie->id,
            'parent_type_ids' => [$this->buyerTypeId()],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->retha->id, $s1->fresh()->agent_id, 'unchanged primary → searches not touched');
        $this->assertSame(0, ContactMatchReassignment::count());
    }

    public function test_the_whole_buyer_move_rolls_back_if_any_step_fails(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $s1 = $this->makeSearch($buyer, $this->maggie);
        $s2 = $this->makeSearch($buyer, $this->maggie);

        // Fail on the SECOND search's save, after the primary agent and the first search moved.
        $calls = 0;
        ContactMatch::saving(function (ContactMatch $m) use (&$calls) {
            if ($m->isDirty('agent_id') && ++$calls === 2) {
                throw new \RuntimeException('simulated failure');
            }
        });

        try {
            $s1->reassignTo($this->retha, $this->manager, 'Should not stick.');
            $this->fail('expected the simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame($this->maggie->id, $buyer->fresh()->agent_id, 'primary agent rolled back');
        $this->assertSame($this->maggie->id, $s1->fresh()->agent_id);
        $this->assertSame($this->maggie->id, $s2->fresh()->agent_id);
        $this->assertSame(0, ContactMatchReassignment::count());
    }

    // ── A: first lead is primary; a later lead never moves it ─────────────

    public function test_a_later_enquiry_on_another_agents_listing_seeds_the_search_under_the_primary_agent(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $retha = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->retha->id, 'title' => 'Retha listing',
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1_500_000, 'beds' => 3, 'garages' => 1,
            'property_type' => 'House',
        ]);

        // Intake passes the LISTING agent (Retha) as owner — the rule must still pick Maggie.
        $seeded = app(BuyerLeadCascadeService::class)->seedFromListing(
            $buyer, $retha, $this->retha->id, BuyerLeadCascadeService::SOURCE_PORTAL_P24, 'Is it still available?'
        );

        $this->assertNotNull($seeded, 'a search was seeded');
        $this->assertSame($this->maggie->id, $seeded->agent_id, 'owned by the primary agent, not the second listing agent');
        $this->assertSame($this->maggie->id, $buyer->fresh()->agent_id, 'primary agent unchanged');
        $this->assertSame(0, ContactMatchReassignment::count(), 'never recorded as a reassignment');
    }

    public function test_a_first_lead_still_belongs_to_the_listing_agent(): void
    {
        // A brand-new contact's primary agent IS the listing agent (observer default).
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->retha->id,
            'first_name' => 'Fresh', 'last_name' => 'Lead', 'phone' => '083' . random_int(1000000, 9999999),
        ]);
        $listing = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->retha->id, 'title' => 'Retha listing',
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1_500_000, 'beds' => 3, 'garages' => 1,
            'property_type' => 'House',
        ]);

        $seeded = app(BuyerLeadCascadeService::class)->seedFromListing(
            $contact, $listing, $this->retha->id, BuyerLeadCascadeService::SOURCE_PORTAL_P24, null
        );

        $this->assertSame($this->retha->id, $contact->fresh()->agent_id);
        $this->assertSame($this->retha->id, $seeded?->agent_id);
    }

    // ── A: the Core Matches display ──────────────────────────────────────

    private function board()
    {
        return $this->actingAs($this->manager)->get(route('corex.core-matches.index', ['scope' => 'agency']));
    }

    public function test_the_board_says_also_enquired_with_for_a_lead_on_another_agents_listing(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $this->makeSearch($buyer, $this->maggie);
        $listing = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->retha->id, 'title' => 'Retha listing',
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1_500_000,
        ]);
        PortalLead::create([
            'agency_id' => $this->agency->id, 'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'enquiry',
            'listing_id' => $listing->id, 'contact_id' => $buyer->id, 'name' => 'Lead',
            'lead_source_raw' => [], 'received_at' => now()->subHour(),
        ]);

        $this->board()->assertOk()
            ->assertSee('Also enquired with Retha Kelly', false)
            ->assertDontSee('Reassigned', false)
            ->assertDontSee('Search created by', false);
    }

    public function test_no_also_enquired_line_when_every_lead_went_to_the_primary_agent(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $this->makeSearch($buyer, $this->maggie);
        $mine = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->maggie->id, 'title' => 'Maggie listing',
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1_500_000,
        ]);
        PortalLead::create([
            'agency_id' => $this->agency->id, 'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'enquiry',
            'listing_id' => $mine->id, 'contact_id' => $buyer->id, 'name' => 'Lead',
            'lead_source_raw' => [], 'received_at' => now()->subHour(),
        ]);

        $this->board()->assertOk()->assertDontSee('Also enquired with', false);
    }

    public function test_the_board_shows_reassigned_only_after_a_real_manual_move(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $search = $this->makeSearch($buyer, $this->maggie);
        $this->board()->assertOk()->assertDontSee('Reassigned', false);

        $search->reassignTo($this->retha, $this->manager, 'Handover.');

        $this->board()->assertOk()
            ->assertSee('Reassigned', false)
            ->assertSee('from Maggie Venter', false)
            ->assertSee('to Retha Kelly', false);
    }

    public function test_the_move_buyer_button_is_for_managers_only(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $this->makeSearch($buyer, $this->maggie);

        $this->board()->assertOk()->assertSee('Move buyer', false);
        $this->actingAs($this->maggie)->get(route('corex.core-matches.index', ['scope' => 'own']))
            ->assertOk()->assertDontSee('Move buyer', false);
    }

    // ── D: the buyer's existing link follows the move ────────────────────

    public function test_the_existing_shared_link_shows_the_new_agent_after_a_move_and_keeps_working(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $search = $this->makeSearch($buyer, $this->maggie);
        $token = $search->share_token;

        $before = $this->get(route('shared.match', ['token' => $token]))->assertOk();
        $before->assertSee('Maggie Venter', false)->assertSee('0821110000', false);

        $search->reassignTo($this->retha, $this->manager, 'Handover.');

        // Same URL, no new link issued.
        $this->assertSame($token, $search->fresh()->share_token);
        $after = $this->get(route('shared.match', ['token' => $token]))->assertOk();
        $after->assertSee('Retha Kelly', false)
            ->assertSee('0832220000', false)
            ->assertSee('retha@example.test', false)
            ->assertDontSee('Maggie Venter', false);
    }

    public function test_the_link_follows_the_primary_agent_even_when_another_agent_created_the_search(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $search = $this->makeSearch($buyer, $this->retha, 'Created by Retha');

        $this->get(route('shared.match', ['token' => $search->share_token]))->assertOk()
            ->assertSee('Maggie Venter', false);
    }

    public function test_the_buyer_portal_shows_the_current_primary_agent_not_the_link_generator(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $this->makeSearch($buyer, $this->maggie);
        $token = bin2hex(random_bytes(16));
        DB::table('buyer_portal_links')->insert([
            'contact_id' => $buyer->id, 'agency_id' => $this->agency->id, 'token' => $token,
            'generated_by_user_id' => $this->maggie->id, 'generated_at' => now(), 'access_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get("/buyer/portal/{$token}")->assertOk()->assertSee('Maggie Venter', false);

        $buyer->forceFill(['agent_id' => $this->retha->id])->saveQuietly();

        $this->get("/buyer/portal/{$token}")->assertOk()
            ->assertSee('Retha Kelly', false)
            ->assertDontSee('Maggie Venter', false);
    }

    // ── Buyer Pipeline card ──────────────────────────────────────────────

    public function test_the_buyer_pipeline_offers_move_to_another_agent_to_managers_only(): void
    {
        $buyer = $this->makeBuyer($this->maggie);
        $this->makeSearch($buyer, $this->maggie);
        $buyer->forceFill(['buyer_pipeline_entered_at' => now()])->saveQuietly();

        $this->actingAs($this->manager)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))
            ->assertOk()->assertSee('Move to another agent', false);
        $this->actingAs($this->maggie)->get(route('command-center.buyers.pipeline'))
            ->assertOk()->assertDontSee('Move to another agent', false);
    }
}
