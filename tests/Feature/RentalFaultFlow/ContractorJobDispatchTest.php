<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalJobDispatch;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalContractorJobService as Jobs;
use App\Services\Rentals\RentalFaultContractorService;
use App\Services\Rentals\RentalFaultPhotoAnnotationService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 round 2 (.ai/specs/rentals-faults-work-orders.md §19): the contractor / crew is told the moment they are appointed - once, honestly -
 * the supplier card always shows the next action, the contractor answers from their own link, and ONE rule says which contractors suit a fault.
 * The agency mail path is replaced by a recorder, so nothing leaves this process.
 */
final class ContractorJobDispatchTest extends TestCase
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
    /** @var array<int, array{0:?string,1:object}> every mail the stand-in mail path was asked to send */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $self = $this;
        $this->app->instance(RentalMailDispatcher::class, new class($self) extends RentalMailDispatcher {
            public function __construct(private $t) {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void { $this->t->recordMail($recipientEmail, $mail); }
        });

        $this->agency = Agency::create(['name' => 'Job Dispatch Agency', 'slug' => 'jd-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'JD Unit', 'status' => 'active', 'listing_type' => 'rental', 'access_notes' => 'Key is with the caretaker, gate code 1234',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid', 'phone' => '0821112222',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    /** called by the stand-in mail path */
    public function recordMail(?string $to, object $mail): void
    {
        $this->sent[] = [$to, $mail];
    }

    private function mailsTo(string $email): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m[0] === $email));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function fault(array $over = [], bool $approved = true): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'Tap leaks', 'description' => 'The kitchen tap drips all night',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ], $over));
        $photo = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/jd/tap.jpg', 'uploaded_by_user_id' => null]);
        app(RentalFaultPhotoAnnotationService::class)->addMark($fault, $photo, ['shape' => 'circle', 'geometry' => ['cx' => 0.4, 'cy' => 0.5, 'r' => 0.1], 'colour' => '#e11d48'], $this->agent);
        app(RentalFaultPhotoAnnotationService::class)->setNote($fault, $photo, 'Drip is from this joint', $this->agent);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap in the kitchen drips.', 'owner_photo_ids' => [$photo->id]], $this->agent);
        if ($approved) {   // sent to the owner, who approved with "the agency appoints" - the point at which the agent appoints the contractor
            $fault->fresh()->requestApproval($this->agent);
            $fault->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner agreed.']);
        }

        return $fault->fresh();
    }

    private function provider(string $name, array $over = [], ?string $tradeCode = null): AgencyServiceProvider
    {
        $p = AgencyServiceProvider::withoutGlobalScopes()->create(array_merge(['agency_id' => $this->agency->id, 'name' => $name, 'is_active' => true, 'specialty' => 'other'], $over));
        if ($tradeCode) {
            $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(['agency_id' => $this->agency->id, 'code' => $tradeCode], ['label' => $tradeCode, 'sort_order' => 1, 'is_active' => true]);
            AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'service_provider_id' => $p->id, 'service_type' => $type->code]);
        }

        return $p;
    }

    private function appointViaFault(RentalFaultReport $fault, AgencyServiceProvider $p): RentalWorkOrder
    {
        $made = app(RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->agent, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id]);

        return $made['work_order']->fresh();
    }

    private function rows(RentalWorkOrder $wo): \Illuminate\Support\Collection
    {
        return RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderBy('id')->get();
    }

    private function liveTokens(RentalWorkOrder $wo): int
    {
        return RentalSecureAccessToken::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->whereNull('revoked_at')->count();
    }

    // ── A: the contractor is told the moment they are appointed ──────────────

    public function test_appointing_a_contractor_emails_them_the_job_with_the_link_and_records_who_how_and_where(): void
    {
        $p = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid', 'phone' => null], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);

        $mails = $this->mailsTo('drip@example.invalid');
        $this->assertCount(1, $mails, 'exactly one job mail');
        $html = $mails[0][1]->render();
        $this->assertStringContainsString('Kitchen tap leaking', $html, 'the title the owner saw');
        $this->assertStringContainsString('A tap in the kitchen drips.', $html, 'the description the owner saw');
        $this->assertStringContainsString('Key is with the caretaker', $html, 'how to get in');
        $this->assertStringContainsString('1 photo', $html);
        $this->assertStringContainsString('/secure/work-orders/', $html, 'the link');
        $this->assertStringNotContainsString($this->landlord->email, $html, 'no owner details');
        $this->assertStringNotContainsString('Tina', $html, 'no tenant details unless the agency switched them on for crews');
        $this->assertStringNotContainsString('R ', strip_tags($html), 'no prices');

        $row = $this->rows($wo)->first();
        $this->assertSame([RentalJobDispatch::CHANNEL_EMAIL, 'drip@example.invalid', RentalJobDispatch::STATUS_SENT, $this->agent->id], [$row->channel, $row->destination, $row->status, $row->sent_by_user_id]);
        $this->assertNotNull($row->sent_at);
        $this->assertSame(1, $this->liveTokens($wo));

        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status);
        $this->assertNotNull($wo->ordered_at);
        $this->assertSame('pending', $wo->contractor_response);
        $line = $wo->updates()->where('update_type', 'job_sent_to_contractor')->value('note');
        $this->assertStringContainsString('Drip Plumbing by email to drip@example.invalid', $line);
        $this->assertStringContainsString('Agnes Agent', $line);
    }

    public function test_the_job_is_never_sent_twice_for_one_appointment_and_each_resend_press_is_one_send(): void
    {
        $p = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $jobs = app(Jobs::class);

        $jobs->dispatchOnAppointment($wo, $this->agent);   // a retry of the same appointment
        $this->assertCount(1, $this->mailsTo('drip@example.invalid'));

        $jobs->resend($wo->fresh(), $this->agent, 'press-1');
        $jobs->resend($wo->fresh(), $this->agent, 'press-1');   // the double click
        $this->assertCount(2, $this->mailsTo('drip@example.invalid'), 'one press = one send');
        $jobs->resend($wo->fresh(), $this->agent, 'press-2');
        $this->assertCount(3, $this->mailsTo('drip@example.invalid'));
        $this->assertSame(1, $this->liveTokens($wo), 'a resend replaces the link; only one stays live');
    }

    public function test_a_contractor_with_no_email_and_no_mobile_is_a_plain_not_sent_line_not_a_silent_dead_end(): void
    {
        $p = $this->provider('Ghost Plumbing', [], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);

        $this->assertSame([], $this->sent, 'nothing was sent');
        $row = $this->rows($wo)->first();
        $this->assertSame([RentalJobDispatch::CHANNEL_NONE, RentalJobDispatch::STATUS_NOT_SENT], [$row->channel, $row->status]);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->status, 'not ordered: nobody has been asked');
        $this->assertNull($wo->contractor_response);
        $this->assertSame(0, $this->liveTokens($wo));
        $this->assertStringContainsString('NOT sent', $wo->updates()->where('update_type', 'job_not_sent')->value('note'));

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-job-not-sent', $html);
        $this->assertStringContainsString('NOT sent', $html);
        $this->assertStringContainsString('no email or mobile on file for Ghost Plumbing', $html);
        $this->assertStringContainsString('data-add-contact-details', $html, 'the link to add them');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-send-job/', $html, 'Send is off until details exist');
        $this->assertStringNotContainsString("Capture the contractor's quote and select it first", $html, 'the dead-end wording is gone');

        // details added -> Send works and the card then says "Job sent"
        AgencyServiceProviderContact::create(['agency_id' => $this->agency->id, 'service_provider_id' => $p->id, 'contact_person' => 'Gus', 'email' => 'ghost@example.invalid', 'is_active' => true]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*data-send-job/', $html);
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.resend', $wo), ['press_key' => 'k1'])->assertRedirect();
        $this->assertCount(1, $this->mailsTo('ghost@example.invalid'));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringContainsString('data-job-sent', $html);
        $this->assertStringNotContainsString('data-job-not-sent>', $html);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
    }

    public function test_whatsapp_is_only_sent_after_the_agent_opened_it_and_then_confirmed(): void
    {
        $p = $this->provider('Phone Plumbing', ['phone' => '082 555 1234'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $wa = $this->rows($wo)->first();
        $this->assertSame([RentalJobDispatch::CHANNEL_WHATSAPP, RentalJobDispatch::STATUS_PREPARED], [$wa->channel, $wa->status]);
        $this->assertSame([], $this->sent, 'nothing is emailed: the contractor only has a mobile');
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->status, 'still not ordered - nothing has gone');

        // "Mark as sent" without opening is refused
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-sent', [$wo, $wa->id]))->assertSessionHasErrors('rental_work_order');
        $this->assertSame(RentalJobDispatch::STATUS_PREPARED, $wa->fresh()->status);

        // open: records the open, redirects to wa.me with the message and a link
        $resp = $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-open', [$wo, $wa->id]));
        $resp->assertRedirectContains('https://wa.me/27825551234?text=');
        $this->assertStringContainsString('secure%2Fwork-orders%2F', $resp->headers->get('Location'));
        $this->assertSame(RentalJobDispatch::STATUS_OPENED, $wa->fresh()->status);
        $this->assertNotNull($wa->fresh()->opened_at);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status, 'opened is not sent');

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-sent', [$wo, $wa->id]))->assertSessionHasNoErrors();
        $this->assertSame(RentalJobDispatch::STATUS_MARKED_SENT, $wa->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertSame('pending', $wo->fresh()->contractor_response);
        $this->assertStringContainsString('by WhatsApp to 082 555 1234', $wo->updates()->where('update_type', 'job_sent_to_contractor')->value('note'));
        // twice changes nothing
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-sent', [$wo, $wa->id]));
        $this->assertSame(1, $wo->updates()->where('update_type', 'job_sent_to_contractor')->count());
    }

    public function test_email_and_whatsapp_together_the_emailed_link_keeps_working_after_the_whatsapp_is_opened(): void
    {
        $p = $this->provider('Both Plumbing', ['email' => 'both@example.invalid', 'phone' => '0825551234'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $this->assertCount(2, $this->rows($wo));
        $this->assertSame(1, $this->liveTokens($wo));

        $wa = $this->rows($wo)->firstWhere('channel', RentalJobDispatch::CHANNEL_WHATSAPP);
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-open', [$wo, $wa->id]));
        $this->assertSame(2, $this->liveTokens($wo), 'the emailed link and the WhatsApp link both live');
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-open', [$wo, $wa->id]));
        $this->assertSame(2, $this->liveTokens($wo), 'opening again replaces the WhatsApp link, it does not pile up');

        preg_match('#/secure/work-orders/([A-Za-z0-9]+)#', $this->mailsTo('both@example.invalid')[0][1]->render(), $m);
        $this->get(route('rentals.secure-link.show', $m[1]))->assertOk()->assertSee('Kitchen tap leaking');
    }

    public function test_other_agencys_dispatch_and_other_work_orders_dispatch_are_refused(): void
    {
        $p = $this->provider('Phone Plumbing', ['phone' => '0825551234'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $wa = $this->rows($wo)->first();
        $wo2 = $this->appointViaFault($this->fault(['title' => 'Second']), $this->provider('Other Phone', ['phone' => '0825550000'], 'Plumbing'));

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.whatsapp-open', [$wo2, $wa->id]))->assertNotFound();
        $this->assertSame(RentalJobDispatch::STATUS_PREPARED, $wa->fresh()->status);
    }

    // ── the contractor's own page ─────────────────────────────────────────────

    private function linkFor(string $email): string
    {
        preg_match('#/secure/work-orders/([A-Za-z0-9]+)#', end($this->sent)[1]->render(), $m);
        $this->assertNotEmpty($m, 'a link in the mail to ' . $email);

        return $m[1];
    }

    public function test_the_contractor_sees_the_job_and_the_photos_with_the_marks_and_notes_and_can_accept_decline_and_quote(): void
    {
        $p = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $token = $this->linkFor('drip@example.invalid');

        $html = $this->get(route('rentals.secure-link.show', $token))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-job', $html);
        $this->assertStringContainsString('Key is with the caretaker', $html);
        $this->assertStringContainsString('data-contractor-photos', $html);
        $this->assertStringContainsString('"shape":"circle"', $html, 'the office\'s mark rides with the photo');
        $this->assertStringContainsString('Drip is from this joint', $html, 'and its note');
        $this->assertStringContainsString('Waiting for their answer', $html);

        // decline needs a reason
        $this->post(route('rentals.secure-link.respond', $token), ['answer' => 'decline'])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $wo->fresh()->contractor_response);

        $this->post(route('rentals.secure-link.respond', $token), ['answer' => 'accept'])->assertRedirect();
        $this->assertSame('accepted', $wo->fresh()->contractor_response);
        $this->assertNotNull($wo->fresh()->contractor_responded_at);

        $this->post(route('rentals.secure-link.respond', $token), ['answer' => 'decline', 'reason' => 'Fully booked this week'])->assertRedirect();
        $this->assertSame('declined', $wo->fresh()->contractor_response);
        $this->assertSame('Fully booked this week', $wo->fresh()->contractor_decline_reason);

        $this->post(route('rentals.secure-link.quote', $token), ['amount' => '850', 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace washer and tap'])->assertRedirect();
        $this->assertSame('quote_uploaded', $wo->fresh()->contractor_response);
        $this->assertSame(1, $wo->quotes()->count());

        $types = $wo->updates()->pluck('update_type')->all();
        $this->assertSame(3, count(array_keys($types, 'contractor_response')), 'accepted, declined, quote - each written to the history');

        // and the supplier card shows it, not just a data row
        $card = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringContainsString('Their answer: <strong>Quote uploaded</strong>', $card);
    }

    public function test_changing_the_contractor_kills_the_old_link_resets_the_answer_and_tells_the_new_one(): void
    {
        $a = $this->provider('Alpha Plumbing', ['email' => 'alpha@example.invalid'], 'Plumbing');
        $b = $this->provider('Bravo Plumbing', ['email' => 'bravo@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $a);
        $oldToken = $this->linkFor('alpha@example.invalid');
        $this->post(route('rentals.secure-link.respond', $oldToken), ['answer' => 'decline', 'reason' => 'No'])->assertRedirect();

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.appoint', $wo), ['agency_service_provider_id' => $b->id])->assertRedirect();

        $this->get(route('rentals.secure-link.show', $oldToken))->assertSee('no longer', false);   // the unavailable page
        $this->assertCount(1, $this->mailsTo('bravo@example.invalid'));
        $fresh = $wo->fresh();
        $this->assertSame($b->id, (int) $fresh->agency_service_provider_id);
        $this->assertSame('pending', $fresh->contractor_response, 'a fresh question to the new contractor');
        $this->assertNull($fresh->contractor_decline_reason);
        $this->assertSame(1, $this->liveTokens($wo));
        $this->assertContains('supplier_changed', $wo->updates()->pluck('update_type')->all());

        // the same contractor again is refused (use Resend)
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.appoint', $wo), ['agency_service_provider_id' => $b->id])->assertSessionHasErrors('rental_work_order');
        // an attorney is not a contractor
        $att = $this->provider('Some Attorneys', ['specialty' => 'transfer_attorney', 'email' => 'att@example.invalid']);
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.contractor.appoint', $wo), ['agency_service_provider_id' => $att->id])->assertSessionHasErrors('rental_work_order');
    }

    // ── C: one rule ───────────────────────────────────────────────────────────

    public function test_one_rule_decides_the_owner_note_the_warning_and_the_picker(): void
    {
        $fault = $this->fault([], false);
        $svc = app(RentalFaultContractorService::class);
        $plumber = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $sparky = $this->provider('Sparky', ['email' => 'sparky@example.invalid'], 'Electrical');
        $handyman = $this->provider('General Handyman', ['phone' => '0821230000']);

        $c = $svc->classify($fault);
        $this->assertSame([$plumber->id], $c['suits']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$sparky->id, $handyman->id], $c['others']->pluck('id')->all(), 'the rest are still offered');
        $this->assertFalse($c['mapping_gap']);
        $this->assertSame([$plumber->id], $svc->optionsFor($fault)->pluck('id')->all(), 'the owner portal list');
        $this->assertTrue($svc->hasContractorFor($fault));
        $this->assertSame([$plumber->id, ], array_slice($svc->pickerFor($fault)->pluck('id')->all(), 0, 1), 'the picker starts with the ones that suit');
        $this->assertTrue($svc->pickerFor($fault)->firstWhere('id', $plumber->id)['suits']);
        $this->assertFalse($svc->pickerFor($fault)->firstWhere('id', $handyman->id)['suits']);

        // the agent's fault page: groups, and a "no contact details" tag where it applies
        $fault->forceFill(['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => 'agency_appoints'])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent();
        $this->assertStringContainsString('Suits Leaking tap', $html);
        $this->assertStringContainsString('Other contractors', $html);
        $this->assertStringNotContainsString('data-no-contractor-suits', $html);
    }

    public function test_when_none_suit_the_picker_and_the_owner_agree_and_the_mapping_gap_is_named(): void
    {
        $roof = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Roof leak', 'category' => 'Structural', 'urgency' => 'urgent', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 2, 'is_active' => true]);
        $fault = $this->fault(['rental_fault_type_id' => $roof->id], false);
        $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $svc = app(RentalFaultContractorService::class);

        $c = $svc->classify($fault);
        $this->assertTrue($c['suits']->isEmpty());
        $this->assertTrue($c['mapping_gap'], 'the agency has no trade matching "Structural" at all');
        $this->assertFalse($svc->hasContractorFor($fault));
        $this->assertSame(1, $c['others']->count(), 'the plumber is still selectable');

        Sanctum::actingAs($this->portalLogin($this->landlord), ['client']);
        $fault->forceFill(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()])->save();
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertJsonPath('fault_report.has_contractor', false);

        $fault->forceFill(['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => 'agency_appoints'])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent();
        $this->assertStringContainsString('data-no-contractor-suits', $html);
        $this->assertStringContainsString('No contractor loaded for Roof leak.', $html);
        $this->assertStringContainsString('Your trade list has no', $html);
        $this->assertStringContainsString('Add a contractor', $html);
        $this->assertStringContainsString('Add Roof leak to an existing contractor', $html);

        // adding a "Structural" trade and ticking it makes them suit - in all three places at once
        $roofer = $this->provider('Top Roofing', ['email' => 'roof@example.invalid'], 'Structural');
        $this->assertTrue($svc->hasContractorFor($fault));
        $this->assertSame([$roofer->id], $svc->classify($fault)['suits']->pluck('id')->all());
        $fault->forceFill(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'approval_route' => null])->save();
        Sanctum::actingAs($this->portalLogin($this->landlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertJsonPath('fault_report.has_contractor', true);
    }

    private function portalLogin(Contact $contact): \App\Models\ClientUser
    {
        $cu = \App\Models\ClientUser::firstOrCreate(['email' => $contact->email], ['current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    // ── supplier card + the old go-ahead path ────────────────────────────────

    public function test_the_supplier_card_always_shows_the_next_action(): void
    {
        $p = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-supplier-card', $html);
        $this->assertStringContainsString('Drip Plumbing', $html);
        $this->assertStringContainsString('Job sent', $html);
        $this->assertStringContainsString('by email to drip@example.invalid', $html);
        $this->assertStringContainsString('Agnes Agent', $html);
        $this->assertStringContainsString('Their answer: <strong>Waiting for their answer</strong>', $html);
        $this->assertStringContainsString('Send the job again', $html);
        $this->assertStringContainsString('Choose a different contractor', $html);
        $this->assertStringContainsString('data-go-ahead-note', $html, 'the go-ahead step is explained, not the only thing on screen');
        $this->assertStringNotContainsString("Capture the contractor's quote and select it first", $html);

        // a work order with no contractor chosen still shows what to do
        $bare = RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'title' => 'Bare', 'description' => 'x',
            'assignment_type' => 'outside_supplier', 'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $bare))->getContent();
        $this->assertStringContainsString('data-no-contractor-chosen', $html);
        $this->assertStringContainsString('Choose and send the job', $html);
    }

    public function test_the_go_ahead_route_sends_the_job_to_a_new_contractor_but_not_again_to_the_same_one(): void
    {
        $a = $this->provider('Alpha Plumbing', ['email' => 'alpha@example.invalid'], 'Plumbing');
        $b = $this->provider('Bravo Plumbing', ['email' => 'bravo@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $a);
        $this->assertCount(1, $this->mailsTo('alpha@example.invalid'));

        // a quote from Bravo is selected and the go-ahead authorised by an emergency-free path: drive the controller's own decision, i.e. the model's gate is stubbed open
        $this->mock(\App\Services\Rentals\RentalApprovalGateService::class, function ($m) {
            $m->shouldReceive('assertAuthorised')->andReturnNull();
        })->makePartial();
        $quote = $wo->quotes()->create(['agency_id' => $this->agency->id, 'agency_service_provider_id' => $b->id, 'amount' => 900, 'quote_date' => now()->toDateString(), 'is_selected' => true, 'captured_by_user_id' => $this->agent->id, 'captured_via' => 'agent']);
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $b->id]);

        $this->assertCount(1, $this->mailsTo('bravo@example.invalid') === [] ? [] : array_filter($this->mailsTo('bravo@example.invalid'), fn ($m) => $m[1] instanceof \App\Mail\Rentals\RentalContractorJobMail), 'the new contractor got the JOB mail (and the PDF mail)');
        $this->assertSame($b->id, (int) $wo->fresh()->agency_service_provider_id);
    }

    // ── D: the in-house crew ──────────────────────────────────────────────────

    private function crewCard(?RentalCrew $crew): \App\Models\RentalJobCard
    {
        $fault = $this->fault();
        $made = app(RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->agent, ['assignment_type' => 'internal', 'rental_crew_id' => $crew?->id]);

        return $made['job_card']->fresh();
    }

    public function test_a_crew_with_an_email_gets_their_link_automatically_and_it_is_written_to_the_history(): void
    {
        $crew = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team Maintenance', 'email' => 'crew@example.invalid', 'phone' => null, 'is_active' => true]);
        $card = $this->crewCard($crew);

        $this->assertCount(1, $this->mailsTo('crew@example.invalid'));
        $this->assertInstanceOf(\App\Mail\Rentals\RentalJobCardCrewLinkMail::class, $this->mailsTo('crew@example.invalid')[0][1]);
        $row = RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->first();
        $this->assertSame([RentalJobDispatch::KIND_CREW, RentalJobDispatch::CHANNEL_EMAIL, RentalJobDispatch::STATUS_SENT], [$row->recipient_kind, $row->channel, $row->status]);
        $this->assertStringContainsString('sent automatically', $card->updates()->where('update_type', 'link_emailed')->value('note'));
        $this->assertNotNull($card->workOrder->updates()->where('update_type', 'job_sent_to_crew')->first());

        // sending it again with the same key does nothing; the existing button stays the Resend
        app(Jobs::class)->sendCrewLink($card->fresh(), $this->agent);
        $this->assertCount(1, $this->mailsTo('crew@example.invalid'));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
        $this->assertStringContainsString('data-crew-sent', $html);
        $this->assertStringContainsString('Send the crew link again', $html);
        $this->assertStringContainsString('data-crew-send-standalone', $html, 'visible from the first moment (the card is still in its draft stage here)');
        $this->assertStringContainsString('data-crew-send', $html);   // the existing "Email a link" form (the Resend) lives in the full crew panel once the card is booked
    }

    public function test_the_crew_auto_send_can_be_switched_off_and_a_crew_without_details_or_with_no_crew_says_so(): void
    {
        $crew = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team Maintenance', 'email' => 'crew@example.invalid', 'is_active' => true]);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_auto_send_on_fault_job' => false]);
        $this->crewCard($crew);
        $this->assertSame([], $this->sent, 'switched off: nothing goes by itself');
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_auto_send_on_fault_job' => true]);

        $bare = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'No Details Crew', 'is_active' => true]);
        $card = $this->crewCard($bare);
        $row = RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->first();
        $this->assertSame(RentalJobDispatch::STATUS_NOT_SENT, $row->status);
        $this->assertStringContainsString('NOT sent', $card->updates()->where('update_type', 'link_not_sent')->value('note'));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-job-cards.show', $card))->getContent();
        $this->assertStringContainsString('data-crew-not-sent', $html);

        $card2 = $this->crewCard(null);
        $row2 = RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card2->id)->first();
        $this->assertStringContainsString('No crew has been chosen', $row2->error);
    }

    public function test_a_crew_with_only_a_mobile_gets_the_honest_whatsapp_pair(): void
    {
        $crew = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Phone Crew', 'phone' => '0825559999', 'is_active' => true]);
        $card = $this->crewCard($crew);
        $wa = RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('channel', 'whatsapp')->first();
        $this->assertSame(RentalJobDispatch::STATUS_PREPARED, $wa->status);

        $this->actingAs($this->agent)->post(route('corex.rental-job-cards.crew-link.whatsapp-sent', [$card, $wa->id]))->assertSessionHasErrors('crew_link');
        $this->actingAs($this->agent)->post(route('corex.rental-job-cards.crew-link.whatsapp-open', [$card, $wa->id]))->assertRedirectContains('https://wa.me/27825559999?text=');
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereNull('revoked_at')->count());
        $this->actingAs($this->agent)->post(route('corex.rental-job-cards.crew-link.whatsapp-sent', [$card, $wa->id]))->assertSessionHasNoErrors();
        $this->assertSame(RentalJobDispatch::STATUS_MARKED_SENT, $wa->fresh()->status);
    }

    public function test_the_crew_setting_saves_has_guarded_and_is_in_the_wizard(): void
    {
        $this->assertTrue(RentalPortalSetting::crewLinkAutoSendOnFaultJobFor($this->agency->id), 'on by default');
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/crew-link-auto-send-on-fault-job', ['crew_link_auto_send_on_fault_job' => '0'])->assertRedirect();
        $this->assertFalse(RentalPortalSetting::crewLinkAutoSendOnFaultJobFor($this->agency->id));
        $this->actingAs($this->agent)->post('/corex/settings/rental-portal/crew-link-auto-send-on-fault-job', [])->assertRedirect();
        $this->assertFalse(RentalPortalSetting::crewLinkAutoSendOnFaultJobFor($this->agency->id), 'a post without the field never flips it');
        $json = json_encode(config('agency-onboarding-copy'));
        $this->assertStringContainsString('crew_link_auto_send_on_fault_job', $json);
        $this->assertStringContainsString('updateCrewLinkAutoSendOnFaultJob', $json);
    }

    // ── H: the popups (over the screen, never away from it) ──────────────────────

    private function managerWith(array $keys): User
    {
        foreach (['agent', 'admin'] as $role) {
            foreach (array_merge(['rental_fault_reports.view', 'rental_fault_reports.create', 'rental_work_orders.view', 'rental_work_orders.create', 'rental_fault_reports.raise_work_order', 'rental_fault_reports.send_to_owner'], $keys) as $k) {
                \App\Models\RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $k, 'agency_id' => $this->agency->id], ['scope' => 'all']);
            }
        }
        \App\Services\PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    public function test_the_fault_page_opens_the_contractor_paths_in_popups_for_managers_only_and_keeps_the_warning_alive_in_place(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers']);
        $plain = $this->managerWith([]);
        $plain->forceFill([])->save();
        // a role that has the fault grants but NOT manage_suppliers
        \App\Models\RolePermission::where('role', 'admin')->where('permission_key', 'deals_v2.manage_suppliers')->delete();
        \App\Services\PermissionService::clearCache();
        $fault = $this->fault([], false);

        $html = $this->actingAs($manager)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-popups', $html, 'the popups are on the page');
        $this->assertStringContainsString('data-contractor-popup-form', $html, 'the Supplier Directory\'s own form, in the popup');
        $this->assertStringContainsString('name="specialty"', $html);
        $this->assertStringContainsString('name="service_types[]"', $html);
        $this->assertStringContainsString('data-contractor-popup="types"', $html);
        $this->assertStringContainsString('x-data="{ noContractor: true', $html, 'the warning is driven by page state, so it clears without a reload');
        $this->assertStringContainsString('data-send-variant="covered"', $html, 'the send dialog swaps without a reload');
        $this->assertStringContainsString('x-bind:disabled="!noContractor"', $html);
        // plain agent words inside the popup, none of the developer wording; the directory page keeps its own
        $popup = substr($html, strpos($html, 'data-contractor-popups'));
        foreach (['>Trade', 'Business address', 'Our preferred contractor for this trade', 'Certificates and services they can do'] as $plainWord) {
            $this->assertStringContainsString($plainWord, $popup);
        }
        foreach (['Specialty', 'Preferred for this specialty', 'Service types', 'filters the work-order picker', 'used on documents this supplier signs'] as $devWord) {
            $this->assertStringNotContainsString($devWord, $popup);
        }
        $this->assertStringContainsString('This contractor will be offered for', $popup);
        $this->assertStringNotContainsString('Added to your directory', $popup);
        // the popup form sits OUTSIDE every other form on the page (nothing typed is touched)
        $this->assertDoesNotMatchRegularExpression('#<form[^>]*owner-version[^>]*>(?:(?!</form>).)*data-contractor-popups#s', $html);
    }

    public function test_save_in_the_add_contractor_popup_is_the_directorys_own_validation_and_write_and_says_whether_the_job_is_now_covered(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers']);
        $fault = $this->fault([], false);
        $types = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(['agency_id' => $this->agency->id, 'code' => 'Plumbing'], ['label' => 'Plumbing', 'sort_order' => 1, 'is_active' => true]);

        // the same validation as the directory: a name and a specialty are required
        $this->actingAs($manager)->postJson(route('deals-v2.suppliers.store'), ['fault_id' => $fault->id])->assertStatus(422)->assertJsonValidationErrors(['name', 'specialty']);
        $this->assertFalse(app(RentalFaultContractorService::class)->hasContractorFor($fault));

        $r = $this->actingAs($manager)->postJson(route('deals-v2.suppliers.store'), [
            'name' => 'Popup Plumbing', 'specialty' => 'plumber', 'email' => 'popup@example.invalid', 'service_types' => ['Plumbing'], 'fault_id' => $fault->id,
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('covered', true);

        $p = AgencyServiceProvider::withoutGlobalScopes()->findOrFail($r->json('provider.id'));
        $this->assertSame(['Plumbing'], $p->typeCodes(), 'this fault\'s type was ticked as a trade');
        $this->assertTrue(app(RentalFaultContractorService::class)->hasContractorFor($fault), 'the warning clears on its own');
        $ev = \Illuminate\Support\Facades\DB::table('agency_service_provider_events')->where('service_provider_id', $p->id)->first();
        $this->assertSame([$manager->id, $fault->id, 'created_from_fault'], [$ev->user_id, $ev->rental_fault_report_id, $ev->event]);
    }

    public function test_adding_a_contractor_who_matches_an_archived_one_says_so_instead_of_claiming_success(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers']);
        $fault = $this->fault([], false);
        AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Old Plumber', 'specialty' => 'plumber', 'email' => 'old@example.invalid', 'is_active' => true])->delete();

        $r = $this->actingAs($manager)->postJson(route('deals-v2.suppliers.store'), [
            'name' => 'New Name', 'specialty' => 'plumber', 'email' => 'old@example.invalid', 'service_types' => ['Plumbing'], 'fault_id' => $fault->id,
        ])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('has been archived', $r->json('message'));
        $this->assertFalse(app(RentalFaultContractorService::class)->hasContractorFor($fault));
    }

    public function test_the_existing_contractor_popup_lists_everyone_with_their_fields_of_work_and_save_writes_who_when_and_from_which_fault(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers']);
        $fault = $this->fault([], false);
        $sparky = $this->provider('Sparky', ['email' => 'sparky@example.invalid'], 'Electrical');
        $handy = $this->provider('Handy Andy', ['phone' => '0821230000']);
        $already = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);

        $pick = $this->actingAs($manager)->getJson(route('deals-v2.suppliers.trade-picker', ['fault' => $fault->id]))->assertOk()->json();
        $this->assertSame(['Leaking tap', 'Plumbing', 'Plumbing'], [$pick['type'], $pick['category'], $pick['code']]);
        $rows = collect($pick['contractors'])->keyBy('id');
        $this->assertSame(['Electrical'], $rows[$sparky->id]['trades'], 'their current fields of work');
        $this->assertFalse($rows[$sparky->id]['has_trade']);
        $this->assertTrue($rows[$already->id]['has_trade'], 'marked as already doing it');
        $this->assertCount(3, $rows);

        $this->assertFalse(app(RentalFaultContractorService::class)->hasContractorFor($fault->fresh()) && false);
        $r = $this->actingAs($manager)->postJson(route('deals-v2.suppliers.add-trade'), ['fault_id' => $fault->id, 'provider_ids' => [$sparky->id, $handy->id, 999999]])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('Leaking tap added to ' . implode(', ', $r->json('added')), $r->json('message'));
        $this->assertEqualsCanonicalizing(['Sparky', 'Handy Andy'], $r->json('added'));
        $this->assertTrue($r->json('covered'));
        $this->assertEqualsCanonicalizing(['Electrical', 'Plumbing'], $sparky->fresh()->typeCodes(), 'added to the existing fields of work, nothing removed');
        $this->assertSame(['Plumbing'], $handy->fresh()->typeCodes());

        $events = \Illuminate\Support\Facades\DB::table('agency_service_provider_events')->where('event', 'trade_added')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$manager->id, $fault->id], [$events[0]->user_id, $events[0]->rental_fault_report_id]);
        $this->assertNotNull($events[0]->created_at);
        $this->assertStringContainsString('Agnes Agent' === $manager->name ? 'Agnes Agent' : $manager->name, $events[0]->note);

        // and it shows in the contractor's history on the directory
        $this->assertStringContainsString('Recent changes', $this->actingAs($manager)->get(route('deals-v2.suppliers.index'))->getContent());
    }

    public function test_a_missing_trade_is_added_only_on_request_by_someone_who_may_and_nothing_crosses_agencies_or_permissions(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers', 'deals_v2.manage_pipeline']);
        $roof = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Roof leak', 'category' => 'Structural', 'urgency' => 'urgent', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 2, 'is_active' => true]);
        $fault = $this->fault(['rental_fault_type_id' => $roof->id], false);
        $roofer = $this->provider('Top Roofing', ['email' => 'roof@example.invalid']);

        $this->assertNull($this->actingAs($manager)->getJson(route('deals-v2.suppliers.trade-picker', ['fault' => $fault->id]))->json('code'), 'no trade in the list yet');
        $this->actingAs($manager)->postJson(route('deals-v2.suppliers.add-trade'), ['fault_id' => $fault->id, 'provider_ids' => [$roofer->id]])->assertStatus(422);
        $this->assertSame([], $roofer->fresh()->typeCodes(), 'without the explicit request nothing is created');

        $this->actingAs($manager)->postJson(route('deals-v2.suppliers.add-trade'), ['fault_id' => $fault->id, 'provider_ids' => [$roofer->id], 'create_trade' => true])->assertOk()->assertJsonPath('covered', true);
        $this->assertSame(['Structural'], $roofer->fresh()->typeCodes());
        $this->assertTrue(AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('code', 'Structural')->exists());

        // someone without the trade-list permission cannot create the trade; someone without manage_suppliers cannot reach the popup endpoints at all
        $fault2 = $this->fault(['rental_fault_type_id' => RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Pests', 'category' => 'Pest control', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 3, 'is_active' => true])->id], false);
        \App\Models\RolePermission::where('permission_key', 'deals_v2.manage_pipeline')->delete();
        \App\Services\PermissionService::clearCache();
        $this->actingAs($manager)->postJson(route('deals-v2.suppliers.add-trade'), ['fault_id' => $fault2->id, 'provider_ids' => [$roofer->id], 'create_trade' => true])->assertForbidden();
        \App\Models\RolePermission::where('permission_key', 'deals_v2.manage_suppliers')->delete();
        \App\Services\PermissionService::clearCache();
        $this->actingAs($manager)->getJson(route('deals-v2.suppliers.trade-picker', ['fault' => $fault->id]))->assertForbidden();
        $this->actingAs($manager)->postJson(route('deals-v2.suppliers.add-trade'), ['fault_id' => $fault->id, 'provider_ids' => [$roofer->id]])->assertForbidden();
    }

    public function test_the_work_order_page_opens_the_same_popups_and_refreshes_its_supplier_card_in_place(): void
    {
        $manager = $this->managerWith(['deals_v2.manage_suppliers']);
        $roof = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Roof leak', 'category' => 'Structural', 'urgency' => 'urgent', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 2, 'is_active' => true]);
        $wo = $this->appointViaFault($this->fault(['rental_fault_type_id' => $roof->id]), $this->provider('Ghost', [], null));
        $html = $this->actingAs($manager)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-popups', $html);
        $this->assertStringContainsString('data-contractor-popup="add" data-work-order-id="' . $wo->id . '"', $html);
        $this->assertStringContainsString('data-refresh-on-contractor-change="wo-supplier-card"', $html);
        $this->assertStringNotContainsString('mode=add', $html, 'no link that navigates away to add a contractor');
    }

    // ── G: closed ones collapse, every row has its date ──────────────────────────

    public function test_closed_faults_carry_the_flags_the_collapsed_lists_need(): void
    {
        $fault = $this->fault([], false);
        $ten = function ($f) {
            Sanctum::actingAs($this->portalLogin($this->tenant), ['client']);

            return $this->getJson('/api/v1/client/rentals/fault-reports')->json('fault_reports');
        };
        $rows = collect($ten($fault))->keyBy('id');
        $this->assertFalse($rows[$fault->id]['closed']);
        $this->assertNull($rows[$fault->id]['end_state']);
        $this->assertNotNull($rows[$fault->id]['reported_at']);

        $fault->forceFill(['status' => 'declined'])->save();
        $this->assertSame('not approved', collect($ten($fault))->firstWhere('id', $fault->id)['end_state'], 'the tenant never reads "declined"');
        $fault->forceFill(['status' => 'resolved', 'outcome' => 'repaired'])->save();
        $this->assertSame('repaired', collect($ten($fault))->firstWhere('id', $fault->id)['end_state']);
        $fault->forceFill(['status' => 'cancelled', 'outcome' => null])->save();
        $row = collect($ten($fault))->firstWhere('id', $fault->id);
        $this->assertSame([true, 'cancelled'], [$row['closed'], $row['end_state']]);
        $this->assertSame('you declined', RentalFaultReport::withoutGlobalScopes()->find($fault->id)->forceFill(['status' => 'declined'])->endStateFor('owner'));
    }

    // ── the lease agents are told (through the one place that decides who they are) ─

    public function test_the_agents_are_told_when_a_job_is_sent_not_sent_and_answered(): void
    {
        $spy = \Mockery::mock(RentalWorkOrderService::class)->makePartial();
        $events = [];
        $spy->shouldReceive('notifyAgentOfOwnerAction')->andReturnUsing(function ($wo, $key) use (&$events) { $events[] = $key; });
        $this->app->instance(RentalWorkOrderService::class, $spy);

        $p = $this->provider('Drip Plumbing', ['email' => 'drip@example.invalid'], 'Plumbing');
        $wo = $this->appointViaFault($this->fault(), $p);
        $this->assertContains('rental_work_order.contractor_job_sent', $events);

        $ghost = $this->provider('Ghost Plumbing', [], 'Plumbing');
        $this->appointViaFault($this->fault(['title' => 'Other tap']), $ghost);
        $this->assertContains('rental_work_order.contractor_job_not_sent', $events);

        app(Jobs::class)->recordResponse($wo->fresh(), 'accepted');
        $this->assertContains('rental_work_order.contractor_responded', $events);
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('notification_event_types')->whereIn('key', [
            'rental_work_order.contractor_job_sent', 'rental_work_order.contractor_job_not_sent', 'rental_work_order.contractor_responded',
        ])->count(), 'all three event keys are registered');
    }
}
