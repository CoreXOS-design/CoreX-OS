<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 round 2, item F (.ai/specs/rentals-faults-work-orders.md §19.7): "That fixed it" is a fault the AGENT can see was closed by the tenant,
 * never a fault in progress - not on the tenant's Faults tab as an active card, not on the owner's portal, not in the review queue or the open
 * counts, and no alert asks anyone to act. A tenant who just leaves the advice leaves no record.
 */
final class TenantSelfResolvedFaultTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Self Fix Agency', 'slug' => 'sf-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'SF Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Lock / keys', 'category' => 'Security', 'urgency' => 'routine', 'first_aid_steps' => 'Try the spare key.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    private function submit(string $resolution, array $extra = [])
    {
        Sanctum::actingAs($this->login($this->tenant), ['client']);

        return $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", array_merge([
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => $resolution, 'title' => 'Lock / keys', 'description' => '',
        ], $extra));
    }

    public function test_that_fixed_it_is_logged_closed_and_asks_nobody_to_act(): void
    {
        $fired = [];
        $spy = \Mockery::mock(NotificationDispatcher::class);
        $spy->shouldReceive('fire')->andReturnUsing(function ($u, $key) use (&$fired) { $fired[] = $key; return true; });
        $this->app->instance(NotificationDispatcher::class, $spy);

        $this->submit('first_aid_resolved')->assertCreated()->assertJsonPath('fault_report.self_resolved', true)->assertJsonPath('fault_report.progress', null);

        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID, false], [$f->status, $f->outcome, (bool) $f->outcome_set_automatically]);
        $this->assertNotNull($f->resolved_at);
        $this->assertTrue($f->isTenantSelfResolved());
        $this->assertSame('Resolved by tenant - tried the suggested fix', $f->statusLabel());
        $this->assertSame($this->tenant->id, (int) $f->reported_by_contact_id);
        $this->assertNull($f->sent_to_owner_at);

        $this->assertNotContains('rental_fault_report.created', $fired, 'no "fault reported" alert asking the agent to act');
        $this->assertSame([], $fired, 'no alert of any kind');
        $this->assertSame(0, \App\Models\RentalFaultReport::withoutGlobalScopes()->where('status', RentalFaultReport::STATUS_REPORTED)->count(), 'nothing for the Review fault queue');
        $this->assertSame(0, app(LeaseHubService::class)->openItemCounts($this->lease)['faults'], 'not an open fault on the lease');
        $this->assertContains('outcome_set', $f->updates()->pluck('update_type')->all(), 'the history says what happened');
    }

    public function test_the_agent_sees_it_as_resolved_by_the_tenant_with_nothing_to_work_and_the_tenancy_log_has_an_informational_line(): void
    {
        $this->submit('first_aid_resolved')->assertCreated();
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();

        $list = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Resolved by tenant - tried the suggested fix', $list);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('data-tenant-self-resolved', $html);
        $this->assertStringContainsString('Resolved by tenant - tried the suggested fix', $html);
        foreach (['owner-version-card', 'data-appoint-contractor', 'raise-work-order-form', 'data-no-contractor-warning'] as $action) {
            $this->assertStringNotContainsString($action, $html, "no {$action} on a closed, tenant-resolved fault");
        }

        $entry = app(LeaseTimelineService::class)->allEntriesFor($this->lease)->firstWhere('type', 'fault');
        $this->assertStringContainsString('resolved by the tenant', strtolower($entry['description']));
    }

    public function test_the_tenant_gets_the_flags_for_one_collapsed_line_and_the_owner_sees_it_as_resolved_without_a_repair(): void
    {
        $this->submit('first_aid_resolved', ['description' => 'The key was stuck, I jiggled it'])->assertCreated();
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();

        Sanctum::actingAs($this->login($this->tenant), ['client']);
        $row = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports.0');
        $this->assertTrue($row['self_resolved']);
        $this->assertTrue($row['closed']);
        $this->assertSame('you fixed this yourself', $row['end_state']);
        $this->assertNull($row['progress'], 'no timeline for a fault the tenant fixed');
        $this->assertSame($this->faultType->id, $row['fault_type_id'], 'so "Report it again" can start a new report of the same type');
        $this->assertSame('Lock / keys', $row['fault_type_name']);
        $this->assertNotNull($row['resolved_at']);
        $this->assertNotNull($row['reported_at'], 'every row shows its reported date');

        // F-OWNER (Johan's correction): the owner DOES see it - in the list, as a closed one-liner, not under "Needs your decision"
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $list = $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->json('fault_reports');
        $this->assertCount(1, $list);
        $o = $list[0];
        $this->assertTrue($o['self_resolved']);
        $this->assertTrue($o['closed']);
        $this->assertFalse($o['needs_decision'], 'no badge demanding action');
        $this->assertSame('resolved without a repair', $o['end_state']);
        $this->assertSame('Lock / keys', $o['type_name']);
        $this->assertStringContainsString('Try the spare key', (string) $o['advice'], 'the advice text the tenant was shown');
        $this->assertSame('The key was stuck, I jiggled it', $o['tenant_description']);
        $this->assertNotEmpty($o['property']);
        $this->assertNull($o['repair_asked']);

        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->assertOk()->json('fault_report');
        $this->assertTrue($show['self_resolved']);
        $this->assertFalse($show['awaiting_decision']);
        $this->assertNull($show['decision']);
        $this->assertSame([], $show['photos']);

        // ...and nothing is pushed to them: no email, no alert, and the "decisions waiting" count is untouched
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, $this->getJson('/api/v1/client/rentals/landlord/decisions')->json('fault_reports') ? count($this->getJson('/api/v1/client/rentals/landlord/decisions')->json('fault_reports')) : 0);
    }

    public function test_have_this_repaired_anyway_from_the_owner_makes_a_linked_normal_request_and_leaves_the_resolved_report_alone(): void
    {
        $this->submit('first_aid_resolved')->assertCreated();
        $resolved = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $before = $resolved->fresh()->getAttributes();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        // an invented id, another property's or a normal fault's id cannot be followed
        $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", ['title' => 'x', 'follows_fault_report_id' => 999999], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", [
            'title' => 'Lock / keys', 'rental_fault_type_id' => $this->faultType->id, 'description' => '', 'follows_fault_report_id' => $resolved->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $new = RentalFaultReport::withoutGlobalScopes()->where('id', '!=', $resolved->id)->firstOrFail();
        $this->assertSame($resolved->id, (int) $new->follows_fault_report_id, 'linked to the report it follows');
        $this->assertSame(RentalFaultReport::REPORTED_BY_LANDLORD, $new->reported_by_type, 'a normal owner work request: the existing flow');
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $new->status, 'AT-452 §19.14: an owner\'s request IS their authority to go to a quote');
        $this->assertStringContainsString('Follows the report of', $new->description);
        $this->assertSame($before, $resolved->fresh()->getAttributes(), 'the resolved report stays exactly as it was');

        $o = $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports');
        $resolvedRow = collect($o)->firstWhere('id', $resolved->id);
        $this->assertSame($new->id, $resolvedRow['repair_asked']['id'], '"Owner asked for a repair on <date>" with the link');
    }

    public function test_the_agent_can_have_it_repaired_anyway_once_through_the_existing_flow(): void
    {
        $this->submit('first_aid_resolved')->assertCreated();
        $resolved = RentalFaultReport::withoutGlobalScopes()->firstOrFail();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $resolved))->getContent();
        $this->assertStringContainsString('data-repair-anyway', $html);

        $r = $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.repair-anyway', $resolved));
        $new = RentalFaultReport::withoutGlobalScopes()->where('id', '!=', $resolved->id)->firstOrFail();
        $r->assertRedirect(route('corex.rental-fault-reports.show', $new));
        $this->assertSame([RentalFaultReport::STATUS_REPORTED, $resolved->id], [$new->status, (int) $new->follows_fault_report_id]);
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $resolved->fresh()->status);

        // pressing it again goes to the same request - never a second one
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.repair-anyway', $resolved))->assertRedirect(route('corex.rental-fault-reports.show', $new));
        $this->assertSame(2, RentalFaultReport::withoutGlobalScopes()->count());

        // the resolved record now says so, and the new one says what it follows
        $this->assertStringContainsString('data-repair-asked', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $resolved))->getContent());
        $this->assertStringNotContainsString('data-repair-anyway', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $resolved))->getContent());
        $this->assertStringContainsString('data-follows-report', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $new))->getContent());

        // a normal fault cannot be "repaired anyway"
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.repair-anyway', $new))->assertSessionHasErrors('rental_fault_report');
    }

    public function test_a_repeat_fault_says_reported_before_inside_the_window_for_agent_and_owner(): void
    {
        $this->submit('first_aid_resolved')->assertCreated();
        $resolved = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $resolved->forceFill(['reported_at' => now()->subDays(30)])->saveQuietly();

        // the tenant reports it again (a normal report, sent to the owner)
        Sanctum::actingAs($this->login($this->tenant), ['client']);
        $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Lock / keys', 'description' => 'It jams again', 'follows_fault_report_id' => $resolved->id,
        ])->assertCreated();
        $new = RentalFaultReport::withoutGlobalScopes()->where('id', '!=', $resolved->id)->firstOrFail();
        $this->assertSame($resolved->id, (int) $new->follows_fault_report_id, '"Report it again" links back');

        $before = $new->reportedBefore();
        $this->assertSame('tenant_self', $before['how']);
        $this->assertStringContainsString('the tenant resolved it then with the suggested step', $before['text']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $new))->getContent();
        $this->assertStringContainsString('data-reported-before', $html);
        $this->assertStringContainsString('Reported before on', $html);

        // the owner's decision screen carries it too, linked, once the agent has sent it
        $new->saveOwnerVersion(['owner_title' => 'Lock jams', 'owner_description' => 'Again'], $this->agent);
        $new->fresh()->requestApproval($this->agent);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $rb = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$new->id}")->json('fault_report.reported_before');
        $this->assertStringContainsString('Reported before on', $rb['text']);
        $this->assertSame($resolved->id, $rb['fault_id'], 'the owner can open the earlier report');
    }

    public function test_outside_the_agency_window_an_unlinked_tenant_resolved_report_is_not_flagged_but_a_repair_always_is(): void
    {
        $this->submit('first_aid_resolved')->assertCreated();
        $old = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $old->forceFill(['reported_at' => now()->subDays(120)])->saveQuietly();   // default window is 90 days
        $mk = fn (array $o = []) => RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'rental_fault_type_id' => $this->faultType->id,
            'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app', 'title' => 'Lock / keys', 'description' => 'again',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(),
        ], $o));
        $new = $mk();
        $this->assertNull($new->reportedBefore(), '120 days > the 90-day window, and not linked');

        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_recurrence_window_days' => 200]);
        $this->assertSame('tenant_self', $new->reportedBefore()['how'], 'the agency widened the window');

        // any earlier fault of the type, repaired - flagged whenever it was
        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_recurrence_window_days' => 1]);
        $new->forceFill(['status' => 'cancelled'])->save();   // the most recent earlier fault is the one that is named; cancelled ones are not
        $old->forceFill(['status' => 'cancelled'])->saveQuietly();
        $repaired = $mk(['status' => 'resolved', 'outcome' => 'repaired', 'reported_at' => now()->subDays(400), 'resolved_at' => now()->subDays(399)]);
        $newer = $mk(['reported_at' => now()]);
        $this->assertSame('repaired', $newer->reportedBefore()['how']);
        $this->assertStringContainsString('repaired then', $newer->reportedBefore()['text']);
        $this->assertSame($repaired->id, $newer->reportedBefore()['id']);
    }

    public function test_the_recurrence_window_setting_saves_has_guarded_and_is_in_the_wizard(): void
    {
        $this->assertSame(90, \App\Models\RentalPortalSetting::faultRecurrenceWindowDaysFor($this->agency->id), 'default 90 days');
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/fault-recurrence-window-days', ['fault_recurrence_window_days' => 45])->assertRedirect();
        $this->assertSame(45, \App\Models\RentalPortalSetting::faultRecurrenceWindowDaysFor($this->agency->id));
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/fault-recurrence-window-days', [])->assertRedirect();
        $this->assertSame(45, \App\Models\RentalPortalSetting::faultRecurrenceWindowDaysFor($this->agency->id), 'a post without the field leaves it alone');
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/fault-recurrence-window-days', ['fault_recurrence_window_days' => 9999])->assertSessionHasErrors('fault_recurrence_window_days');
        $this->assertStringContainsString('updateFaultRecurrenceWindowDays', json_encode(config('agency-onboarding-copy')));
    }

    public function test_still_a_problem_is_the_normal_report_and_a_cancelled_advice_leaves_no_record(): void
    {
        // "Cancel" in the wizard is a client-side close: no request is made, so no record exists
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
        Sanctum::actingAs($this->login($this->tenant), ['client']);
        $this->getJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-types")->assertOk();   // reading the advice
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count(), 'reading the advice stores nothing');

        $this->submit('still_a_problem')->assertCreated()->assertJsonPath('fault_report.self_resolved', false);
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $f->status);
        $this->assertFalse($f->isTenantSelfResolved());
        $this->assertSame(1, app(LeaseHubService::class)->openItemCounts($this->lease)['faults']);
    }

    public function test_an_older_self_resolved_record_displays_correctly_without_being_touched(): void
    {
        // the shape the OLD code stored: reported, then closed by setOutcome (status resolved, outcome first-aid, a null note)
        $f = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'Lock / keys', 'description' => '', 'status' => 'resolved', 'outcome' => 'resolved_by_first_aid', 'outcome_note' => null, 'outcome_set_automatically' => false,
            'owner_approval_status' => 'not_required', 'reported_at' => now(), 'resolved_at' => now(), 'repaired_at' => now()->toDateString(), 'created_by_user_id' => null,
        ]);
        $before = $f->fresh()->getAttributes();

        Sanctum::actingAs($this->login($this->tenant), ['client']);
        $this->assertTrue($this->getJson('/api/v1/client/rentals/fault-reports')->json('fault_reports.0.self_resolved'));
        $this->assertSame('Resolved by tenant - tried the suggested fix', $f->fresh()->statusLabel());
        $this->assertSame($before, $f->fresh()->getAttributes(), 'nothing was changed by reading it');

        // a fault the AGENT closed as "resolved by first aid" is not the tenant's own doing: it keeps its normal wording
        $f2 = $f->replicate();
        $f2->forceFill(['reported_by_type' => 'agent_noticed', 'reported_by_contact_id' => null])->save();
        $this->assertFalse($f2->isTenantSelfResolved());
    }
}
