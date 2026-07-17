<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingPack;
use App\Models\ViewingPackProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-111 (calendar ↔ pack two-way link) + AT-112 (role/branch permission gating).
 *
 * On an unseeded grants table the permission layer takes its TEST-SUITE posture
 * (role-shaped defaults: admin=all, branch_manager=branch, agent=own) — so these
 * tests exercise the real scope logic without hand-seeding role_permissions.
 */
final class ViewingPackCalendarPermissionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchA = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchB = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Shelly', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── AT-112 — role/branch visibility ──────────────────────────────────

    public function test_an_agent_sees_only_their_own_packs(): void
    {
        $me    = $this->user('agent', $this->branchA);
        $other = $this->user('agent', $this->branchA);

        $mine   = $this->pack($me, $this->branchA);
        $theirs = $this->pack($other, $this->branchA);

        $visible = ViewingPack::query()->visibleTo($me)->pluck('id')->all();

        $this->assertContains($mine->id, $visible);
        $this->assertNotContains($theirs->id, $visible, "an own-scope agent must not see another agent's pack");
        $this->assertTrue($mine->isVisibleTo($me));
        $this->assertFalse($theirs->isVisibleTo($me));
    }

    public function test_a_branch_manager_sees_the_branch_but_not_another_branch(): void
    {
        $bm      = $this->user('branch_manager', $this->branchA);
        $inBranch = $this->pack($this->user('agent', $this->branchA), $this->branchA);
        $offBranch = $this->pack($this->user('agent', $this->branchB), $this->branchB);

        $visible = ViewingPack::query()->visibleTo($bm)->pluck('id')->all();

        $this->assertContains($inBranch->id, $visible);
        $this->assertNotContains($offBranch->id, $visible, 'branch scope must not leak another branch');
    }

    public function test_an_admin_sees_all_packs_in_the_agency(): void
    {
        $admin = $this->user('admin', $this->branchA);
        $a = $this->pack($this->user('agent', $this->branchA), $this->branchA);
        $b = $this->pack($this->user('agent', $this->branchB), $this->branchB);

        $visible = ViewingPack::query()->visibleTo($admin)->pluck('id')->all();

        $this->assertContains($a->id, $visible);
        $this->assertContains($b->id, $visible);
    }

    public function test_the_show_route_403s_a_pack_outside_the_agents_scope(): void
    {
        $me     = $this->user('agent', $this->branchA);
        $theirs = $this->pack($this->user('agent', $this->branchA), $this->branchA);

        $this->actingAs($me)
            ->get(route('corex.viewing-packs.show', $theirs))
            ->assertForbidden();
    }

    public function test_the_owner_can_open_their_own_pack(): void
    {
        $me   = $this->user('agent', $this->branchA);
        $mine = $this->pack($me, $this->branchA);

        $this->actingAs($me)
            ->get(route('corex.viewing-packs.show', $mine))
            ->assertOk();
    }

    public function test_new_packs_get_branch_id_from_the_owning_agent(): void
    {
        $agent = $this->user('agent', $this->branchB);
        $buyer = $this->contact($agent);

        $this->actingAs($agent)
            ->post(route('corex.viewing-packs.store'), ['contact_id' => $buyer->id])
            ->assertRedirect();

        $pack = ViewingPack::query()->latest('id')->first();
        $this->assertSame($this->branchB, (int) $pack->branch_id, 'BelongsToBranch must stamp the agent\'s branch');
    }

    // ── AT-111 direction 2 — launch a pack from an event ─────────────────

    public function test_launching_a_pack_from_an_event_links_them_two_way(): void
    {
        $agent = $this->user('agent', $this->branchA);
        $buyer = $this->contact($agent);
        $event = $this->viewingEvent($agent, $buyer, $this->branchA);

        $this->actingAs($agent)
            ->post(route('command-center.calendar.viewing-pack.launch', $event))
            ->assertRedirect();

        $pack = ViewingPack::query()->where('calendar_event_id', $event->id)->first();
        $this->assertNotNull($pack, 'the pack must be linked to the event');
        $this->assertSame($buyer->id, (int) $pack->contact_id, 'the pack inherits the event\'s buyer');
        // Two-way: the event resolves back to the pack.
        $this->assertSame($pack->id, $event->fresh()->viewingPack->id);
    }

    public function test_launching_twice_opens_the_same_pack_never_a_second(): void
    {
        $agent = $this->user('agent', $this->branchA);
        $event = $this->viewingEvent($agent, $this->contact($agent), $this->branchA);

        $this->actingAs($agent)->post(route('command-center.calendar.viewing-pack.launch', $event));
        $this->actingAs($agent)->post(route('command-center.calendar.viewing-pack.launch', $event));

        $this->assertSame(1, ViewingPack::query()->where('calendar_event_id', $event->id)->count());
    }

    // ── AT-111 direction 3 — update appointment in place ─────────────────

    public function test_update_appointment_pushes_ordered_properties_onto_the_linked_event(): void
    {
        $agent = $this->user('agent', $this->branchA);
        $buyer = $this->contact();
        $event = $this->viewingEvent($agent, $buyer, $this->branchA);
        $pack  = $this->pack($agent, $this->branchA, $buyer, $event->id);

        // Two properties, in a deliberate drag order (p2 first).
        $p1 = $this->property($agent);
        $p2 = $this->property($agent);
        ViewingPackProperty::create(['viewing_pack_id' => $pack->id, 'property_id' => $p2->id, 'sort_order' => 1, 'source' => 'ad_hoc']);
        ViewingPackProperty::create(['viewing_pack_id' => $pack->id, 'property_id' => $p1->id, 'sort_order' => 2, 'source' => 'ad_hoc']);

        $this->actingAs($agent)
            ->post(route('corex.viewing-packs.update-appointment', $pack))
            ->assertRedirect();

        // The event now carries BOTH properties as subject-property links.
        $linked = DB::table('calendar_event_links')
            ->where('calendar_event_id', $event->id)
            ->where('role', CalendarEventLink::ROLE_SUBJECT_PROPERTY)
            ->pluck('linkable_id')->map(fn ($v) => (int) $v)->all();

        $this->assertContains($p1->id, $linked);
        $this->assertContains($p2->id, $linked);
        $this->assertCount(2, $linked, 'no new event — the SAME event gains the pack\'s properties');
    }

    public function test_update_appointment_422s_when_the_pack_has_no_linked_event(): void
    {
        $agent = $this->user('agent', $this->branchA);
        $pack  = $this->pack($agent, $this->branchA);   // no calendar_event_id

        $this->actingAs($agent)
            ->post(route('corex.viewing-packs.update-appointment', $pack))
            ->assertStatus(422);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function user(string $role, int $branchId): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branchId, 'role' => $role,
        ]);
    }

    /**
     * A buyer contact the given agent can actually SEE — ContactScope gates an
     * own-scope agent to contacts they created (created_by_user_id). In the real
     * flow the agent already has access to the buyer they're building a pack for;
     * the test reproduces that by stamping ownership. Defaults to the branch-A
     * admin so manager-chain callers (which bypass ContactScope) also see it.
     */
    private function contact(?User $owner = null): Contact
    {
        $owner ??= $this->user('admin', $this->branchA);

        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA,
            'created_by_user_id' => $owner->id,
            'first_name' => 'Buyer', 'last_name' => Str::random(4),
            'email' => 'b-' . Str::random(5) . '@example.test',
        ]);
    }

    private function property(User $agent): Property
    {
        $id = (int) DB::table('properties')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $agent->branch_id, 'agent_id' => $agent->id,
            'external_id' => 'T-' . Str::random(6), 'title' => 'Prop', 'address' => Str::random(6) . ' Rd',
            'suburb' => 'Margate', 'price' => 1_500_000, 'status' => 'active', 'property_type' => 'house',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Property::withoutGlobalScopes()->findOrFail($id);
    }

    private function pack(User $agent, int $branchId, ?Contact $buyer = null, ?int $eventId = null): ViewingPack
    {
        $buyer ??= $this->contact();

        return ViewingPack::create([
            'agency_id'         => $this->agencyId,
            'branch_id'         => $branchId,
            'contact_id'        => $buyer->id,
            'agent_id'          => $agent->id,
            'calendar_event_id' => $eventId,
            'status'            => ViewingPack::STATUS_DRAFT,
            'title'             => 'Pack ' . Str::random(4),
        ]);
    }

    private function viewingEvent(User $agent, Contact $buyer, int $branchId): CalendarEvent
    {
        $event = CalendarEvent::create([
            'user_id'      => $agent->id,
            'created_by_id' => $agent->id,
            'agency_id'    => $this->agencyId,
            'branch_id'    => $branchId,
            'category'     => 'viewing',
            'event_type'   => 'manual',
            'source_type'  => 'manual',
            'title'        => 'Friday viewing',
            'contact_id'   => $buyer->id,
            'event_date'   => now()->addDays(3),
        ]);

        return $event;
    }
}
