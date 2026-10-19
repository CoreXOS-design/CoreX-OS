<?php

namespace Tests\Feature\RentalCrewLinks;

use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Services\Rentals\RentalCrewScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsRentalPortalFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.29 — RentalCrewScheduleService is the ONE query for
 * "this crew's open cards", the crew page's grouping and the "what to load"
 * summation.
 *
 * Input paths proven (spec §17.42 groups): Today (by time) / Scheduled (within the
 * agency window) / Waiting for you (no date, price asked, sent back) / In progress /
 * Done this month; beyond-window hidden; overdue-and-still-open kept at the top of
 * Today; every status (draft + quoted never, approved + scheduled + in progress
 * always, completed + cancelled + archived never); a card on an archived
 * property drops off; the window reads from the agency setting; TWO CREWS IN TWO AGENCIES (and two crews in one
 * agency) never see each other's cards; materials: summed per catalogue item +
 * unit, free text grouped by normalised description + unit, a different unit
 * stays separate, labour excluded, archived lines/tasks excluded, unscheduled and
 * beyond-window cards excluded, prices only with the setting, never "short of".
 */
class RentalCrewScheduleServiceTest extends TestCase
{
    use BuildsRentalPortalFixtures;
    use RefreshDatabase;

    private RentalCrewScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RentalCrewScheduleService::class);
        // A fixed, mid-day "now" so Today / Upcoming are never flaky around midnight.
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function world(): array
    {
        $agency = $this->makeAgency();
        $agent = $this->makeAgent($agency);
        $property = $this->makeProperty($agency, $agent);
        $crew = $this->makeCrew($agency, 'Team 1');

        return [$agency, $agent, $property, $crew];
    }

    private function card($agency, $property, $crew, string $title, ?string $when, string $status = 'scheduled', array $extra = []): RentalJobCard
    {
        return $this->makeJobCard($agency, $property, null, array_merge([
            'title' => $title, 'status' => $status, 'rental_crew_id' => $crew->id,
            'scheduled_at' => $when ? Carbon::parse($when, config('app.timezone')) : null,
        ], $extra));
    }

    private function titles(array $rows): array
    {
        return array_column($rows, 'title');
    }

    public function test_cards_are_grouped_into_today_scheduled_and_waiting_in_the_right_order(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->card($agency, $property, $crew, 'Today afternoon', '2026-10-14 15:00:00');
        $this->card($agency, $property, $crew, 'Today morning', '2026-10-14 08:30:00');
        $this->card($agency, $property, $crew, 'In three days', '2026-10-17 09:00:00');
        $this->card($agency, $property, $crew, 'Tomorrow', '2026-10-15 09:00:00');
        $this->card($agency, $property, $crew, 'No date b', null, 'approved', ['due_at' => Carbon::parse('2026-10-30')]);
        $this->card($agency, $property, $crew, 'No date a', null, 'approved', ['due_at' => Carbon::parse('2026-10-20')]);

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertSame(['Today morning', 'Today afternoon'], $this->titles($page['today']));
        $this->assertSame(['Tomorrow', 'In three days'], $this->titles($page['upcoming']));
        $this->assertSame(['No date a', 'No date b'], $this->titles($page['waiting']), 'waiting: earliest due date first');
        $this->assertSame('Approved — waiting for a date', $page['waiting'][0]['why_waiting']);
        $this->assertSame('08:30', $page['today'][0]['time']);
    }

    public function test_started_jobs_sit_in_progress_and_a_sent_back_job_waits_for_the_crew(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->card($agency, $property, $crew, 'Under way', '2026-10-14 09:00:00', 'in_progress');
        $this->card($agency, $property, $crew, 'Not fixed', '2026-10-12 09:00:00', 'disputed');

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertSame(['Under way'], $this->titles($page['in_progress']));
        $this->assertSame(['Not fixed'], $this->titles($page['waiting']));
        $this->assertSame('The tenant says it is not fixed yet', $page['waiting'][0]['why_waiting']);
        $this->assertSame([], $page['today'], 'each open card is in exactly one group');
    }

    public function test_a_draft_job_the_office_asked_the_crew_to_price_waits_for_the_crew(): void
    {
        [$agency, $agent, $property, $crew] = $this->world();
        $draft = $this->card($agency, $property, $crew, 'Please price', null, 'draft');
        \App\Models\RentalJobCardPriceRequest::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'rental_job_card_id' => $draft->id, 'status' => \App\Models\RentalJobCardPriceRequest::STATUS_OPEN,
            'requested_by_user_id' => $agent->id, 'requested_at' => now(),
        ]);

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertSame(['Please price'], $this->titles($page['waiting']));
        $this->assertTrue($page['waiting'][0]['to_price']);
        $this->assertSame('The office asked you to price this job', $page['waiting'][0]['why_waiting']);
    }

    public function test_every_row_carries_the_tenant_the_access_contact_and_a_maps_link(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $lease = $this->makeLease($agency, $property);
        $this->makeTenant($agency, $lease, ['first_name' => 'Tina', 'last_name' => 'Tenant', 'phone' => '083 111 2222']);
        $card = $this->card($agency, $property, $crew, 'Fix tap', '2026-10-14 09:00:00', 'scheduled', [
            'lease_id' => $lease->id, 'access_contact_name' => 'Caretaker Carl', 'access_contact_phone' => '082 555 0101', 'access_notes' => 'Gate code 4321',
        ]);

        $row = $this->service->schedule($agency->id, $crew->id)['today'][0];

        $this->assertSame($card->id, $row['id']);
        $this->assertSame('Tina Tenant', $row['tenants'][0]['name']);
        $this->assertSame('0831112222', $row['tenants'][0]['tel']);
        $this->assertSame(['Caretaker Carl', '0825550101', 'Gate code 4321'], [$row['access']['name'], $row['access']['tel'], $row['access']['note']]);
        $this->assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=', (string) $row['map_url']);

        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_link_show_tenant_contact' => false]);
        $this->assertSame([], $this->service->schedule($agency->id, $crew->id)['today'][0]['tenants'], 'the agency can keep the tenant off the crew page');
    }

    public function test_cards_beyond_the_upcoming_window_are_not_listed_until_inside_it(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->card($agency, $property, $crew, 'Inside', '2026-10-28 09:00:00');   // today + 14 days
        $this->card($agency, $property, $crew, 'Outside', '2026-10-29 09:00:00');  // today + 15 days

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertSame(['Inside'], $this->titles($page['upcoming']));
        $this->assertSame([], $page['waiting'], 'a dated job beyond the window is not "waiting"');
        $this->assertSame(14, $page['upcoming_days']);
    }

    public function test_the_upcoming_window_comes_from_the_agency_setting(): void
    {
        [$agency, , $property, $crew] = $this->world();
        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_page_upcoming_days' => 3]);
        $this->card($agency, $property, $crew, 'Day 3', '2026-10-17 09:00:00');
        $this->card($agency, $property, $crew, 'Day 4', '2026-10-18 09:00:00');

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertSame(['Day 3'], $this->titles($page['upcoming']));
    }

    public function test_an_open_card_booked_for_an_earlier_day_is_kept_at_the_top_of_today_flagged_overdue(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->card($agency, $property, $crew, 'Due later today', '2026-10-14 16:00:00');
        $this->card($agency, $property, $crew, 'Missed yesterday', '2026-10-13 09:00:00', 'scheduled');

        $today = $this->service->schedule($agency->id, $crew->id)['today'];

        $this->assertSame(['Missed yesterday', 'Due later today'], $this->titles($today));
        $this->assertTrue($today[0]['overdue']);
        $this->assertFalse($today[1]['overdue']);
    }

    public function test_only_booked_statuses_count_draft_and_quoted_never_closed_never(): void
    {
        [$agency, , $property, $crew] = $this->world();
        foreach (['approved', 'scheduled', 'in_progress'] as $status) {
            $this->card($agency, $property, $crew, "Open {$status}", '2026-10-14 11:00:00', $status);
        }
        foreach (['draft', 'quoted', 'completed', 'cancelled'] as $status) {
            $this->card($agency, $property, $crew, "Hidden {$status}", '2026-10-14 11:00:00', $status);
        }

        $page = $this->service->schedule($agency->id, $crew->id);

        $this->assertEqualsCanonicalizing(['Open approved', 'Open scheduled'], $this->titles($page['today']));
        $this->assertSame(['Open in_progress'], $this->titles($page['in_progress']));
        $this->assertSame([], $page['waiting']);
        $this->assertSame(RentalJobCard::CREW_VISIBLE_STATUSES, ['approved', 'scheduled', 'in_progress', 'disputed'], 'ONE constant decides it (disputed joined in §17.12: a reopened job must reach the crew)');
    }

    public function test_an_archived_card_or_a_card_on_an_archived_property_drops_off(): void
    {
        [$agency, $agent, $property, $crew] = $this->world();
        $gone = $this->card($agency, $property, $crew, 'Archived card', '2026-10-14 11:00:00');
        $gone->delete();
        $archivedProperty = $this->makeProperty($agency, $agent, '9 Closed Road, Uvongo');
        $this->card($agency, $archivedProperty, $crew, 'On archived property', '2026-10-14 11:00:00');
        $archivedProperty->delete();
        $this->card($agency, $property, $crew, 'Fine', '2026-10-14 11:00:00');

        $this->assertSame(['Fine'], $this->titles($this->service->schedule($agency->id, $crew->id)['today']));
    }

    public function test_a_card_that_closes_after_listing_drops_off_on_the_next_read(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $card = $this->card($agency, $property, $crew, 'Closing soon', '2026-10-14 11:00:00');
        $this->assertSame(['Closing soon'], $this->titles($this->service->schedule($agency->id, $crew->id)['today']));

        $card->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $page = $this->service->schedule($agency->id, $crew->id);
        $this->assertSame([], $page['today']);
        $this->assertSame(['Closing soon'], $this->titles($page['done']), 'and shows under done this month');
        $this->assertNull($this->service->findOpenCard($agency->id, $crew->id, $card->id));
    }

    public function test_done_this_month_lists_completed_jobs_since_the_first_and_nothing_else(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->card($agency, $property, $crew, 'Done 2 days ago', null, 'completed', ['completed_at' => now()->subDays(2)]);
        $this->card($agency, $property, $crew, 'Done last month', null, 'completed', ['completed_at' => Carbon::parse('2026-09-30 16:00:00', config('app.timezone'))]);
        $this->card($agency, $property, $crew, 'Cancelled', null, 'cancelled', ['cancelled_at' => now()]);

        $this->assertSame(['Done 2 days ago'], $this->titles($this->service->schedule($agency->id, $crew->id)['done']));
    }

    public function test_two_crews_in_two_agencies_never_see_each_others_cards(): void
    {
        [$agencyA, , $propertyA, $crewA] = $this->world();
        $agencyB = $this->makeAgency('Cape Town Rentals');
        $agentB = $this->makeAgent($agencyB);
        $propertyB = $this->makeProperty($agencyB, $agentB, '9 Kloof Street, Gardens');
        $crewB = $this->makeCrew($agencyB, 'Team 1'); // same NAME on purpose
        $mineA = $this->card($agencyA, $propertyA, $crewA, 'Agency A job', '2026-10-14 11:00:00');
        $mineB = $this->card($agencyB, $propertyB, $crewB, 'Agency B job', '2026-10-14 11:00:00');

        $this->assertSame(['Agency A job'], $this->titles($this->service->schedule($agencyA->id, $crewA->id)['today']));
        $this->assertSame(['Agency B job'], $this->titles($this->service->schedule($agencyB->id, $crewB->id)['today']));
        // Even handed the other agency's crew id with this agency's id, nothing leaks.
        $this->assertSame([], $this->service->schedule($agencyA->id, $crewB->id)['today']);
        $this->assertNull($this->service->findOpenCard($agencyA->id, $crewA->id, $mineB->id));
        $this->assertNull($this->service->findOpenCard($agencyB->id, $crewB->id, $mineA->id));
    }

    public function test_two_crews_in_one_agency_see_only_their_own(): void
    {
        [$agency, , $property, $crew1] = $this->world();
        $crew2 = $this->makeCrew($agency, 'Team 2');
        $this->card($agency, $property, $crew1, 'Team 1 job', '2026-10-14 11:00:00');
        $theirs = $this->card($agency, $property, $crew2, 'Team 2 job', '2026-10-14 11:00:00');
        $this->card($agency, $property, $crew1, 'Unassigned elsewhere', '2026-10-14 12:00:00', 'scheduled', ['rental_crew_id' => null]);

        $this->assertSame(['Team 1 job'], $this->titles($this->service->schedule($agency->id, $crew1->id)['today']));
        $this->assertNull($this->service->findOpenCard($agency->id, $crew1->id, $theirs->id));
    }

    // ── What to load ────────────────────────────────────────────────────

    public function test_materials_are_summed_per_catalogue_item_and_unit_across_today_and_upcoming(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $item = $this->makeCatalogueItem($agency, 'GEY-EL', 'Geyser element 3kW');
        $a = $this->card($agency, $property, $crew, 'Job A', '2026-10-14 09:00:00');
        $b = $this->card($agency, $property, $crew, 'Job B', '2026-10-16 09:00:00');
        $this->makeLine($a, 'part', 'Geyser element 3kW', 2, 'each', ['rental_catalogue_item_id' => $item->id, 'code' => 'GEY-EL']);
        $this->makeLine($b, 'part', 'Element (renamed on the line)', 3, 'each', ['rental_catalogue_item_id' => $item->id, 'code' => 'GEY-EL']);
        $this->makeLine($b, 'part', 'Geyser element 3kW', 1.5, 'box', ['rental_catalogue_item_id' => $item->id, 'code' => 'GEY-EL']);

        $rows = collect($this->service->schedule($agency->id, $crew->id)['materials']);
        $each = $rows->first(fn ($r) => $r['unit'] === 'each');
        $box = $rows->first(fn ($r) => $r['unit'] === 'box');

        $this->assertCount(2, $rows, 'same catalogue item but a different unit stays a separate row');
        $this->assertSame('5', $each['quantity']);
        $this->assertSame(2, $each['job_count']);
        $this->assertSame('GEY-EL', $each['code']);
        $this->assertSame('Wed 14 Oct', $each['first_date'], 'needed from the earliest job');
        $this->assertSame('1.5', $box['quantity']);
        $this->assertEqualsCanonicalizing(['Job A', 'Job B'], array_column($each['jobs'], 'title'));
    }

    public function test_free_text_parts_group_by_normalised_description_and_unit(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $a = $this->card($agency, $property, $crew, 'Job A', '2026-10-14 09:00:00');
        $b = $this->card($agency, $property, $crew, 'Job B', '2026-10-15 09:00:00');
        $this->makeLine($a, 'part', 'Tap washer  15mm', 4, 'Each');
        $this->makeLine($b, 'part', '  tap washer 15MM ', 6, 'each');
        $this->makeLine($b, 'part', 'Tap washer 20mm', 1, 'each');

        $rows = collect($this->service->schedule($agency->id, $crew->id)['materials']);

        $this->assertCount(2, $rows);
        $this->assertSame('10', $rows->first(fn ($r) => str_contains(strtolower($r['description']), '15mm'))['quantity']);
    }

    public function test_what_to_load_is_today_and_scheduled_only_not_started_or_waiting_jobs(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->makeLine($this->card($agency, $property, $crew, 'Going', '2026-10-14 09:00:00', 'in_progress'), 'part', 'Started part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Sent back', '2026-10-12 09:00:00', 'disputed'), 'part', 'Sent back part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Later', '2026-10-16 09:00:00'), 'part', 'Scheduled part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Now', '2026-10-14 09:00:00'), 'part', 'Today part', 1);

        $this->assertEqualsCanonicalizing(['Scheduled part', 'Today part'], array_column($this->service->schedule($agency->id, $crew->id)['materials'], 'description'));
    }

    public function test_labour_archived_lines_and_lines_of_archived_tasks_are_never_loaded(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $card = $this->card($agency, $property, $crew, 'Job', '2026-10-14 09:00:00');
        $this->makeLine($card, 'labour', 'Plumber hour', 3, 'hour');
        $this->makeLine($card, 'part', 'Kept part', 2, 'each');
        $this->makeLine($card, 'part', 'Archived line', 9, 'each')->delete();
        $archivedTask = $this->makeTask($card, 'Dropped task');
        $this->makeLine($card, 'part', 'Part of dropped task', 7, 'each', ['rental_job_card_task_id' => $archivedTask->id]);
        $archivedTask->delete();

        $rows = $this->service->schedule($agency->id, $crew->id)['materials'];

        $this->assertSame(['Kept part'], array_column($rows, 'description'));
    }

    public function test_unscheduled_and_beyond_window_cards_do_not_count_towards_what_to_load(): void
    {
        [$agency, , $property, $crew] = $this->world();
        $this->makeLine($this->card($agency, $property, $crew, 'Unscheduled', null, 'approved'), 'part', 'Unscheduled part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Far away', '2026-12-01 09:00:00'), 'part', 'Far part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Draft today', '2026-10-14 09:00:00', 'draft'), 'part', 'Draft part', 1);
        $this->makeLine($this->card($agency, $property, $crew, 'Real', '2026-10-14 09:00:00'), 'part', 'Real part', 1);

        $this->assertSame(['Real part'], array_column($this->service->schedule($agency->id, $crew->id)['materials'], 'description'));
    }

    /** §17.4.7 — the crew page's "what to load" shows COST (never the selling price), and only with the setting. */
    public function test_costs_appear_only_with_the_setting_and_never_the_selling_price(): void
    {
        [$agency, , $property, $crew] = $this->world();
        // Sells at 2 x R250 = R500; costs 2 x R100 = R200. A second part has a selling price but NO cost recorded.
        $card = $this->card($agency, $property, $crew, 'Job', '2026-10-14 09:00:00');
        $this->makeLine($card, 'part', 'Valve', 2, 'each', ['unit_price' => 250, 'unit_cost' => 100, 'cost_total' => 200]);
        $this->makeLine($card, 'part', 'Gasket', 1, 'each', ['unit_price' => 90]);

        $off = $this->service->schedule($agency->id, $crew->id);
        $this->assertFalse($off['show_costs']);
        $this->assertSame([null, null], array_column($off['materials'], 'total_value'));

        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_link_show_costs' => true]);
        $on = $this->service->schedule($agency->id, $crew->id);
        $this->assertTrue($on['show_costs']);
        $byName = array_column($on['materials'], 'total_value', 'description');
        $this->assertSame('200.00', $byName['Valve']);      // the cost, not 500.00
        $this->assertNull($byName['Gasket']);                // no cost recorded: nothing shown, never 0.00
    }

    public function test_a_crew_with_nothing_booked_gets_empty_lists_not_an_error(): void
    {
        [$agency, , , $crew] = $this->world();

        $page = $this->service->schedule($agency->id, $crew->id);

        foreach (['today', 'upcoming', 'waiting', 'in_progress', 'done', 'materials'] as $k) {
            $this->assertSame([], $page[$k], $k);
        }
    }

    public function test_link_status_per_crew(): void
    {
        [$agency, $agent, , $crew] = $this->world();
        $never = $this->makeCrew($agency, 'Never linked');
        $inactive = $this->makeCrew($agency, 'Inactive crew');
        $revoked = $this->makeCrew($agency, 'Revoked crew');
        $tokens = app(\App\Services\Rentals\RentalSecureAccessTokenService::class);
        $tokens->issueForCrew($crew, $agent);
        $tokens->issueForCrew($inactive, $agent);
        $inactive->forceFill(['is_active' => false])->save();
        $tokens->issueForCrew($revoked, $agent);
        $tokens->revokeAllFor($revoked);

        $s = $this->service->linkStatusesFor([$crew->id, $never->id, $inactive->id, $revoked->id]);

        $this->assertSame('live', $s[$crew->id]['state']);
        $this->assertSame('never', $s[$never->id]['state']);
        $this->assertSame('unavailable', $s[$inactive->id]['state']);
        $this->assertSame('revoked', $s[$revoked->id]['state']);
        $this->assertSame([], $this->service->linkStatusesFor([]));
    }

    /** §17.42 — the home-link line: when it was last sent and how many times it was opened, across the crew's current links. */
    public function test_link_status_carries_the_sent_date_and_open_count_of_the_current_links(): void
    {
        [$agency, $agent, , $crew] = $this->world();
        $tokens = app(\App\Services\Rentals\RentalSecureAccessTokenService::class);
        $fresh = $tokens->issueForCrew($crew, $agent)['token'];

        $s = $this->service->linkStatusesFor([$crew->id])[$crew->id];
        $this->assertSame([0, null, null], [$s['open_count'], $s['sent_at'], $s['sent_via']], 'never sent, never opened');

        foreach ([\App\Models\RentalCrewLinkEvent::EVENT_OPENED, \App\Models\RentalCrewLinkEvent::EVENT_OPENED, \App\Models\RentalCrewLinkEvent::EVENT_EMAILED] as $event) {
            \App\Models\RentalCrewLinkEvent::record($event, $agency->id, $crew->id, $fresh->id, null, 'test', $agent);
        }
        $s = $this->service->linkStatusesFor([$crew->id])[$crew->id];
        $this->assertSame(2, $s['open_count']);
        $this->assertSame('email', $s['sent_via']);
        $this->assertNotNull($s['sent_at']);

        Carbon::setTestNow(now()->addMinute());
        \App\Models\RentalCrewLinkEvent::record(\App\Models\RentalCrewLinkEvent::EVENT_WHATSAPP, $agency->id, $crew->id, $fresh->id, null, 'test', $agent);
        $this->assertSame('whatsapp', $this->service->linkStatusesFor([$crew->id])[$crew->id]['sent_via'] ?? null, 'the latest send wins');
    }

    public function test_regenerate_starts_a_clean_sent_and_opened_history(): void
    {
        [$agency, $agent, , $crew] = $this->world();
        $tokens = app(\App\Services\Rentals\RentalSecureAccessTokenService::class);
        $old = $tokens->issueForCrew($crew, $agent)['token'];
        \App\Models\RentalCrewLinkEvent::record(\App\Models\RentalCrewLinkEvent::EVENT_OPENED, $agency->id, $crew->id, $old->id, null, 'test', $agent);
        \App\Models\RentalCrewLinkEvent::record(\App\Models\RentalCrewLinkEvent::EVENT_EMAILED, $agency->id, $crew->id, $old->id, null, 'test', $agent);

        $tokens->issueForCrew($crew, $agent); // regenerate: the old link is revoked

        $s = $this->service->linkStatusesFor([$crew->id])[$crew->id];
        $this->assertSame([0, null], [$s['open_count'], $s['sent_at']]);
    }
}
