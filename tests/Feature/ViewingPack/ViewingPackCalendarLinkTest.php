<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Http\Controllers\CommandCenter\ViewingPackController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingPack;
use App\Models\ViewingPackProperty;
use App\Services\CommandCenter\CalendarEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-111 — Viewing Pack ↔ Calendar two-way link (schedule-now-prep-later).
 *
 * Reverse direction only (the forward pack→calendar prefill handoff is unchanged):
 *   - launchFromEvent: an existing appointment spawns (or re-opens) ONE linked pack.
 *   - updateAppointment: the pack's finalised properties (drag order) are pushed
 *     onto the linked event IN PLACE — replace, not append; no new event.
 */
final class ViewingPackCalendarLinkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC ' . uniqid(), 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->buyer  = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Steve', 'last_name' => 'Buyer', 'phone' => '0830001111']);
        Auth::setUser($this->agent);
    }

    private function property(string $title): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'external_id' => 'AT111-' . uniqid(), 'title' => $title,
        ]);
    }

    private function event(): CalendarEvent
    {
        return CalendarEvent::create([
            'event_type' => 'manual', 'category' => 'viewing', 'title' => 'Viewing — Steve',
            'event_date' => now()->addDays(7)->setTime(13, 0), 'status' => 'pending', 'source_type' => 'manual',
            'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->buyer->id,
        ]);
    }

    private function request(): Request
    {
        $req = Request::create('/x', 'POST');
        $req->setUserResolver(fn () => $this->agent);

        return $req;
    }

    public function test_launch_from_event_links_one_pack_and_is_create_or_open(): void
    {
        $event = $this->event();
        $ctl   = app(ViewingPackController::class);

        $ctl->launchFromEvent($this->request(), $event);
        $pack = ViewingPack::where('calendar_event_id', $event->id)->first();

        $this->assertNotNull($pack, 'an appointment launches a linked pack');
        $this->assertSame($event->id, (int) $pack->calendar_event_id);
        $this->assertSame($this->buyer->id, (int) $pack->contact_id, 'pack is anchored to the event buyer');
        $this->assertNotNull($pack->tour_at, 'tour time seeded from the event');

        // Create-or-open: a second launch never mints a second pack.
        $ctl->launchFromEvent($this->request(), $event);
        $this->assertSame(1, ViewingPack::where('calendar_event_id', $event->id)->count());
    }

    public function test_update_appointment_pushes_ordered_properties_in_place(): void
    {
        $event = $this->event();
        $ctl   = app(ViewingPackController::class);
        $ctl->launchFromEvent($this->request(), $event);
        $pack = ViewingPack::where('calendar_event_id', $event->id)->first();

        $p1 = $this->property('11 Ocean View');
        $p2 = $this->property('22 Cliff Road');
        // Drag order: p2 first (sort_order 1), p1 second.
        ViewingPackProperty::create(['agency_id' => $this->agency->id, 'viewing_pack_id' => $pack->id, 'property_id' => $p2->id, 'sort_order' => 1, 'source' => 'ad_hoc']);
        ViewingPackProperty::create(['agency_id' => $this->agency->id, 'viewing_pack_id' => $pack->id, 'property_id' => $p1->id, 'sort_order' => 2, 'source' => 'ad_hoc']);

        $ctl->updateAppointment($this->request(), $pack->fresh(), app(CalendarEventService::class));

        $links = DB::table('calendar_event_links')
            ->where('calendar_event_id', $event->id)->where('role', 'subject_property')
            ->orderBy('id')->pluck('linkable_id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$p2->id, $p1->id], $links, 'properties pushed onto the event in drag order');
        $this->assertSame($p2->id, (int) $event->fresh()->property_id, 'primary property = first in drag order');

        // Idempotent: re-push replaces, never appends.
        $ctl->updateAppointment($this->request(), $pack->fresh(), app(CalendarEventService::class));
        $this->assertSame(2, DB::table('calendar_event_links')->where('calendar_event_id', $event->id)->where('role', 'subject_property')->count());
    }
}
