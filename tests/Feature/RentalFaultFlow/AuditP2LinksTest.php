<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalJobCancelledMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalJobDispatch;
use App\Models\RentalOwnerHold;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026 (rentals-faults-work-orders.md section 29), package P2 - the contractor's public link: a cancelled job says so and tells the people who were sent it
 * (C-A4), the contractor's own "I have started" (C-A6), a page that outlived the session keeps what was typed (C-D1), throttles per token (C-D2), links that renew on use
 * (C-D3), a declined contractor cannot quote and quote amounts are sane (C-C6), an owner hold refuses every action (C-C5), the invoice amount starts from what is known (C-E2).
 */
final class AuditP2LinksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Audit P2 Agency', 'slug' => 'ap2-' . uniqid()]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);
    }

    private function workOrder(array $over = []): RentalWorkOrder
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => Branch::where('agency_id', $this->agency->id)->value('id'),
            'title' => 'P2 Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $wo = RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Fix gate', 'description' => 'Gate motor broken',
            'status' => RentalWorkOrder::STATUS_ORDERED, 'approval_basis' => RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED,
            'priority' => RentalWorkOrder::PRIORITY_NORMAL, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_at' => now(),
        ]);
        $wo->forceFill($over)->save();

        return $wo->fresh();
    }

    private function link(RentalWorkOrder $wo): string
    {
        return app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->agent)['raw_token'];
    }

    private function goAheadSent(RentalWorkOrder $wo): void
    {
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'work_order_sent', 'note' => 'Work order sent to the contractor.']);
    }

    // ── C-A4 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_cancelling_a_work_order_tells_the_contractor_and_the_tenant_once_and_the_link_says_cancelled(): void
    {
        $wo = $this->workOrder(['appointment_at' => now()->addDays(2)]);
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $wo->branch_id, 'property_id' => $wo->property_id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $wo->branch_id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina-' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $wo->forceFill(['lease_id' => $lease->id])->save();
        RentalJobDispatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'recipient_kind' => 'contractor', 'recipient_name' => 'Acme Plumbing',
            'channel' => 'email', 'destination' => 'acme@example.invalid', 'status' => 'sent', 'reason' => 'appointed', 'idempotency_key' => 'k-' . uniqid(), 'sent_at' => now(),
        ]);
        $raw = $this->link($wo);
        $this->get('/secure/work-orders/' . $raw)->assertOk()->assertSee('Fix gate');

        $wo->cancel($this->agent, 'Owner changed their mind');

        Mail::assertQueued(RentalJobCancelledMail::class, fn ($m) => $m->hasTo('acme@example.invalid') && $m->audience === 'worker');
        Mail::assertQueued(RentalJobCancelledMail::class, fn ($m) => $m->hasTo($tenant->email) && $m->audience === 'tenant');
        Mail::assertQueuedCount(2);
        $note = $wo->updates()->where('update_type', 'note')->orderByDesc('id')->value('note');
        $this->assertStringContainsString('Emailed "Job cancelled - do not attend" to Acme Plumbing', (string) $note);
        $this->assertStringContainsString('the appointment will not go ahead', (string) $note);
        $this->assertStringNotContainsString('Owner changed their mind', (string) $note, 'the internal reason is not in what is sent');

        // the dead link says WHY - not "expired or replaced"
        $this->get('/secure/work-orders/' . $raw)->assertOk()->assertSee('This job was cancelled')->assertDontSee('may have expired');
        // a second cancel is refused, so nobody is told twice
        $this->expectException(\LogicException::class);
        $wo->fresh()->cancel($this->agent, 'again');
    }

    public function test_a_cancelled_job_with_no_appointment_does_not_email_the_tenant_and_a_whatsapp_only_contractor_is_flagged_for_a_phone_call(): void
    {
        $wo = $this->workOrder();
        RentalJobDispatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'recipient_kind' => 'contractor', 'recipient_name' => 'Bob Builder',
            'channel' => 'whatsapp', 'destination' => '0821234567', 'status' => 'marked_sent', 'reason' => 'appointed', 'idempotency_key' => 'k-' . uniqid(), 'sent_at' => now(),
        ]);
        $wo->cancel($this->agent, 'x');

        Mail::assertNothingQueued();
        $this->assertStringContainsString('Phone Bob Builder', (string) $wo->updates()->where('update_type', 'note')->orderByDesc('id')->value('note'));
    }

    public function test_a_unknown_or_expired_link_is_still_the_generic_page_not_cancelled(): void
    {
        $this->get('/secure/work-orders/not-a-token')->assertSee('no longer available')->assertDontSee('This job was cancelled');
    }

    // ── C-A6 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_contractor_can_press_i_have_started_once_the_job_is_authorised_and_the_go_ahead_has_gone(): void
    {
        $wo = $this->workOrder();
        $raw = $this->link($wo);

        // the go-ahead has not gone: refused in plain words, nothing changes
        $this->post('/secure/work-orders/' . $raw . '/started')->assertSessionHasErrors('work_order');
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);

        $this->goAheadSent($wo);
        $this->get('/secure/work-orders/' . $raw)->assertSee('I have started');
        $this->post('/secure/work-orders/' . $raw . '/started')->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertTrue($wo->updates()->where('update_type', 'contractor_progress')->exists());
        $this->assertTrue($wo->updates()->where('to_status', 'in_progress')->exists());

        // a second press is a quiet no-op and the button is gone
        $this->post('/secure/work-orders/' . $raw . '/started')->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->updates()->where('to_status', 'in_progress')->count());
        $this->get('/secure/work-orders/' . $raw)->assertDontSee('I have started')->assertSee('The job is in progress');
    }

    public function test_started_is_refused_when_declined_and_the_event_type_is_its_own(): void
    {
        $wo = $this->workOrder(['contractor_response' => 'declined']);
        $this->goAheadSent($wo);
        $raw = $this->link($wo);
        $this->post('/secure/work-orders/' . $raw . '/started')->assertSessionHasErrors('work_order');
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('notification_event_types')->where('key', 'rental_work_order.contractor_started')->exists(), 'its own alert type, so the cooldown on contractor_responded cannot swallow it');
    }

    // ── C-C5 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_an_owner_hold_refuses_every_contractor_action_not_only_finishing(): void
    {
        $wo = $this->workOrder();
        $this->goAheadSent($wo);
        $raw = $this->link($wo);
        $owner = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $wo->branch_id, 'first_name' => 'Olly', 'last_name' => 'Owner', 'email' => 'olly-' . uniqid() . '@example.invalid']);
        RentalOwnerHold::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'stopped_by_contact_id' => $owner->id, 'reason' => 'Owner wants to think', 'stopped_via' => 'link', 'stopped_at' => now(), 'stage_when_stopped' => 'ordered']);

        foreach (['started', 'respond', 'quote', 'photo', 'extra', 'invoice', 'mark-done'] as $action) {
            $this->post('/secure/work-orders/' . $raw . '/' . $action, ['answer' => 'accept'])->assertSessionHasErrors('work_order');
        }
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertSame('This job is on hold - please do not attend until you hear from us.', session('errors')->first('work_order'));
        $this->postJson('/secure/work-orders/' . $raw . '/photo')->assertStatus(423);
        // the page itself still opens and says so
        $this->get('/secure/work-orders/' . $raw)->assertOk()->assertSee('please do not attend');
    }

    // ── C-C6 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_declined_contractor_cannot_quote_and_a_quote_must_be_more_than_zero_and_below_the_agency_maximum(): void
    {
        $wo = $this->workOrder(['approval_basis' => null, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED]);
        $raw = $this->link($wo);
        $good = ['amount' => 1500, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the motor'];

        $this->post('/secure/work-orders/' . $raw . '/quote', array_merge($good, ['amount' => 0]))->assertSessionHasErrors('amount');
        $this->post('/secure/work-orders/' . $raw . '/quote', array_merge($good, ['amount' => 1000001]))->assertSessionHasErrors('amount');
        $this->assertSame(0, $wo->quotes()->count());

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['quote_max_amount' => 5000]);
        $this->post('/secure/work-orders/' . $raw . '/quote', array_merge($good, ['amount' => 5001]))->assertSessionHasErrors('amount');
        $this->post('/secure/work-orders/' . $raw . '/quote', $good)->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->quotes()->count());

        // declined: the server refuses even though a hand-made POST is possible
        $wo2 = $this->workOrder(['approval_basis' => null, 'contractor_response' => 'declined']);
        $raw2 = $this->link($wo2);
        $this->post('/secure/work-orders/' . $raw2 . '/quote', $good)->assertSessionHasErrors('quote');
        $this->assertSame(0, $wo2->quotes()->count());
        $this->assertSame('declined', $wo2->fresh()->contractor_response, 'a refused quote does not flip the answer back');
    }

    public function test_a_contractor_cannot_decline_once_the_work_has_started(): void
    {
        $wo = $this->workOrder(['status' => RentalWorkOrder::STATUS_IN_PROGRESS]);
        $raw = $this->link($wo);
        $this->post('/secure/work-orders/' . $raw . '/respond', ['answer' => 'decline', 'reason' => 'Too busy'])->assertSessionHasErrors('answer');
        $this->assertNull($wo->fresh()->contractor_response);
    }

    // ── C-D1 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_page_that_outlived_the_session_goes_back_to_the_same_page_with_what_was_typed_and_never_to_the_staff_login(): void
    {
        $wo = $this->workOrder();
        $raw = $this->link($wo);
        $request = Request::create('/secure/work-orders/' . $raw . '/quote', 'POST', ['amount' => '1234', 'detail_text' => 'Typed notes', '_token' => 'stale'], [], [], ['HTTP_REFERER' => url('/secure/work-orders/' . $raw)]);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);

        $response = $this->app->make(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertTrue($response->isRedirect());
        $this->assertSame(url('/secure/work-orders/' . $raw), $response->headers->get('Location'));
        $this->assertStringNotContainsString('/login', (string) $response->headers->get('Location'));
        $this->assertSame('1234', $request->session()->get('_old_input.amount'));
        $this->assertSame('Typed notes', $request->session()->get('_old_input.detail_text'));
        $this->assertNull($request->session()->get('_old_input._token'));
        $this->assertStringContainsString('press the button again', $request->session()->get('errors')->first('session'));

        // a public link's JSON call (the crew's no-reload rows) gets the same words, status 419
        $json = Request::create('/secure/job-cards/' . $raw . '/lines', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $json);
        $jr = $this->app->make(ExceptionHandler::class)->render($json, new TokenMismatchException('CSRF token mismatch.'));
        $this->assertSame(419, $jr->getStatusCode());
        $this->assertStringContainsString('press the button again', $jr->getContent());
    }

    // ── C-D2 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_throttle_is_per_token_not_per_address(): void
    {
        config(['rental-public-links.per_token_per_minute' => 3, 'rental-public-links.per_address_per_minute' => 1000, 'rental-public-links.heavy_per_token_per_ten_minutes' => 4]);
        \Illuminate\Support\Facades\RateLimiter::clear('x');
        $a = $this->link($this->workOrder());
        $b = $this->link($this->workOrder(['title' => 'Other job']));

        foreach (range(1, 3) as $i) {
            $this->get('/secure/work-orders/' . $a)->assertOk();
        }
        $this->get('/secure/work-orders/' . $a)->assertStatus(429);
        // another token from the SAME address is not affected
        $this->get('/secure/work-orders/' . $b)->assertOk();
    }

    public function test_guessing_made_up_tokens_from_one_address_is_stopped_while_real_links_keep_working(): void
    {
        // audit 2026-10-20 finding 9: the per-address ceiling stays wide (a shared office / mobile network), so GUESSING is counted where it shows - as "not found" answers
        config(['rental-public-links.per_token_per_minute' => 100, 'rental-public-links.per_address_per_minute' => 1000, 'rental-public-links.misses_per_address_per_minute' => 3]);
        $real = $this->link($this->workOrder());

        $this->get('/secure/work-orders/' . $real)->assertOk();   // a real link is never a miss
        foreach (range(1, 3) as $i) {
            $this->get('/secure/work-orders/not-a-real-token-' . $i)->assertOk();   // the page itself never says why a link is dead
        }
        $this->get('/secure/work-orders/not-a-real-token-4')->assertStatus(429);
        // the address is held for the rest of the minute
        $this->get('/secure/work-orders/' . $real)->assertStatus(429);
    }

    public function test_an_expired_or_revoked_link_is_not_a_miss(): void
    {
        config(['rental-public-links.per_token_per_minute' => 100, 'rental-public-links.per_address_per_minute' => 1000, 'rental-public-links.misses_per_address_per_minute' => 2]);
        $wo = $this->workOrder();
        $raw = $this->link($wo);
        RentalSecureAccessToken::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->update(['expires_at' => now()->subDay()]);

        foreach (range(1, 5) as $i) {
            $this->get('/secure/work-orders/' . $raw)->assertOk();   // the dead-link page, every time - a real link that has run out is never "guessing"
        }
    }

    public function test_real_links_never_use_up_the_miss_allowance(): void
    {
        config(['rental-public-links.per_token_per_minute' => 100, 'rental-public-links.per_address_per_minute' => 1000, 'rental-public-links.misses_per_address_per_minute' => 2]);
        $real = $this->link($this->workOrder());

        foreach (range(1, 6) as $i) {
            $this->get('/secure/work-orders/' . $real)->assertOk();
        }
    }

    // ── C-D3 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_link_renews_its_expiry_each_time_it_is_used_when_the_agency_setting_is_on_and_never_otherwise(): void
    {
        $wo = $this->workOrder();
        $raw = $this->link($wo);
        $token = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $set = fn ($at) => RentalSecureAccessToken::withoutGlobalScopes()->whereKey($token->id)->update(['expires_at' => $at]);   // a plain update: no stale-model dirty check
        $set(now()->addDays(2));

        $this->get('/secure/work-orders/' . $raw)->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $token->fresh()->expires_at->timestamp, 120, 'default 14 days from the agency setting, from now');

        // switched off: used, not renewed
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['link_renew_on_use' => false]);
        $set(now()->addDays(2));
        $this->get('/secure/work-orders/' . $raw)->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, $token->fresh()->expires_at->timestamp, 120);

        // never shortens a longer expiry, never gives an expiry to a link that has none
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['link_renew_on_use' => true]);
        $set(now()->addDays(60));
        $this->get('/secure/work-orders/' . $raw)->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $token->fresh()->expires_at->timestamp, 120);
        $set(null);
        $this->get('/secure/work-orders/' . $raw)->assertOk();
        $this->assertNull($token->fresh()->expires_at);

        // an expired or revoked link is never revived by being used
        $set(now()->subHour());
        $this->get('/secure/work-orders/' . $raw)->assertSee('no longer available');
        $this->assertTrue($token->fresh()->expires_at->isPast());
    }

    // ── C-E2 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_invoice_starts_at_the_authorised_amount_less_what_is_already_invoiced(): void
    {
        $wo = $this->workOrder(['approved_amount' => 2000]);
        $raw = $this->link($wo);
        $page = $this->get('/secure/work-orders/' . $raw)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="amount"[^>]*value="2000.00"[^>]*data-invoice-amount/', $page);
        $this->assertStringContainsString('data-invoice-suggested', $page);

        \App\Models\RentalWorkOrderInvoice::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'amount' => 800,
            'document_storage_path' => 'x/y.pdf', 'document_original_name' => 'y.pdf', 'document_mime' => 'application/pdf', 'document_size' => 10,
        ]);
        $this->assertSame(1200.0, app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class)->suggestedInvoiceAmount($wo->fresh()));
        $this->assertSame(800.0, app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class)->suggestedFinalCost($wo->fresh())['amount'], 'the agent final cost starts from the contractor\'s invoices');
    }
}
