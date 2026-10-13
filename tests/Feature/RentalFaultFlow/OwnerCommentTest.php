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
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalFaultReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 (rentals-faults-work-orders.md §19.12 / §19.13) - the owner's own comment ("Your comment to your agent"), "Have this repaired anyway" with the
 * tenant's report shown READ-ONLY, the owner's decision form carrying the same comment, and the staff fault page's three equal buttons.
 */
final class OwnerCommentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $ownerAgent;
    private User $tenantAgent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Comment Agency', 'slug' => 'oc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $mk = fn () => User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->ownerAgent = $mk();
        $this->tenantAgent = $mk();
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->ownerAgent->id, 'branch_id' => $this->branch->id, 'title' => 'OC Unit', 'status' => 'active', 'listing_type' => 'rental', 'rental_no_approval_spend_threshold' => 1000]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
            'owner_agent_user_id' => $this->ownerAgent->id, 'tenant_agent_user_id' => $this->tenantAgent->id,
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Lock / keys', 'category' => 'Security', 'urgency' => 'routine', 'first_aid_steps' => 'Check the key.', 'is_active' => true]);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    /** "That fixed it": a fault the tenant resolved themselves, with their own words and a photo. */
    private function selfResolved(): RentalFaultReport
    {
        $f = app(RentalFaultReportService::class)->reportSelfResolved($this->property, [
            'lease_id' => $this->lease->id, 'rental_fault_type_id' => $this->type->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'Lock / keys', 'description' => 'The key sticks in the front door, it fixed itself after a spray.',
        ]);
        RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $f->id, 'storage_path' => '/storage/oc/lock.jpg', 'uploaded_by_user_id' => null]);

        return $f->fresh();
    }

    private function sentFault(): RentalFaultReport
    {
        $f = app(RentalFaultReportService::class)->report($this->property, [
            'lease_id' => $this->lease->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'rental_fault_type_id' => $this->type->id, 'title' => 'Roof leak', 'description' => 'Water in the passage',
        ]);
        $f->saveOwnerVersion(['owner_title' => 'Roof leaking', 'owner_description' => 'Water comes in.', 'owner_agent_note' => 'Roofer booked'], $this->ownerAgent);
        $f->forceFill(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()])->save();

        return $f->fresh();
    }

    private function told(string $eventKey): array
    {
        return DB::table('notifications')->get()->filter(fn ($n) => (json_decode($n->data, true)['event_key'] ?? null) === $eventKey)
            ->pluck('notifiable_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    // ───────────────────────── the repair request ─────────────────────────

    public function test_the_owner_asks_for_a_repair_with_a_comment_and_the_tenants_report_is_untouched(): void
    {
        $resolved = $this->selfResolved();
        $before = $resolved->only(['title', 'description', 'status', 'outcome', 'outcome_note', 'reported_at', 'resolved_at', 'reported_by_contact_id', 'reported_by_type']);
        $photosBefore = $resolved->photos()->pluck('storage_path')->all();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $res = $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", ['comment' => 'Please get a locksmith to look, it cost us last year.'])->assertStatus(201);
        $new = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));

        // the new request: linked, the owner's own comment with name + time, raised by the owner
        $this->assertSame($resolved->id, (int) $new->follows_fault_report_id);
        $this->assertSame(RentalFaultReport::REPORTED_BY_LANDLORD, $new->reported_by_type);
        $this->assertSame('Please get a locksmith to look, it cost us last year.', $new->owner_comment);
        $this->assertSame($this->landlord->id, (int) $new->owner_comment_by_contact_id);
        $this->assertNotNull($new->owner_commented_at);
        $c = $new->ownerComment();
        $this->assertSame('Lenny Landlord', $c['by']);

        // the tenant's report is exactly as it was
        $after = $resolved->fresh();
        $this->assertSame(json_encode($before), json_encode($after->only(array_keys($before))));
        $this->assertNull($after->owner_comment, 'the owner\'s comment is NOT written onto the tenant\'s report');
        $this->assertSame($photosBefore, $after->photos()->pluck('storage_path')->all());
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $after->status, 'the resolved report stays resolved');

        // history, in plain words, on both
        $this->assertStringContainsString('asked the agency to arrange this work', $new->updates()->where('update_type', 'owner_repair_request')->value('note'));
        $this->assertStringContainsString('They wrote: Please get a locksmith', $new->updates()->where('update_type', 'owner_repair_request')->value('note'));
        $this->assertStringContainsString('asked for a repair - request #' . $new->id, $after->updates()->where('update_type', 'owner_repair_request')->value('note'));

        // both lease agents hear about the new request, like any new fault
        $both = [$this->ownerAgent->id, $this->tenantAgent->id];
        sort($both);
        $this->assertSame($both, $this->told('rental_fault_report.created'));
    }

    public function test_a_repair_request_without_a_comment_is_fine_and_a_second_ask_is_refused(): void
    {
        $resolved = $this->selfResolved();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $id = $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", [])->assertStatus(201)->json('fault_report.id');
        $this->assertNull(RentalFaultReport::withoutGlobalScopes()->find($id)->owner_comment);
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->whereNotNull('owner_comment')->count());

        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", ['comment' => 'again'])->assertStatus(422)
            ->assertJsonPath('message', 'A repair has already been asked for on this one.');
        $this->assertSame(1, $resolved->followUps()->count());
    }

    public function test_it_only_works_on_a_fault_the_tenant_resolved_and_only_for_that_properties_owner(): void
    {
        $normal = $this->sentFault();
        $resolved = $this->selfResolved();
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam+' . uniqid() . '@example.invalid']);
        $other = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->ownerAgent->id, 'branch_id' => $this->branch->id, 'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental']);
        $other->contacts()->attach($stranger->id, ['role' => 'landlord']);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$normal->id}/repair-request", ['comment' => 'x'])->assertStatus(422);

        Sanctum::actingAs($this->login($stranger), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", ['comment' => 'not mine'])->assertStatus(404);
        $this->assertSame(0, $resolved->followUps()->count());
    }

    public function test_the_owner_sees_the_tenants_report_read_only_and_their_own_comment_back(): void
    {
        $resolved = $this->selfResolved();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}")->assertOk()->json('fault_report');
        $this->assertSame('The key sticks in the front door, it fixed itself after a spray.', $show['tenant_report']['text']);
        $this->assertNotNull($show['tenant_report']['at']);
        $this->assertCount(1, $show['tenant_report']['photos']);
        $this->assertNull($show['repair_asked']);

        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", ['comment' => 'Please check the barrel too'])->assertStatus(201);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}")->json('fault_report');
        $this->assertNotNull($show['repair_asked']);
        $this->assertSame('Please check the barrel too', $show['repair_asked']['owner_comment']['text'], 'read back to the owner after submitting');
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->firstWhere('id', $resolved->id);
        $this->assertSame('Please check the barrel too', $row['repair_asked']['owner_comment']['text']);
    }

    public function test_no_route_open_to_the_owner_can_change_a_fault_reports_text_photos_or_date(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/client/rentals/landlord/fault-reports'));
        $this->assertGreaterThan(0, $routes->count());
        $verbs = $routes->flatMap(fn ($r) => $r->methods())->unique()->values()->all();
        $this->assertEmpty(array_intersect(['PUT', 'PATCH', 'DELETE'], $verbs), 'the owner has no edit or delete route on a fault report');

        // the owner's complete list of WRITE routes on fault reports: a new one must be looked at on purpose (section 22.7 added stop + resume: both only pause / un-pause, neither edits the report)
        $writes = $routes->filter(fn ($r) => in_array('POST', $r->methods(), true))->map(fn ($r) => $r->getName())->sort()->values()->all();
        $this->assertSame(['client.rentals.landlord.fault-reports.decision', 'client.rentals.landlord.fault-reports.query', 'client.rentals.landlord.fault-reports.repair-request', 'client.rentals.landlord.fault-reports.resume', 'client.rentals.landlord.fault-reports.stop'], array_values(array_filter($writes, fn ($n) => $n !== 'client.rentals.landlord.properties.fault-reports.store')));
    }

    // ───────────────────────── the decision form ─────────────────────────

    public function test_the_decision_form_saves_the_owners_comment_for_decline_and_for_an_optional_note_on_approve(): void
    {
        $a = $this->sentFault();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$a->id}/decision", ['decision' => 'decline', 'note' => 'We just replaced that roof - claim from the builder'])->assertOk();
        $a = $a->fresh();
        $this->assertSame('We just replaced that roof - claim from the builder', $a->owner_comment);
        $this->assertSame($this->landlord->id, (int) $a->owner_comment_by_contact_id);
        $this->assertNotNull($a->owner_commented_at);
        $this->assertContains('owner_comment', $a->updates()->pluck('update_type')->all());

        $b = $this->sentFault();
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$b->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency', 'note' => 'Use Mr Roofer if you can'])->assertOk();
        $this->assertSame('Use Mr Roofer if you can', $b->fresh()->owner_comment);

        $c = $this->sentFault();
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$c->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();
        $this->assertNull($c->fresh()->owner_comment, 'no comment, none saved');

        $d = $this->sentFault();
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$d->id}/decision", ['decision' => 'decline', 'note' => ''])->assertStatus(422);
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $d->fresh()->owner_approval_status, 'a decline still needs the owner to say why');
    }

    public function test_the_owner_sees_their_decision_comment_back(): void
    {
        $f = $this->sentFault();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency', 'note' => 'Thanks - keep me posted'])->assertOk();
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report');
        $this->assertSame('Thanks - keep me posted', $show['owner_comment']['text']);
        $this->assertSame('Lenny Landlord', $show['owner_comment']['by']);
    }

    // ───────────────────────── the agent's page ─────────────────────────

    public function test_the_agent_sees_the_owners_comment_in_its_own_block_separate_from_the_tenants_report_and_the_agents_note(): void
    {
        $resolved = $this->selfResolved();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $id = $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$resolved->id}/repair-request", ['comment' => 'Do it before the weekend please'])->json('fault_report.id');

        $html = $this->actingAs($this->ownerAgent)->get(route('corex.rental-fault-reports.show', $id))->assertOk()->getContent();
        $this->assertStringContainsString('data-owner-comment', $html);
        $this->assertStringContainsString("Owner's comment", $html);
        $this->assertStringContainsString('Lenny Landlord', $html);
        $this->assertStringContainsString('Do it before the weekend please', $html);
        // not inside the agent's own note box
        $this->assertDoesNotMatchRegularExpression('#name="owner_agent_note"[^>]*>[^<]*Do it before the weekend#', $html);
    }

    public function test_the_owner_version_has_one_save_button_and_approve_within_limit_is_gone(): void
    {
        $f = app(RentalFaultReportService::class)->report($this->property, ['lease_id' => $this->lease->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'rental_fault_type_id' => $this->type->id, 'title' => 'Tap', 'description' => 'drip']);
        $f->saveOwnerVersion(['owner_title' => 'Tap dripping', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'x'], $this->ownerAgent);
        $html = $this->actingAs($this->ownerAgent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->assertOk()->getContent();

        $this->assertStringContainsString('cx-equal-btns', $html);
        $row = substr($html, strpos($html, 'data-owner-version-actions'));
        $row = substr($row, 0, strpos($row, '</form>'));
        // section 22 (page order fix): ONE button on the owner version - Save. Sending is only through "Who goes to look first?" below it.
        $this->assertStringContainsString('data-owner-version-save', $row);
        $this->assertSame(1, substr_count($row, '<button'));
        $this->assertStringNotContainsString('Save and send to owner', $html);
        $this->assertStringNotContainsString('Send saved version to owner', $html);
        $this->assertLessThan(strpos($html, 'data-decision-one'), strpos($html, 'id="owner-version-card"'), 'what the owner will see comes BEFORE who goes to look first');
        // section 22: approval against a limit exists only at Decision 2 (the cost) - never on the fault page
        $this->assertStringNotContainsString('Approve within agent limit', $html);
        $this->assertStringNotContainsString('data-go-ahead-toggle', $html);
        $this->assertStringNotContainsString('data-agent-limit-line', $html);
        $this->assertStringNotContainsString('Go ahead now and tell the owner', $html);
    }

    public function test_the_buttons_save_the_owner_version_so_an_unsaved_draft_is_not_a_blocker_on_the_page(): void
    {
        $f = app(RentalFaultReportService::class)->report($this->property, ['lease_id' => $this->lease->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'rental_fault_type_id' => $this->type->id, 'title' => 'Tap', 'description' => 'drip']);
        $html = $this->actingAs($this->ownerAgent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        // page-order fix: the two buttons under "Who goes to look first?" send the draft's fields with the press, so there is no "save it first" line to scroll for
        $this->assertStringNotContainsString('data-inspect-block-line', $html);
        $this->assertStringContainsString('id="owner-version-form"', $html);
    }

    // ───────────────────────── the owner portal screens ─────────────────────────

    public function test_the_portal_has_a_real_button_the_read_only_tenant_report_and_the_comment_box(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="btn btn-primary"[^>]*data-repair-anyway#', $html, 'a filled action button, not a faint outline');
        $this->assertStringNotContainsString('class="btn btn-outline" x-show="!faultDetail.repair_asked" data-repair-anyway', $html);
        $this->assertStringContainsString('What the tenant reported', $html);
        $this->assertStringContainsString('Your comment to your agent', $html);
        $this->assertStringContainsString('Ask my agent to have this repaired', $html);
        $this->assertStringContainsString('data-tenant-report-text', $html);
        $this->assertStringContainsString('data-owner-comment-input', $html);
        $this->assertStringNotContainsString('repairAnyway(', $html, 'the old wizard route is gone');
        // the tenant's text is shown as text, never as an input the owner can type into
        $block = substr($html, strpos($html, 'data-tenant-report-text'), 400);
        $this->assertStringNotContainsString('<textarea', $block);
    }

    public function test_the_staff_self_resolved_block_has_a_filled_repair_button(): void
    {
        $resolved = $this->selfResolved();
        $html = $this->actingAs($this->ownerAgent)->get(route('corex.rental-fault-reports.show', $resolved))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="corex-btn-primary text-xs" data-repair-anyway#', $html);
    }

    // ───────────────────────── the owner's work request (§19.14) ─────────────────────────

    public function test_an_owner_work_request_reaches_the_agent_flagged_and_is_the_owners_authority_to_go_to_a_quote(): void
    {
        $this->actingAs($this->ownerAgent);   // (the staff session must not be mistaken for the owner below)
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $res = $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->type->id, 'description' => 'Please replace the front door lock barrel', 'comment' => 'Use the same key system as the gate',
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $f = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));

        $this->assertTrue($f->isOwnerRequested());
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $f->owner_approval_status, 'the owner\'s instruction IS the authority - no decision is asked of them');
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $f->approval_route);
        $this->assertSame('Lock / keys', $f->title, 'the type\'s name is the title - the owner form has no title box');
        $this->assertSame('Please replace the front door lock barrel', $f->description);
        $this->assertSame('Use the same key system as the gate', $f->owner_comment);
        $a = $f->decision();
        $this->assertSame('portal', $a->evidence_type);
        $this->assertSame($this->landlord->id, (int) $a->recorded_by_contact_id);
        $this->assertStringContainsString('Their instruction is their authority to go to a quote', $f->updates()->where('update_type', 'owner_repair_request')->value('note'));

        // both lease agents are told - as a WORK REQUEST, not a fault report
        $both = [$this->ownerAgent->id, $this->tenantAgent->id];
        sort($both);
        $this->assertSame($both, $this->told('rental_fault_report.created'));
        $note = DB::table('notifications')->get()->map(fn ($n) => json_decode($n->data, true))->firstWhere('event_key', 'rental_fault_report.created');
        $this->assertStringContainsString('Work requested by the owner', json_encode($note));
        $this->assertStringContainsString('create_work_order=1', json_encode($note));

        // the tenant is not mailed about a fault they never reported
        Mail::assertNotQueued(\App\Mail\Rentals\RentalTenantStatusChangeMail::class);
        Mail::assertNotSent(\App\Mail\Rentals\RentalTenantStatusChangeMail::class);

        // on the owner's list: no "needs your decision", read plainly
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->firstWhere('id', $f->id);
        $this->assertFalse($row['needs_decision']);
        $this->assertSame('With your agent', $row['status_label']);

        // on the agent's page: flagged, and the work order can be made at once
        $html = $this->actingAs($this->ownerAgent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('data-requested-by-owner', $html);
        $this->assertStringContainsString('Requested by the owner', $html);
        $this->assertNull($f->fresh()->workOrderBlockReason());
    }

    public function test_quote_limits_still_apply_to_an_owner_work_request(): void
    {
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $id = $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", ['rental_fault_type_id' => $this->type->id, 'description' => 'New lock'], ['Accept' => 'application/json'])->json('fault_report.id');
        $f = RentalFaultReport::withoutGlobalScopes()->findOrFail($id);
        $supplier = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Locks Ltd', 'email' => 'locks@example.invalid', 'is_active' => true, 'specialty' => 'other']);
        $this->actingAs($this->ownerAgent)->post(route('corex.rental-fault-reports.raise-work-order', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id])->assertRedirect();
        $wo = \App\Models\RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);

        $q = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 1000, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->ownerAgent);
        $wo->fresh()->selectQuote($q, $this->ownerAgent);
        $this->assertSame(\App\Models\RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status, 'at the limit (R1000): the agent may approve it');

        $wo2 = \App\Models\RentalWorkOrder::withoutGlobalScopes()->findOrFail(
            (function () use ($supplier) {
                Sanctum::actingAs($this->login($this->landlord), ['client']);
                $id2 = $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", ['rental_fault_type_id' => $this->type->id, 'description' => 'Second lock'], ['Accept' => 'application/json'])->json('fault_report.id');
                $this->actingAs($this->ownerAgent)->post(route('corex.rental-fault-reports.raise-work-order', $id2), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id]);

                return RentalFaultReport::withoutGlobalScopes()->findOrFail($id2)->rental_work_order_id;
            })()
        );
        $q2 = $wo2->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 1000.01, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->ownerAgent);
        $wo2->fresh()->selectQuote($q2, $this->ownerAgent);
        $this->assertSame(\App\Models\RentalWorkOrder::APPROVAL_PENDING, $wo2->fresh()->owner_approval_status, 'one cent over: the quote goes to the owner for approval');
    }

    public function test_a_work_request_needs_a_type_or_some_details(): void
    {
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", ['description' => '  '], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('message', 'Choose what needs doing, or write the details.');
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
        // details alone are enough - the title is made from them
        $id = $this->post("/api/v1/client/rentals/landlord/properties/{$this->property->id}/fault-reports", ['description' => 'The garage door spring has snapped and it will not close'], ['Accept' => 'application/json'])->assertStatus(201)->json('fault_report.id');
        $this->assertSame('The garage door spring has snapped and it will not close', RentalFaultReport::withoutGlobalScopes()->find($id)->title);
    }

    public function test_the_owner_form_is_an_instruction_with_no_self_help_while_the_tenant_form_keeps_it(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();
        $a = strpos($html, 'data-landlord-fault-wizard');
        $b = strpos($html, 'data-owner-faults', $a);
        $owner = substr($html, $a, $b - $a);
        $this->assertStringContainsString('Ask your agent to arrange work', $owner);
        $this->assertStringContainsString("this.landlordTab = 'faults';", $html, 'Request work on the Properties tab takes the owner to the form');
        $this->assertStringContainsString('What needs doing', $owner);
        $this->assertStringContainsString('Details', $owner);
        $this->assertStringContainsString('Photos (optional)', $owner);
        $this->assertStringContainsString('Your comment to your agent (optional)', $owner);
        $this->assertStringContainsString('Send work request to my agent', $owner);
        $this->assertStringContainsString('Sent to your agent. They will arrange a contractor and a quote.', $owner);
        $this->assertStringNotContainsString('fault-aid', $owner);
        $this->assertStringNotContainsString('data-fault-aid-step', $owner);
        $this->assertStringNotContainsString('Before you request work', $owner);
        $this->assertStringNotContainsString("That fixed it", $owner);
        $this->assertStringNotContainsString('Describe the problem', $owner);
        // the tenant's form still has its "Try this first" steps
        $this->assertStringContainsString('data-fault-aid-step', substr($html, 0, $a));
        $this->assertStringContainsString('Try this first', $html);
    }

    public function test_the_photo_description_is_centred_on_a_dark_band_for_every_viewer(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('.cxpv-note > * { width: 100%; max-width: 70%;', $html);
        $this->assertStringContainsString('text-align: center', substr($html, strpos($html, '.cxpv-note {'), 400));
        $this->assertStringContainsString('rgba(0, 0, 0, .6)', substr($html, strpos($html, '.cxpv-note {'), 300));
        $this->assertStringContainsString('font-size: 17px', substr($html, strpos($html, '.cxpv-note .cxpv-text'), 250));
        $this->assertStringContainsString('@media (max-width: 600px)', $html);
        $this->assertStringContainsString('.cxpv-note > * { max-width: 94%; }', $html, 'works at 390');
    }
}
