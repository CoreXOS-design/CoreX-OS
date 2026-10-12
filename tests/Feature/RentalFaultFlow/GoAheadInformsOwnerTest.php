<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalLandlordInformedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 round 2, item I (rentals-faults-work-orders.md §19.10) - Johan: "any fault, even if it is in the repair limit, still needs to go to the owner.
 * Even if the agent authorised and got it fixed, the owner still needs to know about it." The agent goes ahead in ONE step; the owner is INFORMED,
 * never asked, sees it as "Arranged by your agent", gets the normal owner notice, and can only ask a question.
 */
final class GoAheadInformsOwnerTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Go Ahead Agency', 'slug' => 'ga-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'GA Unit', 'status' => 'active', 'listing_type' => 'rental', 'rental_no_approval_spend_threshold' => 1500]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    /** A reported fault with the owner version prepared (and a shared photo) - nothing sent. */
    private function prepared(bool $saveVersion = true): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'TENANT WORDS: tap', 'description' => 'TENANT ORIGINAL', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $photo = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/ga/tap.jpg', 'uploaded_by_user_id' => null]);
        if ($saveVersion) {
            $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'Plumber booked', 'owner_photo_ids' => [$photo->id]], $this->agent);
        }

        return $fault->fresh();
    }

    private function crew(): RentalCrew
    {
        return RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team Maintenance', 'is_active' => true]);
    }

    private function goAhead(RentalFaultReport $f, array $extra = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $f), array_merge(['assignment_type' => 'internal', 'rental_crew_id' => $this->crew()->id], $extra));
    }

    public function test_going_ahead_before_the_owner_decides_informs_the_owner_in_one_step(): void
    {
        $f = $this->prepared();
        $this->goAhead($f)->assertRedirect();
        $f = $f->fresh();

        $this->assertNotNull($f->rental_work_order_id, 'the work order exists');
        $this->assertNotNull($f->arranged_by_agent_at);
        $this->assertSame(RentalFaultReport::ARRANGED_NO_APPROVAL_LIMIT, $f->arranged_basis);
        $this->assertEquals(1500.0, (float) $f->arranged_limit_amount);
        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $f->owner_approval_status, 'never "pending": nothing is asked');
        $this->assertContains('arranged_by_agent', $f->updates()->pluck('update_type')->all());

        // the owner got the normal owner notice (same channel as "a decision is needed")
        Mail::assertQueued(RentalLandlordInformedMail::class, 1);
        Mail::assertQueued(RentalLandlordInformedMail::class, fn ($m) => $m->hasTo($this->landlord->email) && str_contains($m->arrangedText, 'R1 500.00 no-approval limit') && str_contains($m->portalUrl, 'fault=' . $f->id));
        Mail::assertNotQueued(\App\Mail\Rentals\RentalLandlordDecisionNeededMail::class);
    }

    public function test_the_owner_portal_shows_it_as_arranged_by_the_agent_never_as_needs_your_decision(): void
    {
        $f = $this->prepared();
        $this->goAhead($f);
        $f = $f->fresh();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->json('fault_reports'))->firstWhere('id', $f->id);
        $this->assertNotNull($row, 'it reaches the owner the moment the agent goes ahead');
        $this->assertTrue($row['arranged_by_agent']);
        $this->assertFalse($row['needs_decision']);
        $this->assertSame('Arranged by your agent', $row['status_label']);
        $this->assertSame('Kitchen tap leaking', $row['title'], 'the agent\'s prepared version, never the tenant\'s words');

        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->assertOk()->json('fault_report');
        $this->assertFalse($show['awaiting_decision']);
        $this->assertNull($show['decision']);
        $this->assertSame('Your agent arranged this repair under your R1 500.00 no-approval limit.', $show['arranged']['text']);
        $this->assertSame('Kitchen tap leaking', $show['title']);
        $this->assertSame('Plumber booked', $show['agent_note']);
        $this->assertCount(1, $show['photos'], 'the photo the agent chose to share');
        $this->assertNotNull($show['work_order'], 'who does it');
        $this->assertSame(['informed', 'owner_decided'], collect($show['progress']['steps'])->map(fn ($s) => $s['key'] === 'sent_to_owner' ? 'informed' : ($s['key'] === 'owner_decided' ? 'owner_decided' : null))->filter()->values()->all());
        $labels = collect($show['progress']['steps'])->pluck('label')->all();
        $this->assertContains('Your agent told you', $labels);
        $this->assertContains('Arranged by your agent under your no-approval limit', $labels);
        $this->assertNotContains('You approved', $labels, 'it was never put to them to approve');
        $this->assertNotContains('Sent to you for approval', $labels);

        $this->assertSame([], $this->getJson('/api/v1/client/rentals/landlord/decisions')->json('fault_reports') ?? [], 'not in anything waiting on the owner');
    }

    public function test_an_emergency_is_worded_as_one(): void
    {
        $f = $this->prepared();
        $this->goAhead($f, ['emergency' => '1']);
        $this->assertSame(RentalFaultReport::ARRANGED_EMERGENCY, $f->fresh()->arranged_basis);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->assertSame('Your agent arranged this repair as an emergency.', $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report.arranged.text'));
    }

    public function test_the_owner_version_must_be_prepared_first_and_a_normal_send_is_unchanged(): void
    {
        $f = $this->prepared(false);
        $this->goAhead($f)->assertSessionHasErrors('rental_fault_report');
        $this->assertNull($f->fresh()->rental_work_order_id, 'nothing was created');
        $this->assertNull($f->fresh()->arranged_by_agent_at);
        Mail::assertNothingQueued();

        // the ordinary route: sent to the owner, who decides - not "arranged"
        $g = $this->prepared();
        $g->requestApproval($this->agent);
        $g->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $g->fresh()), ['assignment_type' => 'internal', 'rental_crew_id' => $this->crew()->id])->assertRedirect();
        $this->assertNull($g->fresh()->arranged_by_agent_at, 'an owner-approved fault is not "arranged by the agent"');
        $this->assertFalse($g->fresh()->isArrangedByAgent());
    }

    public function test_the_go_ahead_screen_says_what_it_does_and_waits_for_the_owner_version(): void
    {
        $f = $this->prepared(false);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('Approve within agent limit', $html);
        $this->assertStringContainsString('data-go-ahead-needs-version', $html);

        $f->saveOwnerVersion(['owner_title' => 'T', 'owner_description' => 'D'], $this->agent);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->getContent();
        $this->assertStringNotContainsString('data-go-ahead-needs-version', $html);
        $this->assertStringContainsString('Approve within agent limit', $html);
        $this->assertStringContainsString('data-emergency-row', $html);
        $this->assertStringContainsString('told, not asked', $html);
    }

    public function test_the_agent_sees_that_the_owner_was_told(): void
    {
        $f = $this->prepared();
        $this->goAhead($f);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->getContent();
        $this->assertStringContainsString('data-arranged-by-agent', $html);
        $this->assertStringContainsString('The owner was told on', $html);
        $this->assertStringContainsString('Approved within agent limit - owner told', $html);
    }

    public function test_when_the_cost_goes_over_the_limit_the_normal_owner_approval_still_applies_and_the_owner_sees_it(): void
    {
        $f = $this->prepared();
        $outside = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Pricey Plumbing', 'email' => 'pricey@example.invalid', 'is_active' => true, 'specialty' => 'other']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $outside->id])->assertRedirect();
        $f = $f->fresh();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->rental_work_order_id);
        $this->assertTrue($f->isArrangedByAgent());

        // a quote INSIDE the limit: authorised without the owner
        $cheap = $wo->recordQuote(['agency_service_provider_id' => $outside->id, 'amount' => 900, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->agent);
        $wo->selectQuote($cheap, $this->agent);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status);

        // a dearer one: the existing over-limit rule triggers - the owner's approval is asked for, on the work order
        $dear = $wo->recordQuote(['agency_service_provider_id' => $outside->id, 'amount' => 4800, 'quote_date' => now()->toDateString(), 'detail_text' => 'y'], $this->agent);
        $wo->fresh()->selectQuote($dear, $this->agent);
        $this->assertTrue(in_array($wo->fresh()->owner_approval_status, [RentalWorkOrder::APPROVAL_PENDING, RentalWorkOrder::APPROVAL_APPROVED], true) || $wo->fresh()->openVariation() !== null, 'approval is required over the limit');

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $arranged = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report.arranged');
        $this->assertTrue($arranged['needs_owner_approval'] || $arranged['amount'] !== null, 'the owner sees the amount or the approval being asked for');
    }

    public function test_the_final_cost_reaches_the_owner_portal_and_the_completion_notice_is_wired(): void
    {
        $f = $this->prepared();
        $this->goAhead($f);
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now(), 'cost_amount' => 1234.50, 'approved_amount' => 1300])->save();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $a = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report.arranged');
        $this->assertEquals(1234.5, $a['final_cost']);
        $this->assertEquals(1300.0, $a['amount']);
        $this->assertTrue(\Illuminate\Support\Facades\Event::hasListeners(\App\Events\Rentals\RentalWorkOrderClosed::class), 'closing the work order sends the owner the final statement with the amount');
    }

    public function test_query_this_is_a_note_to_both_lease_agents_not_a_decision(): void
    {
        $f = $this->prepared();
        $this->goAhead($f);
        $f = $f->fresh();
        $fired = [];
        $spy = \Mockery::mock(NotificationDispatcher::class);
        $spy->shouldReceive('fire')->andReturnUsing(function ($u, $key) use (&$fired) { $fired[] = [$u->id, $key]; return true; });
        $this->app->instance(NotificationDispatcher::class, $spy);

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/query", ['note' => 'Why this plumber?'])->assertCreated()->assertJsonPath('arranged.queries.0.note', fn ($n) => str_contains($n, 'Why this plumber?'));
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/query", ['note' => 'x'])->assertStatus(422);

        $this->assertContains('rental_fault_report.owner_query', array_column($fired, 1));
        $this->assertContains($this->agent->id, array_column($fired, 0));
        $this->assertContains('owner_query', $f->updates()->pluck('update_type')->all());
        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $f->fresh()->owner_approval_status, 'a question never approves or declines anything');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('notification_event_types')->where('key', 'rental_fault_report.owner_query')->count(), 'the event is registered');

        // a fault the agent did NOT arrange has nothing to query; another owner's fault is not theirs
        $other = $this->prepared();
        $other->requestApproval($this->agent);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$other->id}/query", ['note' => 'Hello there'])->assertStatus(422);
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'first_name' => 'S', 'last_name' => 'Stranger', 'email' => 'stranger+' . uniqid() . '@example.invalid']);
        Sanctum::actingAs($this->login($stranger), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/query", ['note' => 'Not my property'])->assertStatus(404);
    }

    public function test_what_reaches_the_owner_and_what_does_not(): void
    {
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $unsent = $this->prepared();
        $cancelled = $this->prepared();
        $cancelled->cancel($this->agent, 'logged in error - not a fault');
        $ids = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->pluck('id');
        $this->assertNotContains($unsent->id, $ids->all(), 'a fault the agency has not acted on stays invisible');
        $this->assertNotContains($cancelled->id, $ids->all(), 'closed as not a fault, never sent: nothing reaches the owner');

        // acted on = a work order exists: visible even if it was cancelled afterwards
        $acted = $this->prepared();
        $this->goAhead($acted);
        RentalWorkOrder::withoutGlobalScopes()->whereKey($acted->fresh()->rental_work_order_id)->update(['status' => 'cancelled']);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->assertContains($acted->id, collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->pluck('id')->all());
    }
}
