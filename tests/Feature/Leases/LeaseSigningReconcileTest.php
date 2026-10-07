<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\SignatureEnvelopeCancelled;
use App\Events\Docuperfect\SignatureEnvelopeDeclined;
use App\Events\Docuperfect\SignatureEnvelopeExpired;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Events\Docuperfect\SignatureEnvelopeSent;
use App\Events\Rentals\LeaseAgreementFailed;
use App\Events\Rentals\LeaseAgreementSigned;
use App\Mail\Rentals\LeaseAgreementStatusMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEscalation;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseAgreementHarvest;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\Rentals\LeaseSigningStateService;
use App\Services\Rentals\PreviousTermValuesReader;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.5 / §15.15 / §15.16 / §15.21 (Build L3b) — what happens to a lease once its agreement is
 * out: the lease's signing state follows the e-sign envelope (the engine's five announcements, applied by
 * UpdateLeaseSigningState and repaired by the safety net), and when the agent's final approval lands the lease is
 * signed, accepted and active with its captured dates, its signed copy findable, its agreement details harvested, the
 * previous term expired and the escalation recorded on a renewal.
 *
 * Mirrors reality (BUILD_STANDARD §5): the same completion announced twice, a property with ANOTHER lease already
 * active, a renewal, a signer who declined, a lease cancelled with the agreement out, a missed announcement repaired
 * on read and by the nightly command, a mail that fails to send, a listener that throws, an old lease e-signed before
 * any of this existed, and a second agency.
 */
final class LeaseSigningReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi@example.test', 'id_number' => '8002025009081',
        ]);
    }

    // ═══ the one mapping ═══

    #[DataProvider('statusMapProvider')]
    public function test_every_engine_status_means_one_thing_for_the_lease(string $engineStatus, string $expected): void
    {
        $envelope = new SignatureTemplate(['status' => $engineStatus]);

        $this->assertSame($expected, Lease::signingStatusFor($envelope));
    }

    public static function statusMapProvider(): array
    {
        $out = Lease::SIGNING_OUT_FOR_SIGNING;
        $review = Lease::SIGNING_AWAITING_AGENT_REVIEW;

        return [
            'draft' => ['draft', $out], 'ready' => ['ready', $out], 'signing' => ['signing', $out],
            'awaiting tenant' => ['awaiting_tenant', $out], 'awaiting landlord' => ['awaiting_landlord', $out],
            'partial' => ['partial', $out], 'deferred' => ['awaiting_deferred', $out], 'revived' => ['revived', $out],
            'pending agent approval' => ['pending_agent_approval', $review], 'returned' => ['returned_to_candidate', $review],
            'amendment review' => ['amendment_review', $review], 'amendment initialing' => ['amendment_initialing', $review],
            'amendment chain review' => ['amendment_chain_review', $review], 'editor reacceptance' => ['editor_reacceptance', $review],
            'declined' => ['declined', Lease::SIGNING_DECLINED], 'rejected' => ['rejected', Lease::SIGNING_DECLINED],
            'cancelled' => ['cancelled', Lease::SIGNING_VOIDED],
            'expired' => ['expired', Lease::SIGNING_EXPIRED], 'lapsed' => ['lapsed', Lease::SIGNING_EXPIRED],
            're-lapsed' => ['re_lapsed', Lease::SIGNING_EXPIRED], 'extension proposed' => ['extension_proposed', Lease::SIGNING_EXPIRED],
        ];
    }

    public function test_a_completed_envelope_is_signed_only_once_the_signed_copy_is_filed(): void
    {
        $filing = new SignatureTemplate(['status' => SignatureTemplate::STATUS_COMPLETED]);
        $filed = new SignatureTemplate(['status' => SignatureTemplate::STATUS_COMPLETED, 'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED]);

        $this->assertSame(Lease::SIGNING_AWAITING_AGENT_REVIEW, Lease::signingStatusFor($filing));
        $this->assertSame(Lease::SIGNING_SIGNED, Lease::signingStatusFor($filed));
    }

    // ═══ signed and approved ═══

    public function test_the_approval_signs_accepts_and_activates_the_lease_with_its_captured_dates(): void
    {
        Mail::fake();
        Event::fake([LeaseAgreementSigned::class]);
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->finalise($envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status);
        $this->assertSame('2026-11-01', $lease->start_date->toDateString());
        $this->assertSame('2027-10-31', $lease->end_date->toDateString());
        $this->assertSame(8500.0, (float) $lease->rental_amount, 'the lease record is not overwritten by the document');
        $this->assertNotNull($lease->signed_at);
        $this->assertNotNull($lease->accepted_at);
        $this->assertSame($this->agent->id, $lease->accepted_by_user_id, 'with nobody signed in, the agent who sent it');
        $this->assertSame(Lease::SOURCE_ESIGN_DOCUMENT, $lease->source);
        $this->assertSame($document->id, $lease->source_document_id);
        $this->assertSame($document->id, $lease->agreement_document_id);

        $this->assertSame(
            [LeaseEvent::TYPE_AGREEMENT_SIGNED, LeaseEvent::TYPE_AGREEMENT_ACCEPTED, LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING],
            LeaseEvent::where('lease_id', $lease->id)->orderBy('id')->pluck('event_type')->all()
        );
        Event::assertDispatched(LeaseAgreementSigned::class, fn ($e) => $e->lease->id === $lease->id && $e->signatureTemplateId === $envelope->id);
    }

    public function test_the_agent_is_told_in_the_app_and_by_mail_when_the_lease_is_signed(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->finalise($envelope, $document);

        $notification = $this->agent->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('signed and the lease is now active', $notification->data['message']);
        $this->assertSame(route('corex.leases.show', $lease->id), $notification->data['url']);
        Mail::assertSent(LeaseAgreementStatusMail::class, fn ($m) => $m->hasTo($this->agent->email) && $m->outcome === LeaseAgreementStatusMail::OUTCOME_SIGNED);
    }

    public function test_the_signed_copy_is_found_through_the_lease(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut();
        $filed = \App\Models\Document::create([
            'original_name' => 'lease-signed.pdf', 'storage_path' => 'esign/lease-signed.pdf', 'disk' => 'local',
            'source_type' => 'esign', 'source_id' => $envelope->id, 'uploaded_by' => $this->agent->id,
        ]);

        $this->finalise($envelope, $document);

        $this->assertSame($filed->id, $lease->fresh()->signedDocument()?->id);
    }

    public function test_the_same_completion_announced_twice_changes_nothing_the_second_time(): void
    {
        Mail::fake();
        Event::fake([LeaseAgreementSigned::class]);
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->finalise($envelope, $document);
        $signedAt = $lease->fresh()->signed_at;
        $this->travel(5)->minutes();
        $this->finalise($envelope, $document);

        $lease = $lease->fresh();
        $this->assertEquals($signedAt, $lease->signed_at);
        $this->assertSame(3, LeaseEvent::where('lease_id', $lease->id)->count(), 'one signed, one accepted, one activated — never repeated');
        Event::assertDispatchedTimes(LeaseAgreementSigned::class, 1);
        Mail::assertSent(LeaseAgreementStatusMail::class, 1);
    }

    public function test_whoever_is_signed_in_at_the_approval_is_recorded_as_the_approver(): void
    {
        Mail::fake();
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->actingAs($other);
        $this->finalise($envelope, $document);

        $this->assertSame($other->id, $lease->fresh()->accepted_by_user_id);
    }

    public function test_a_renewal_expires_the_previous_term_and_records_the_escalation(): void
    {
        Mail::fake();
        $previous = Lease::create($this->leaseAttributes(['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31']));
        [$renewal, $envelope, $document] = $this->leaseOut(['previous_lease_id' => $previous->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);

        $this->finalise($envelope, $document);

        $renewal = $renewal->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
        $this->assertSame($renewal->id, $previous->fresh()->renewed_lease_id);
        $escalation = LeaseEscalation::where('lease_id', $renewal->id)->first();
        $this->assertNotNull($escalation, 'the rent change is on record');
        $this->assertSame(8000.0, (float) $escalation->previous_rental_amount);
        $this->assertSame(8500.0, (float) $escalation->new_rental_amount);
    }

    public function test_a_signed_lease_whose_property_has_another_active_lease_stays_signed_and_draft_with_a_clear_next_step(): void
    {
        Mail::fake();
        Lease::create($this->leaseAttributes(['status' => Lease::STATUS_ACTIVE, 'start_date' => '2025-01-01', 'end_date' => '2026-12-31']));
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->finalise($envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'never two active leases on one property');
        $this->assertNotNull($lease->accepted_at, 'the signing itself is not lost');
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_SIGNED_NOT_ACTIVATED)->count());
        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING)->count());

        $next = app(\App\Services\Rentals\LeaseHubService::class)->nextStep($lease, $this->agent);
        $this->assertSame('Signed — another lease is still active on this property. End or renew it, then activate.', $next['label']);
        Mail::assertSent(LeaseAgreementStatusMail::class, fn ($m) => $m->outcome === LeaseAgreementStatusMail::OUTCOME_SIGNED_NOT_ACTIVE);
    }

    public function test_a_lease_that_was_cancelled_while_its_agreement_was_out_cannot_be_activated_by_a_late_completion(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut(['status' => Lease::STATUS_CANCELLED]);

        $this->finalise($envelope, $document);

        $this->assertSame(Lease::STATUS_CANCELLED, $lease->fresh()->status);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_SIGNED_NOT_ACTIVATED)->count());
    }

    public function test_a_mail_that_cannot_be_sent_never_undoes_the_lease(): void
    {
        $this->mock(RentalMailDispatcher::class, fn ($m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('smtp down')));
        [$lease, $envelope, $document] = $this->leaseOut();

        $this->finalise($envelope, $document);

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertCount(1, $this->agent->notifications, 'the in-app notice still reached the agent');
    }

    public function test_a_failing_listener_is_swallowed_and_never_reaches_the_signing(): void
    {
        $this->mock(LeaseSigningStateService::class, fn ($m) => $m->shouldReceive('applyEnvelope')->andThrow(new \RuntimeException('boom')));
        [$lease, $envelope, $document] = $this->leaseOut();

        event(new SignatureEnvelopeFinalized($envelope->id, $document->id, $this->agency->id));

        $this->assertTrue(true, 'no exception escaped the listener');
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->fresh()->signing_status, 'left for the safety net');
    }

    // ═══ in flight ═══

    public function test_a_request_just_sent_means_out_for_signing_even_while_the_envelope_still_reads_pending_approval(): void
    {
        [$lease, $envelope] = $this->leaseOut([], SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, Lease::SIGNING_PREPARED);

        event(new SignatureEnvelopeSent($envelope->id, $envelope->document_id, $this->agency->id));

        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->fresh()->signing_status);
    }

    public function test_the_safety_net_follows_an_envelope_into_waiting_for_the_agents_approval_and_back(): void
    {
        [$lease, $envelope] = $this->leaseOut();

        $envelope->update(['status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL]);
        $this->assertTrue($lease->fresh()->reconcileSigning());
        $this->assertSame(Lease::SIGNING_AWAITING_AGENT_REVIEW, $lease->fresh()->signing_status);

        $envelope->update(['status' => SignatureTemplate::STATUS_AWAITING_LANDLORD]);
        $this->assertTrue($lease->fresh()->reconcileSigning());
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->fresh()->signing_status);

        $this->assertFalse($lease->fresh()->reconcileSigning(), 'nothing changed, so nothing is reported changed');
    }

    public function test_a_completed_envelope_whose_signed_copy_is_still_being_filed_does_not_yet_activate_the_lease(): void
    {
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now()]);

        $lease->fresh()->reconcileSigning();

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_AWAITING_AGENT_REVIEW, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);

        $card = app(LeaseSigningLauncher::class)->cardFor($lease, $this->agent);
        $this->assertSame('Signed — filing the document', $card['label']);
        $this->assertNull($card['approve_url'], 'it is already approved');
        $this->assertSame('Signed — filing the document', app(\App\Services\Rentals\LeaseHubService::class)->nextStep($lease, $this->agent)['label']);
    }

    // ═══ declined, cancelled, expired ═══

    public function test_a_declined_agreement_leaves_the_lease_a_draft_tells_the_agent_and_offers_prepare_again(): void
    {
        Mail::fake();
        Event::fake([LeaseAgreementFailed::class]);
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_DECLINED]);

        event(new SignatureEnvelopeDeclined($envelope->id, $envelope->document_id, $this->agency->id, 'Thandi Nkosi declined — rent is too high'));

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_DECLINED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame('Thandi Nkosi declined — rent is too high', $lease->signing_failure_note);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_DECLINED)->count());
        $this->assertStringContainsString('was declined', $this->agent->notifications()->first()->data['message']);
        Mail::assertSent(LeaseAgreementStatusMail::class, fn ($m) => $m->hasTo($this->agent->email) && $m->outcome === LeaseAgreementStatusMail::OUTCOME_DECLINED);
        Event::assertDispatched(LeaseAgreementFailed::class, fn ($e) => $e->lease->id === $lease->id && $e->outcome === Lease::SIGNING_DECLINED);

        $card = app(LeaseSigningLauncher::class)->cardFor($lease, $this->agent);
        $this->assertTrue($card['can_prepare_again']);
    }

    public function test_a_decline_with_no_reason_given_reads_the_reason_from_the_envelope(): void
    {
        Mail::fake();
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_REJECTED, 'rejection_reason' => 'Wrong tenant on page 1']);

        event(new SignatureEnvelopeDeclined($envelope->id, $envelope->document_id, $this->agency->id));

        $this->assertSame('Wrong tenant on page 1', $lease->fresh()->signing_failure_note);
    }

    public function test_cancelling_in_e_sign_voids_the_lease_agreement_without_telling_the_agent_who_did_it(): void
    {
        Mail::fake();
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_CANCELLED, 'cancellation_reason' => 'Rent changed']);

        $this->actingAs($this->agent);
        event(new SignatureEnvelopeCancelled($envelope->id, $envelope->document_id, $this->agency->id, 'Rent changed'));

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_VOIDED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_VOIDED)->first();
        $this->assertSame($this->agent->id, $event->actor_user_id);
        Mail::assertNothingSent();
        $this->assertCount(0, $this->agent->notifications);
    }

    public function test_expired_signing_links_expire_the_lease_agreement_and_the_agent_is_told(): void
    {
        Mail::fake();
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_EXPIRED]);

        event(new SignatureEnvelopeExpired($envelope->id, $envelope->document_id, $this->agency->id, 'the signing links ran out before everyone signed'));

        $this->assertSame(Lease::SIGNING_EXPIRED, $lease->fresh()->signing_status);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_EXPIRED)->count());
        Mail::assertSent(LeaseAgreementStatusMail::class, fn ($m) => $m->outcome === LeaseAgreementStatusMail::OUTCOME_EXPIRED);
    }

    public function test_a_lease_that_has_already_ended_its_agreement_is_not_moved_again_by_a_late_announcement(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut([], SignatureTemplate::STATUS_CANCELLED, Lease::SIGNING_VOIDED);

        $this->finalise($envelope, $document);
        event(new SignatureEnvelopeDeclined($envelope->id, $envelope->document_id, $this->agency->id, 'late'));

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_VOIDED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->count());
    }

    public function test_an_envelope_that_did_not_come_from_a_lease_changes_nothing_and_throws_nothing(): void
    {
        Mail::fake();
        [$lease, , ] = $this->leaseOut();
        [$stray, $document] = $this->envelope(SignatureTemplate::STATUS_COMPLETED, ['finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED]);

        event(new SignatureEnvelopeFinalized($stray->id, $document->id, $this->agency->id));
        event(new SignatureEnvelopeFinalized(999999, null, null));

        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->fresh()->signing_status);
        $this->assertSame(0, LeaseEvent::count());
    }

    public function test_only_the_lease_whose_agreement_it_is_moves(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut();
        $neighbour = Lease::create($this->leaseAttributes(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING]));

        $this->finalise($envelope, $document);

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertSame(Lease::STATUS_DRAFT, $neighbour->fresh()->status);
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $neighbour->fresh()->signing_status);
    }

    // ═══ cancelling the lease with the agreement out ═══

    public function test_cancelling_a_lease_with_its_agreement_out_records_one_void_not_two(): void
    {
        Mail::fake();
        [$lease, $envelope] = $this->leaseOut();
        $request = SignatureRequest::create([
            'signature_template_id' => $envelope->id, 'party_role' => 'tenant', 'signer_name' => 'Thandi Nkosi', 'signer_email' => 'thandi@example.test',
            'status' => 'pending', 'signing_order' => 2, 'token' => Str::random(40), 'token_expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant changed their mind'])->assertRedirect();

        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $envelope->fresh()->status);
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(Lease::SIGNING_VOIDED, $lease->fresh()->signing_status);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_VOIDED)->count());
        $this->assertSame(
            'Tenant changed their mind',
            LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_VOIDED)->first()->metadata['reason']
        );
    }

    public function test_the_signed_lease_card_offers_the_signed_copy_and_the_signing_certificate(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut();
        \App\Models\Document::create([
            'original_name' => 'lease-signed.pdf', 'storage_path' => 'esign/lease-signed.pdf', 'disk' => 'local',
            'source_type' => 'esign', 'source_id' => $envelope->id, 'uploaded_by' => $this->agent->id,
        ]);

        $this->finalise($envelope, $document);

        $card = app(LeaseSigningLauncher::class)->cardFor($lease->fresh(), $this->agent);
        $this->assertSame('Signed', $card['label']);
        $this->assertSame(route('docuperfect.signatures.download', $document->id), $card['signed_copy_url']);
        $this->assertSame(route('docuperfect.signatures.certificate', $document->id), $card['certificate_url']);

        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertSee('Download signed copy', false)->assertSee('Download signing certificate', false);
    }

    // ═══ the safety net ═══

    public function test_opening_the_lease_repairs_a_status_the_engine_never_announced(): void
    {
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_DECLINED, 'rejection_reason' => 'Not for me']);

        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->assertSee('Prepare again', false);

        $this->assertSame(Lease::SIGNING_DECLINED, $lease->fresh()->signing_status);
    }

    public function test_the_leases_list_repairs_the_lease_rows_on_the_page(): void
    {
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update(['status' => SignatureTemplate::STATUS_EXPIRED]);

        $this->actingAs($this->agent)->get(route('corex.leases.index'))->assertOk();

        $this->assertSame(Lease::SIGNING_EXPIRED, $lease->fresh()->signing_status);
    }

    public function test_a_missed_finalisation_is_repaired_on_read_and_the_lease_goes_active(): void
    {
        Mail::fake();
        [$lease, $envelope] = $this->leaseOut();
        $envelope->update([
            'status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(), 'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED,
        ]);

        $this->assertTrue($lease->fresh()->reconcileSigning());

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->fresh()->signing_status);
    }

    public function test_the_nightly_command_repairs_every_agency_and_leaves_the_rest_alone(): void
    {
        Mail::fake();
        [$mine, $myEnvelope] = $this->leaseOut();
        $myEnvelope->update(['status' => SignatureTemplate::STATUS_DECLINED]);

        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = Branch::create(['agency_id' => $rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        $rivalProperty = Property::create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'agent_id' => $rivalAgent->id, 'title' => 'Karoo farm', 'status' => 'active', 'listing_type' => 'rental']);
        $rivalDocument = Document::create(['name' => 'Lease', 'owner_id' => $rivalAgent->id, 'agency_id' => $rival->id]);
        $rivalEnvelope = SignatureTemplate::create([
            'agency_id' => $rival->id, 'document_id' => $rivalDocument->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_EXPIRED, 'created_by' => $rivalAgent->id,
        ]);
        $theirs = Lease::create([
            'agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'property_id' => $rivalProperty->id, 'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 5000, 'start_date' => '2026-11-01', 'source' => 'manual', 'signing_status' => Lease::SIGNING_OUT_FOR_SIGNING,
            'signature_template_id' => $rivalEnvelope->id,
        ]);
        $fine = $this->leaseOut()[0]; // in step with its envelope: must stay exactly as it is

        $this->assertSame(0, Artisan::call('leases:reconcile-signing'));

        $this->assertStringContainsString('3 lease agreement(s)', Artisan::output());
        $this->assertSame(Lease::SIGNING_DECLINED, $mine->fresh()->signing_status);
        $this->assertSame(Lease::SIGNING_EXPIRED, $theirs->fresh()->signing_status);
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $fine->fresh()->signing_status);
    }

    // ═══ what the signed agreement said, read back ═══

    public function test_the_printed_values_of_the_signed_agreement_are_harvested_into_the_leases_agreement_details(): void
    {
        [$lease, , $document] = $this->leaseOut();
        $this->mapFor($lease, [
            'adults' => ['field' => 'occupants'], 'pets' => ['field' => 'pets_allowed'], 'escalation_percent' => ['field' => 'escalation'],
            'earliest_termination_date' => ['field' => 'earliest_term'], 'pool_rule' => ['field' => 'pool_rule'],
            'rent' => ['field' => 'monthly_rental'], 'tenant_name' => ['field' => 'lessee_name'], 'rent_in_words' => ['field' => 'rent_words'],
        ]);
        $document->update(['web_template_data' => ['_fill_review_overlay' => [
            'occupants' => '3', 'pets_allowed' => 'One small dog', 'escalation' => '7,5 %', 'earliest_term' => '1 May 2027',
            'pool_rule' => 'Landlord services the pool', 'monthly_rental' => 'R9 999', 'lessee_name' => 'Somebody Else', 'rent_words' => 'nine thousand',
        ]]]);

        $terms = app(LeaseAgreementHarvest::class)->fromDocument($lease->fresh(), $document->fresh());

        $this->assertSame(3, $terms->adults);
        $this->assertSame('One small dog', $terms->pets);
        $this->assertSame('7.50', (string) $terms->escalation_percent);
        $this->assertSame('2027-05-01', $terms->earliest_termination_date->toDateString());
        $this->assertSame(['pool_rule' => 'Landlord services the pool'], $terms->extra, 'an agency-specific extra is kept as text — and nothing else is');
        $this->assertSame(LeaseAgreementTerms::SOURCE_ESIGN_HARVEST, $terms->source);
        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount, 'the lease record is only ever changed by the agent confirming');
    }

    public function test_the_harvest_runs_at_approval_and_what_was_corrected_in_fill_and_review_reaches_the_next_renewal(): void
    {
        Mail::fake();
        [$lease, $envelope, $document] = $this->leaseOut();
        LeaseAgreementTerms::forLease($lease)->forceFill(['adults' => 1, 'pets' => 'None'])->save();
        $this->mapFor($lease, ['adults' => ['field' => 'occupants'], 'pets' => ['field' => 'pets_allowed']]);
        $document->update(['web_template_data' => ['_fill_review_overlay' => ['occupants' => '2', 'pets_allowed' => '']]]);

        $this->finalise($envelope, $document);

        // Build L3c (§15.8, R6): a value corrected in the agreement is a DIFFERENCE, not something the completion may
        // write into the lease on its own. The lease is signed but stays a draft, and its agreement details are untouched
        // until the agent confirms ...
        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame(1, LeaseAgreementTerms::where('lease_id', $lease->id)->first()->adults, 'not silently overwritten');

        // ... and then the corrected value is what the next renewal pre-fills from. A blank in the document never wipes
        // what was captured: the agent says what the agreement means by it.
        $verdict = app(\App\Services\Rentals\LeaseAgreementCheck::class)->verdict($lease);
        app(\App\Services\Rentals\LeaseAgreementConfirmService::class)->confirm($lease, $this->agent, $verdict['fingerprint'], ['pets' => 'None']);

        $terms = LeaseAgreementTerms::where('lease_id', $lease->id)->first();
        $this->assertSame(2, $terms->adults, 'the corrected value, once confirmed');
        $this->assertSame('None', $terms->pets);
    }

    public function test_a_document_with_no_known_map_harvests_nothing_and_never_guesses(): void
    {
        [$lease, , $document] = $this->leaseOut();
        $document->update(['web_template_data' => ['_fill_review_overlay' => ['occupants' => '3']]]);

        $this->assertNull(app(LeaseAgreementHarvest::class)->fromDocument($lease, $document));
        $this->assertSame(0, LeaseAgreementTerms::count());
    }

    public function test_an_old_lease_e_signed_before_the_terms_existed_fills_its_terms_the_first_time_a_renewal_reads_it(): void
    {
        [$previous, , $document] = $this->leaseOut(['status' => Lease::STATUS_ACTIVE, 'source' => Lease::SOURCE_ESIGN_DOCUMENT], SignatureTemplate::STATUS_COMPLETED, Lease::SIGNING_SIGNED);
        $previous->update(['source_document_id' => $document->id]);
        $this->mapFor($previous, ['adults' => ['field' => 'occupants']]);
        $document->update(['web_template_data' => ['_fill_review_overlay' => ['occupants' => '4']]]);
        $this->assertSame(0, LeaseAgreementTerms::count());

        $terms = app(PreviousTermValuesReader::class)->for($previous->fresh());

        $this->assertSame(4, $terms->adults);
        $this->assertSame(LeaseAgreementTerms::SOURCE_ESIGN_HARVEST, $terms->source);
        $this->assertSame(1, LeaseAgreementTerms::count(), 'written once, found next time');
        $this->assertSame($terms->id, app(PreviousTermValuesReader::class)->for($previous->fresh())->id);
    }

    public function test_a_lease_with_no_terms_and_no_document_has_nothing_on_record(): void
    {
        $lease = Lease::create($this->leaseAttributes());

        $this->assertNull(app(PreviousTermValuesReader::class)->for($lease));
    }

    // ═══ helpers ═══

    /** @param array<string,mixed> $overrides */
    private function leaseAttributes(array $overrides = []): array
    {
        return $overrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ];
    }

    /**
     * A draft lease with its agreement in flight: an envelope in $envelopeStatus, its document, the lease linked to
     * both (as LeaseSigningLauncher::linkEnvelope does when the agent prepares signing), and one tenant.
     *
     * @param array<string,mixed> $leaseOverrides
     * @return array{0: Lease, 1: SignatureTemplate, 2: Document}
     */
    private function leaseOut(array $leaseOverrides = [], string $envelopeStatus = SignatureTemplate::STATUS_AWAITING_TENANT, string $signingStatus = Lease::SIGNING_OUT_FOR_SIGNING): array
    {
        [$envelope, $document] = $this->envelope($envelopeStatus);
        $lease = Lease::create($this->leaseAttributes($leaseOverrides + [
            'signing_status' => $signingStatus,
            'signature_template_id' => $envelope->id,
            'agreement_document_id' => $document->id,
        ]));
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return [$lease, $envelope, $document];
    }

    /**
     * @param array<string,mixed> $envelopeOverrides
     * @return array{0: SignatureTemplate, 1: Document}
     */
    private function envelope(string $status = SignatureTemplate::STATUS_SIGNING, array $envelopeOverrides = []): array
    {
        $document = Document::create([
            'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        $envelope = SignatureTemplate::create($envelopeOverrides + [
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => $status, 'created_by' => $this->agent->id,
        ]);

        return [$envelope, $document];
    }

    /** What the e-sign engine does at completion: the envelope is completed and its signed copy filed, then it announces it. */
    private function finalise(SignatureTemplate $envelope, Document $document): void
    {
        $envelope->update([
            'status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(),
            'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED,
        ]);

        event(new SignatureEnvelopeFinalized($envelope->id, $document->id, $this->agency->id));
    }

    /** Link the lease's agreement template to the agency's field map, as Settings → Rental lease agreements does. */
    private function mapFor(Lease $lease, array $map): RentalLeaseTemplate
    {
        $template = new Template(['name' => 'Residential lease', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $lease->update(['agreement_template_id' => $template->id]);

        return RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Residential lease', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true, 'field_map' => $map,
        ]);
    }
}
