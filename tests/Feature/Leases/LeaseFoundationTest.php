<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\SignatureEnvelopeCancelled;
use App\Events\Docuperfect\SignatureEnvelopeDeclined;
use App\Events\Docuperfect\SignatureEnvelopeExpired;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Events\Docuperfect\SignatureEnvelopeSent;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document as EsignDocument;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\LeaseAgreementCheck;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use App\Services\Rentals\PreviousTermValuesReader;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * leases.md §15.10 / §15.21 Build L1 — the foundation: schema, models, the data back-fill (M5), the
 * shells with their final signatures, and the one behaviour-preserving extraction. L1 changes no
 * behaviour a user can see, so most of this proves the foundation is there and INERT.
 */
final class LeaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ── Schema (M1–M4) ──────────────────────────────────────────────────────────────

    public function test_the_foundation_schema_is_in_place(): void
    {
        $this->assertTrue(\Schema::hasTable('lease_agreement_terms'));
        foreach (['adults', 'max_other_persons', 'pets', 'escalation_percent', 'no_escalation', 'escalation_month',
            'earliest_termination_date', 'renewal_option_months', 'electricity_arrangement', 'other_conditions',
            'extra', 'source', 'agency_id', 'lease_id', 'deleted_at'] as $column) {
            $this->assertTrue(\Schema::hasColumn('lease_agreement_terms', $column), "lease_agreement_terms.$column");
        }
        foreach (['signing_status', 'signing_flow_id', 'signature_template_id', 'agreement_document_id',
            'agreement_template_id', 'signed_at', 'accepted_at', 'accepted_by_user_id', 'agreement_confirmed_fingerprint',
            'agreement_confirmed_at', 'agreement_confirmed_by_user_id', 'capture_key', 'signing_failure_note',
            'renewal_draft_flow_id'] as $column) {
            $this->assertTrue(\Schema::hasColumn('leases', $column), "leases.$column");
        }
        $this->assertTrue(\Schema::hasColumn('flows', 'lease_id'));
        foreach (['field_map', 'is_default', 'validated_at', 'validation_problems'] as $column) {
            $this->assertTrue(\Schema::hasColumn('rental_lease_templates', $column), "rental_lease_templates.$column");
        }
    }

    public function test_a_new_lease_starts_not_sent_and_a_lease_with_nothing_attached_has_no_terms_or_documents(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property))->fresh();

        $this->assertSame(Lease::SIGNING_NOT_SENT, $lease->signing_status);
        $this->assertNull($lease->agreementTerms);
        $this->assertNull($lease->signedDocument());
        $this->assertNull($lease->signingFlow);
        $this->assertNull($lease->signatureTemplate);
        $this->assertNull($lease->agreementDocument);
        $this->assertNull($lease->agreementTemplate);
        $this->assertFalse($lease->isLockedForSigning());
    }

    public function test_capture_key_is_unique_so_a_double_submit_can_only_ever_make_one_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        Lease::create($this->leaseAttributes($agency, $branch, $property, ['capture_key' => 'abc']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        Lease::create($this->leaseAttributes($agency, $branch, $property, ['capture_key' => 'abc']));
    }

    public function test_leases_without_a_capture_key_do_not_collide(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        Lease::create($this->leaseAttributes($agency, $branch, $property));
        Lease::create($this->leaseAttributes($agency, $branch, $property));

        $this->assertSame(2, Lease::where('property_id', $property->id)->count());
    }

    // ── Terms model ─────────────────────────────────────────────────────────────────

    public function test_terms_are_stored_typed_and_read_back_through_the_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));

        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->fill([
            'adults' => 2, 'max_other_persons' => 1, 'pets' => 'One small dog', 'escalation_percent' => 7.5,
            'escalation_month' => 3, 'earliest_termination_date' => '2027-01-31', 'renewal_option_months' => 12,
            'electricity_arrangement' => 'Prepaid meter', 'other_conditions' => "No smoking.\nNo parties.",
            'extra' => ['other_deduction' => '150.00'],
        ])->save();

        $read = $lease->fresh()->agreementTerms;
        $this->assertSame($agency->id, $read->agency_id);
        $this->assertSame(2, $read->adults);
        $this->assertSame('7.50', $read->escalation_percent);
        $this->assertSame('2027-01-31', $read->earliest_termination_date->toDateString());
        $this->assertSame(['other_deduction' => '150.00'], $read->extra);
        $this->assertFalse($read->no_escalation);
        $this->assertSame(LeaseAgreementTerms::SOURCE_CAPTURED, $read->source);
    }

    public function test_a_soft_deleted_terms_row_is_restored_not_duplicated_when_recreated(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));

        $first = LeaseAgreementTerms::forLease($lease);
        $first->fill(['adults' => 2])->save();
        $first->delete();
        $this->assertNull($lease->fresh()->agreementTerms);

        // lease_id is UNIQUE — a plain create() here would be a duplicate-key error.
        $again = LeaseAgreementTerms::forLease($lease);
        $again->fill(['adults' => 3])->save();

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, LeaseAgreementTerms::withTrashed()->where('lease_id', $lease->id)->count());
        $this->assertSame(3, $lease->fresh()->agreementTerms->adults);
        $this->assertNull($again->fresh()->deleted_at);
    }

    public function test_terms_follow_the_agency_scope(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB, $branchB] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agencyA, $branchA, $propertyA));
        LeaseAgreementTerms::forLease($lease)->fill(['adults' => 2])->save();

        $this->actingAs($this->makeUser($agencyB, $branchB));
        $this->assertSame(0, LeaseAgreementTerms::where('lease_id', $lease->id)->count());

        $this->actingAs($this->makeUser($agencyA, $branchA));
        $this->assertSame(1, LeaseAgreementTerms::where('lease_id', $lease->id)->count());
    }

    public function test_the_previous_term_reader_returns_the_row_or_nothing_and_never_guesses(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));
        $reader = app(PreviousTermValuesReader::class);

        $this->assertNull($reader->for($lease));

        LeaseAgreementTerms::forLease($lease)->fill(['pets' => 'None'])->save();
        $this->assertSame('None', $reader->for($lease->fresh())->pets);
    }

    // ── Signing helpers on Lease ────────────────────────────────────────────────────

    public function test_a_lease_is_locked_for_signing_only_while_the_agreement_is_out_or_awaiting_review(): void
    {
        $lease = new Lease;
        foreach ([Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW] as $locked) {
            $lease->signing_status = $locked;
            $this->assertTrue($lease->isLockedForSigning(), $locked);
        }
        foreach (array_diff(Lease::SIGNING_STATUSES, [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW]) as $open) {
            $lease->signing_status = $open;
            $this->assertFalse($lease->isLockedForSigning(), $open);
        }
    }

    public function test_the_signed_copy_is_the_filed_esign_pdf_or_else_the_paper_copy(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));
        $paper = $this->makeFiledDocument('lease', $lease->id);

        $this->assertSame($paper->id, $lease->signedDocument()?->id, 'paper copy is the fallback');

        $envelope = $this->makeEnvelope($agency, $this->makeEsignDocument($agency, $branch), SignatureTemplate::STATUS_COMPLETED);
        $lease->update(['signature_template_id' => $envelope->id]);
        $this->assertSame($paper->id, $lease->fresh()->signedDocument()?->id, 'no filed e-sign copy yet → still the paper copy');

        $filed = $this->makeFiledDocument('esign', $envelope->id);
        $this->assertSame($filed->id, $lease->fresh()->signedDocument()?->id, 'the filed e-sign copy wins');
    }

    public function test_every_lease_event_type_fits_the_event_type_column(): void
    {
        $types = array_filter(
            (new \ReflectionClass(LeaseEvent::class))->getConstants(),
            fn ($value, $name) => str_starts_with($name, 'TYPE_'),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertCount(count(array_unique($types)), $types, 'event types must be unique');
        foreach ($types as $name => $value) {
            $this->assertLessThanOrEqual(40, strlen($value), $name);
        }
        $this->assertContains('agreement_differences_confirmed', $types);
        $this->assertContains('lease_signed_on_paper', $types);
    }

    // ── Rental lease template (M4) ──────────────────────────────────────────────────

    public function test_a_rental_lease_template_carries_its_field_map_and_last_check(): void
    {
        [$agency, $branch] = $this->makeAgencyBranchProperty();
        $template = Template::create(['name' => 'Agency lease', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);

        $row = RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
            'field_map' => ['rent' => ['field' => 'monthly_rental', 'required' => true]],
            'is_default' => true, 'validated_at' => now(), 'validation_problems' => ['No signing place for the tenant'],
        ])->fresh();

        $this->assertSame('monthly_rental', $row->field_map['rent']['field']);
        $this->assertTrue($row->is_default);
        $this->assertNotNull($row->validated_at);
        $this->assertSame(['No signing place for the tenant'], $row->validation_problems);

        $plain = RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Plain', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL,
        ])->fresh();
        $this->assertNull($plain->field_map);
        $this->assertFalse($plain->is_default);
    }

    // ── The shells: final signatures, and safe until they are built ────────────────

    public function test_the_template_guard_refuses_a_shared_template_and_links_nothing_until_a_row_is_ready(): void
    {
        [$agency, $branch] = $this->makeAgencyBranchProperty();
        $guard = app(LeaseAgreementTemplateGuard::class);
        // The shape a built-in (ownerless, shared) lease template has: no owning agency, shared with everyone.
        $shared = Template::create(['name' => 'Built-in lease', 'render_type' => 'web', 'is_esign' => true, 'agency_id' => null, 'is_global' => true]);
        $owned = Template::create(['name' => 'Own lease', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);

        foreach ([$shared, $owned] as $template) {
            $this->assertNotSame([], $guard->problemsFor($template, $agency->id));
            try {
                $guard->assertUsable($template, $agency->id);
                $this->fail('the shell guard must refuse every template');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('template', $e->errors());
            }
        }

        $row = RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $owned->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true, 'is_default' => true,
        ]);
        $this->assertNull($guard->linkedFor($agency->id), 'a row with no field map is not a linked agreement');
        $this->assertFalse($row->isUsableBy($agency->id));
    }

    public function test_the_check_shell_reports_no_differences(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));

        $this->assertSame(
            ['has_differences' => false, 'differences' => [], 'cannot_verify' => [], 'fingerprint' => null],
            app(LeaseAgreementCheck::class)->verdict($lease),
        );
    }

    public function test_the_harvest_is_real_and_says_nothing_when_it_has_no_map_to_read_with(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));

        // LeaseCaptureService::capture() is real since Build L2 (LeaseCaptureTest), the launcher's missing()/launch()
        // since Build L3a (LeaseSigningLauncherTest) and the harvest since Build L3b (LeaseSigningReconcileTest). With no
        // agreement map known for the document it returns nothing — it never guesses a field name.
        $this->assertNull(app(\App\Services\Rentals\LeaseAgreementHarvest::class)->fromDocument($lease, $this->makeEsignDocument($agency, $branch)));
        $this->assertSame(0, \App\Models\LeaseAgreementTerms::count());
    }

    // ── Events and the listener (live since Build L3b) ──────────────────────────────

    public function test_the_engine_events_have_one_listener_each_and_an_envelope_still_signing_leaves_the_lease_alone(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $envelope = $this->makeEnvelope($agency, $this->makeEsignDocument($agency, $branch), SignatureTemplate::STATUS_SIGNING);
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'signature_template_id' => $envelope->id, 'signing_status' => Lease::SIGNING_OUT_FOR_SIGNING,
        ]));

        $events = [
            new SignatureEnvelopeSent($envelope->id, null, $agency->id),
            new SignatureEnvelopeFinalized($envelope->id, null, $agency->id),
            new SignatureEnvelopeDeclined($envelope->id, null, $agency->id, 'Declined by the tenant'),
            new SignatureEnvelopeCancelled($envelope->id, null, $agency->id, 'Cancelled by the agent'),
            new SignatureEnvelopeExpired($envelope->id, null, $agency->id, 'Links lapsed'),
        ];
        foreach ($events as $event) {
            $this->assertTrue(Event::hasListeners($event::class), $event::class);
            $this->assertSame($agency->id, $event->agencyId());
            $this->assertSame([SignatureTemplate::class, $envelope->id], $event->subject());
            event($event);
        }

        $fresh = $lease->fresh();
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $fresh->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->signed_at);
    }

    public function test_the_rental_events_carry_the_lease_and_its_agency(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->leaseAttributes($agency, $branch, $property));

        $signed = new \App\Events\Rentals\LeaseAgreementSigned($lease, 5, 9);
        $failed = new \App\Events\Rentals\LeaseAgreementFailed($lease, 'declined', 'Declined by the tenant');

        $this->assertSame($agency->id, $signed->agencyId());
        $this->assertSame(9, $signed->actorUserId());
        $this->assertSame([Lease::class, $lease->id], $failed->subject());
        $this->assertSame('declined', $failed->outcome);
    }

    // ── The extraction: behaviour-identical (plus flows.lease_id, M3) ───────────────

    public function test_copy_forward_builds_the_same_flow_as_before_and_links_it_to_the_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $user = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement test', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $user->id, 'agency_id' => $agency->id]);
        $current = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
        ]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'Renewing');
        LeaseTenant::create(['lease_id' => $current->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $landlord = $this->makeContact($agency, $branch, 'Owner', 'Landlord');
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');

        $result = app(RenewalDraftService::class)->copyForward($current->fresh(), [
            'start_date' => now()->addDay()->toDateString(), 'rental_amount' => 9900,
        ], $user);

        $flow = $result['flow']->fresh();
        $newTerm = $result['lease'];

        // As before.
        $this->assertSame('esign', $flow->type);
        $this->assertSame(2, (int) $flow->current_step, 'the renewal copy-forward path still opens on step 2');
        $this->assertSame('active', $flow->status);
        $this->assertSame($template->id, $flow->template_id);
        $this->assertSame($user->id, $flow->user_id);
        $this->assertSame($property->id, $flow->property_id);
        $this->assertSame($tenant->id, $flow->contact_id);
        // Since Build L3a the signers are always written in the one fixed order (R4): agent, tenant(s), landlord(s).
        $this->assertSame(['agent', 'tenant', 'landlord'], array_column($flow->step_data['recipients']['recipients'], 'role'));
        $this->assertSame('9900.00', $flow->step_data['details']['monthly_rental']);
        $this->assertSame($flow->id, $newTerm->renewal_draft_flow_id);
        $this->assertSame('esign_document', $newTerm->source);

        // New in L1 (nothing reads these yet).
        $this->assertSame($newTerm->id, (int) $flow->lease_id);
        $this->assertSame($flow->id, $newTerm->signing_flow_id);
        $this->assertSame(Lease::SIGNING_PREPARED, $newTerm->signing_status);
        $this->assertSame($template->id, $newTerm->agreement_template_id);
        $this->assertSame(Lease::STATUS_DRAFT, $newTerm->status, 'lease status is untouched');
    }

    // ── M5: the back-fill ───────────────────────────────────────────────────────────

    public function test_the_back_fill_gives_every_existing_lease_the_signing_state_it_truthfully_has(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $user = $this->makeUser($agency, $branch);
        $completedAt = '2026-09-01 10:00:00';

        // A — e-signed through CoreX, envelope findable.
        $docA = $this->makeEsignDocument($agency, $branch);
        $envA = $this->makeEnvelope($agency, $docA, SignatureTemplate::STATUS_COMPLETED, $completedAt);
        $signed = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $docA->id,
        ]));

        // B — e-signed, but its envelope can no longer be found.
        $docB = $this->makeEsignDocument($agency, $branch);
        $signedNoEnvelope = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_EXPIRED, 'source' => 'esign_document', 'source_document_id' => $docB->id,
        ]));

        // C — a paper renewal: the signed PDF was filed against the lease.
        $paper = Lease::create($this->leaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $this->makeFiledDocument('lease', $paper->id);

        // D — a draft with a flow where signing was never prepared.
        $flowD = $this->makeFlow($agency, $user, $property, []);
        $prepared = Lease::create($this->leaseAttributes($agency, $branch, $property, ['renewal_draft_flow_id' => $flowD->id]));

        // E — drafts whose flow reached an envelope: out for signing / declined / needing the agent.
        $outForSigning = $this->draftWithEnvelope($agency, $branch, $property, $user, SignatureTemplate::STATUS_AWAITING_TENANT);
        $declined = $this->draftWithEnvelope($agency, $branch, $property, $user, SignatureTemplate::STATUS_DECLINED);
        $awaiting = $this->draftWithEnvelope($agency, $branch, $property, $user, SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL);

        // F — a CANCELLED draft that still has a flow is not in flight.
        $cancelled = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_CANCELLED, 'renewal_draft_flow_id' => $this->makeFlow($agency, $user, $property, [])->id,
        ]));

        // G — an ordinary manual lease.
        $manual = Lease::create($this->leaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));

        // H — a lease that has already moved on must never be overwritten.
        $movedOn = Lease::create($this->leaseAttributes($agency, $branch, $property, [
            'renewal_draft_flow_id' => $this->makeFlow($agency, $user, $property, [])->id,
            'signing_status' => Lease::SIGNING_DECLINED,
        ]));

        $this->runBackFill();
        $after = fn (Lease $l) => Lease::withoutGlobalScopes()->find($l->id);

        $this->assertSame(Lease::SIGNING_SIGNED, $after($signed)->signing_status);
        $this->assertSame($envA->id, $after($signed)->signature_template_id);
        $this->assertSame($docA->id, $after($signed)->agreement_document_id);
        $this->assertSame($completedAt, $after($signed)->signed_at->format('Y-m-d H:i:s'));
        $this->assertSame($completedAt, $after($signed)->accepted_at->format('Y-m-d H:i:s'));
        $this->assertNull($after($signed)->accepted_by_user_id, 'who approved is not knowable for an old lease');
        $this->assertSame(Lease::STATUS_ACTIVE, $after($signed)->status, 'status is never touched');

        $this->assertSame(Lease::SIGNING_SIGNED, $after($signedNoEnvelope)->signing_status);
        $this->assertSame($docB->id, $after($signedNoEnvelope)->agreement_document_id);
        $this->assertNull($after($signedNoEnvelope)->signature_template_id);
        $this->assertNull($after($signedNoEnvelope)->signed_at);

        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $after($paper)->signing_status);

        $this->assertSame($flowD->id, $after($prepared)->signing_flow_id, 'mirrors renewal_draft_flow_id');
        $this->assertSame(Lease::SIGNING_PREPARED, $after($prepared)->signing_status);
        $this->assertNull($after($prepared)->signature_template_id);

        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $after($outForSigning)->signing_status);
        $this->assertNotNull($after($outForSigning)->signature_template_id);
        $this->assertNotNull($after($outForSigning)->agreement_document_id);
        $this->assertSame(Lease::SIGNING_DECLINED, $after($declined)->signing_status);
        $this->assertSame(Lease::SIGNING_AWAITING_AGENT_REVIEW, $after($awaiting)->signing_status);

        $this->assertSame(Lease::SIGNING_NOT_SENT, $after($cancelled)->signing_status);
        $this->assertSame(Lease::SIGNING_NOT_SENT, $after($manual)->signing_status);
        $this->assertSame(Lease::SIGNING_DECLINED, $after($movedOn)->signing_status);
    }

    public function test_the_back_fill_is_idempotent(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $user = $this->makeUser($agency, $branch);
        $docA = $this->makeEsignDocument($agency, $branch);
        $this->makeEnvelope($agency, $docA, SignatureTemplate::STATUS_COMPLETED, '2026-09-01 10:00:00');
        Lease::create($this->leaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $docA->id]));
        $this->draftWithEnvelope($agency, $branch, $property, $user, SignatureTemplate::STATUS_SIGNING);
        Lease::create($this->leaseAttributes($agency, $branch, $property, ['renewal_draft_flow_id' => $this->makeFlow($agency, $user, $property, [])->id]));

        $this->runBackFill();
        $once = DB::table('leases')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->runBackFill();
        $twice = DB::table('leases')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame($once, $twice);
    }

    public function test_the_back_fill_copes_with_a_flow_that_has_no_readable_step_data_or_was_deleted(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $user = $this->makeUser($agency, $branch);

        $garbled = $this->makeFlow($agency, $user, $property, []);
        // step_data is a JSON column, so "unreadable" can only mean valid JSON that is not the expected object.
        DB::table('flows')->where('id', $garbled->id)->update(['step_data' => json_encode('plain text')]);
        $deleted = $this->makeFlow($agency, $user, $property, []);
        $deleted->delete();

        $a = Lease::create($this->leaseAttributes($agency, $branch, $property, ['renewal_draft_flow_id' => $garbled->id]));
        $b = Lease::create($this->leaseAttributes($agency, $branch, $property, ['renewal_draft_flow_id' => $deleted->id]));

        $this->runBackFill();

        $this->assertSame(Lease::SIGNING_PREPARED, Lease::withoutGlobalScopes()->find($a->id)->signing_status);
        $this->assertSame(Lease::SIGNING_NOT_SENT, Lease::withoutGlobalScopes()->find($b->id)->signing_status, 'a deleted flow is not in flight');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────

    private function runBackFill(): void
    {
        $migration = require base_path('database/migrations/2026_10_12_100005_backfill_lease_signing_state.php');
        $migration->up();
    }

    private function draftWithEnvelope(Agency $agency, Branch $branch, Property $property, User $user, string $envelopeStatus): Lease
    {
        $document = $this->makeEsignDocument($agency, $branch);
        $envelope = $this->makeEnvelope($agency, $document, $envelopeStatus);
        $flow = $this->makeFlow($agency, $user, $property, ['signature_template_id' => $envelope->id, 'document_id' => $document->id]);

        return Lease::create($this->leaseAttributes($agency, $branch, $property, ['renewal_draft_flow_id' => $flow->id]));
    }

    private function makeFlow(Agency $agency, User $user, Property $property, array $stepData): Flow
    {
        return Flow::create([
            'type' => 'esign', 'user_id' => $user->id, 'property_id' => $property->id,
            'current_step' => 2, 'step_data' => $stepData, 'status' => 'active',
        ]);
    }

    private function makeEsignDocument(Agency $agency, Branch $branch): EsignDocument
    {
        $template = Template::create(['name' => 'Lease ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);

        return EsignDocument::create([
            'name' => 'Lease document', 'template_id' => $template->id, 'owner_id' => $this->makeUser($agency, $branch)->id, 'agency_id' => $agency->id,
        ]);
    }

    private function makeEnvelope(Agency $agency, EsignDocument $document, string $status, ?string $completedAt = null): SignatureTemplate
    {
        $envelope = SignatureTemplate::create(['document_id' => $document->id, 'status' => $status, 'agency_id' => $agency->id]);
        if ($completedAt) {
            DB::table('signature_templates')->where('id', $envelope->id)->update(['completed_at' => $completedAt]);
        }

        return $envelope->fresh();
    }

    private function makeFiledDocument(string $sourceType, int $sourceId): Document
    {
        return Document::create([
            'original_name' => 'signed.pdf', 'storage_path' => 'x/signed-' . uniqid() . '.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10, 'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function leaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->toDateString(), 'source' => 'manual',
        ], $overrides);
    }

    private function makeContact(Agency $agency, Branch $branch, string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    private function makeUser(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }
}
