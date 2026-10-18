<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Events\Docuperfect\SignatureEnvelopeCancelled;
use App\Events\Docuperfect\SignatureEnvelopeDeclined;
use App\Events\Docuperfect\SignatureEnvelopeExpired;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Events\Docuperfect\SignatureEnvelopeSent;
use App\Mail\Signatures\DocumentCancelledMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.15 (Build L3b) — the e-sign engine announces five things about an envelope (sent, finalised,
 * declined, cancelled, expired) as domain events, from the exact places it changes that state; and one failing
 * listener can never disturb a signing that has legally happened.
 *
 * The lease side of what those announcements do is LeaseSigningReconcileTest. This file pins the engine side: each
 * emission point fires once with the right ids, "cancel document" still does everything it did (now through
 * SignatureService::cancelEnvelope()), and both finalisation paths — the synchronous cascade and the queued job —
 * announce through the one recorder they share.
 */
final class SignatureEnvelopeEventsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_sending_a_signing_request_announces_the_envelope_as_sent(): void
    {
        Mail::fake();
        Event::fake([SignatureEnvelopeSent::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_SIGNING);
        $request = $this->request($envelope, SignatureRequest::STATUS_WAITING);

        app(SignatureService::class)->sendSigningRequest($request);

        Event::assertDispatchedTimes(SignatureEnvelopeSent::class, 1);
        Event::assertDispatched(SignatureEnvelopeSent::class, fn ($e) => $e->signatureTemplateId === $envelope->id
            && $e->documentId === $envelope->document_id && $e->agencyId === $this->agency->id);
    }

    public function test_a_party_who_does_not_sign_is_never_invited_and_so_nothing_is_announced(): void
    {
        Mail::fake();
        Event::fake([SignatureEnvelopeSent::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_SIGNING);
        $request = $this->request($envelope, SignatureRequest::STATUS_WAITING, ['signer_email' => '']);

        app(SignatureService::class)->sendSigningRequest($request);

        Event::assertNotDispatched(SignatureEnvelopeSent::class);
    }

    public function test_recording_a_successful_finalisation_announces_the_envelope_as_finalised(): void
    {
        Event::fake([SignatureEnvelopeFinalized::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_COMPLETED);

        app(SignatureService::class)->recordFinalizationSucceeded($envelope);

        Event::assertDispatchedTimes(SignatureEnvelopeFinalized::class, 1);
        Event::assertDispatched(SignatureEnvelopeFinalized::class, fn ($e) => $e->signatureTemplateId === $envelope->id && $e->documentId === $envelope->document_id);
        $this->assertSame(SignatureTemplate::FINALIZATION_SUCCEEDED, $envelope->fresh()->finalization_status, 'the announcement is made after the state is saved');
    }

    public function test_a_failed_finalisation_announces_nothing(): void
    {
        Event::fake([SignatureEnvelopeFinalized::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_COMPLETED);

        app(SignatureService::class)->recordFinalizationFailed($envelope, 'disk full');

        Event::assertNotDispatched(SignatureEnvelopeFinalized::class);
    }

    public function test_both_finalisation_paths_announce_through_the_one_recorder(): void
    {
        $service = file_get_contents(base_path('app/Services/Docuperfect/SignatureService.php'));
        $job = file_get_contents(base_path('app/Jobs/Docuperfect/FinalizeSignedDocumentJob.php'));

        $this->assertStringContainsString('$this->recordFinalizationSucceeded($template);', $service, 'the synchronous cascade records success');
        $this->assertStringContainsString('$signatureService->recordFinalizationSucceeded($template);', $job, 'the queued job records success');
        $this->assertSame(1, substr_count($service, 'SignatureEnvelopeFinalized::class'), 'and the announcement is made in exactly one place');
    }

    public function test_a_decline_is_announced_after_it_is_saved_with_who_and_why(): void
    {
        Event::fake([SignatureEnvelopeDeclined::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $request = $this->request($envelope);

        app(SignatureService::class)->declineRequest($request, 'Rent is too high');

        $this->assertSame(SignatureTemplate::STATUS_DECLINED, $envelope->fresh()->status);
        Event::assertDispatchedTimes(SignatureEnvelopeDeclined::class, 1);
        Event::assertDispatched(SignatureEnvelopeDeclined::class, fn ($e) => $e->signatureTemplateId === $envelope->id
            && $e->reason === 'Nomsa Dlamini declined — Rent is too high');
    }

    public function test_a_decline_with_no_reason_still_says_who(): void
    {
        Event::fake([SignatureEnvelopeDeclined::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);

        app(SignatureService::class)->declineRequest($this->request($envelope));

        Event::assertDispatched(SignatureEnvelopeDeclined::class, fn ($e) => $e->reason === 'Nomsa Dlamini declined');
    }

    public function test_links_that_run_out_announce_the_envelope_as_expired_only_when_nobody_can_still_sign(): void
    {
        Event::fake([SignatureEnvelopeExpired::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $stale = $this->request($envelope, SignatureRequest::STATUS_PENDING, ['token_expires_at' => now()->subDay()]);
        $stillLive = $this->request($envelope, SignatureRequest::STATUS_PENDING, ['party_role' => 'landlord', 'signer_name' => 'Pieter Botha', 'signer_email' => 'pieter@example.test']);

        $this->assertSame(1, app(SignatureService::class)->expireOutstandingRequests());
        Event::assertNotDispatched(SignatureEnvelopeExpired::class);
        $this->assertSame(SignatureTemplate::STATUS_AWAITING_TENANT, $envelope->fresh()->status);

        $stillLive->update(['token_expires_at' => now()->subHour()]);
        app(SignatureService::class)->expireOutstandingRequests();

        $this->assertSame(SignatureTemplate::STATUS_EXPIRED, $envelope->fresh()->status);
        Event::assertDispatchedTimes(SignatureEnvelopeExpired::class, 1);
        Event::assertDispatched(SignatureEnvelopeExpired::class, fn ($e) => $e->signatureTemplateId === $envelope->id);
        $this->assertSame('expired', $stale->fresh()->status);
    }

    public function test_a_ceremony_that_passes_its_legal_deadline_announces_the_envelope_as_expired(): void
    {
        Event::fake([SignatureEnvelopeExpired::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT, ['legal_deadline_at' => now()->subDay()]);

        $this->assertSame(1, app(SignatureService::class)->lapseExpiredCeremonies());

        $this->assertSame(SignatureTemplate::STATUS_LAPSED, $envelope->fresh()->status);
        Event::assertDispatched(SignatureEnvelopeExpired::class, fn ($e) => $e->signatureTemplateId === $envelope->id && $e->reason === 'the signing deadline passed');
    }

    // ═══ cancelling ═══

    public function test_cancelling_an_envelope_does_everything_it_did_and_announces_it(): void
    {
        Mail::fake();
        Event::fake([SignatureEnvelopeCancelled::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $waiting = $this->request($envelope);
        $done = $this->request($envelope, SignatureRequest::STATUS_COMPLETED, ['party_role' => 'agent', 'signer_name' => 'The agent', 'signer_email' => 'agent@example.test']);

        $notified = app(SignatureService::class)->cancelEnvelope($envelope, $this->agent, 'Rent changed', '10.0.0.1', 'phpunit');

        $this->assertSame(1, $notified);
        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $envelope->fresh()->status);
        $this->assertSame('Rent changed', $envelope->fresh()->cancellation_reason);
        $this->assertSame($this->agent->id, $envelope->fresh()->cancelled_by);
        $this->assertNotNull($envelope->fresh()->cancelled_at);
        $this->assertSame('cancelled', $waiting->fresh()->status, 'the waiting party\'s link stops working');
        $this->assertSame('completed', $done->fresh()->status, 'a party who already signed stays signed');
        Mail::assertSent(DocumentCancelledMail::class, 1);
        Mail::assertSent(DocumentCancelledMail::class, fn ($m) => $m->hasTo('nomsa@example.co.za'));
        Event::assertDispatched(SignatureEnvelopeCancelled::class, fn ($e) => $e->signatureTemplateId === $envelope->id && $e->reason === 'Rent changed');
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $envelope->id, 'action' => 'cancelled', 'actor_ip_address' => '10.0.0.1', 'actor_id' => $this->agent->id]);
    }

    public function test_the_cancel_document_screen_action_goes_through_it_unchanged(): void
    {
        Mail::fake();
        Event::fake([SignatureEnvelopeCancelled::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $this->request($envelope);

        $this->actingAs($this->agent)
            ->post(route('docuperfect.esign.cancelDocument', $envelope), ['cancellation_reason' => 'Changed my mind'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Document "Lease agreement" has been cancelled. 1 waiting parties notified.');

        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $envelope->fresh()->status);
        Event::assertDispatchedTimes(SignatureEnvelopeCancelled::class, 1);
        Mail::assertSent(DocumentCancelledMail::class, 1);
    }

    public function test_only_the_creator_can_cancel_and_nothing_is_announced_when_refused(): void
    {
        Event::fake([SignatureEnvelopeCancelled::class]);
        [$envelope] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $stranger = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('docuperfect.esign.cancelDocument', $envelope), ['cancellation_reason' => 'Not mine to cancel'])
            ->assertSessionHasErrors();

        $this->assertSame(SignatureTemplate::STATUS_AWAITING_TENANT, $envelope->fresh()->status);
        Event::assertNotDispatched(SignatureEnvelopeCancelled::class);
    }

    // ═══ rejecting a document ═══

    public function test_rejecting_a_document_announces_it_declined_whether_it_is_archived_or_returned_for_revision(): void
    {
        Event::fake([SignatureEnvelopeDeclined::class]);

        [$archived, $archivedDocument] = $this->envelope(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL);
        $this->actingAs($this->agent)
            ->post(route('docuperfect.documents.reject', $archivedDocument->id), ['rejection_reason' => 'Wrong tenant named', 'action' => 'archive'])
            ->assertRedirect();

        [$revised, $revisedDocument] = $this->envelope(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL);
        $this->actingAs($this->agent)
            ->post(route('docuperfect.documents.reject', $revisedDocument->id), ['rejection_reason' => 'Fix the rent', 'action' => 'revise'])
            ->assertRedirect();

        $this->assertSame(SignatureTemplate::STATUS_REJECTED, $archived->fresh()->status);
        Event::assertDispatched(SignatureEnvelopeDeclined::class, fn ($e) => $e->signatureTemplateId === $archived->id && $e->reason === 'Rejected — Wrong tenant named');
        Event::assertDispatched(SignatureEnvelopeDeclined::class, fn ($e) => $e->signatureTemplateId === $revised->id && $e->reason === 'Returned for revision — Fix the rent');
    }

    // ═══ a listener can never disturb the signing ═══

    public function test_a_listener_that_throws_never_breaks_a_decline_a_cancel_or_a_finalisation(): void
    {
        Mail::fake();
        foreach ([SignatureEnvelopeDeclined::class, SignatureEnvelopeCancelled::class, SignatureEnvelopeFinalized::class, SignatureEnvelopeSent::class, SignatureEnvelopeExpired::class] as $class) {
            Event::listen($class, function () {
                throw new \RuntimeException('a listener fell over');
            });
        }

        [$declined] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        app(SignatureService::class)->declineRequest($this->request($declined), 'No');
        $this->assertSame(SignatureTemplate::STATUS_DECLINED, $declined->fresh()->status);

        [$cancelled] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT);
        $this->request($cancelled);
        app(SignatureService::class)->cancelEnvelope($cancelled, $this->agent, 'Changed my mind');
        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $cancelled->fresh()->status);

        [$completed] = $this->envelope(SignatureTemplate::STATUS_COMPLETED);
        app(SignatureService::class)->recordFinalizationSucceeded($completed);
        $this->assertSame(SignatureTemplate::FINALIZATION_SUCCEEDED, $completed->fresh()->finalization_status);

        [$sending] = $this->envelope(SignatureTemplate::STATUS_SIGNING);
        $waiting = $this->request($sending, SignatureRequest::STATUS_WAITING);
        app(SignatureService::class)->sendSigningRequest($waiting);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $waiting->fresh()->status);

        [$lapsing] = $this->envelope(SignatureTemplate::STATUS_AWAITING_TENANT, ['legal_deadline_at' => now()->subDay()]);
        $this->assertSame(1, app(SignatureService::class)->lapseExpiredCeremonies());
        $this->assertSame(SignatureTemplate::STATUS_LAPSED, $lapsing->fresh()->status);
    }

    // ═══ helpers ═══

    /**
     * @param array<string,mixed> $overrides
     * @return array{0: SignatureTemplate, 1: Document}
     */
    private function envelope(string $status, array $overrides = []): array
    {
        $document = Document::create([
            'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        $envelope = SignatureTemplate::create($overrides + [
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => $status, 'created_by' => $this->agent->id,
        ]);

        return [$envelope, $document];
    }

    /** @param array<string,mixed> $overrides */
    private function request(SignatureTemplate $envelope, string $status = SignatureRequest::STATUS_PENDING, array $overrides = []): SignatureRequest
    {
        return SignatureRequest::create($overrides + [
            'signature_template_id' => $envelope->id, 'party_role' => 'tenant', 'role_index' => 1, 'signing_order' => 2,
            'signer_name' => 'Nomsa Dlamini', 'signer_email' => 'nomsa@example.co.za', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => $status,
        ]);
    }
}
