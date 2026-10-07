<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use App\Services\Rentals\LeaseAgreementCheck;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseSigningLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.9 (Build L3c) — what the agent sees and what is written when a lease agreement was changed in
 * e-sign: the lease screen in confirm mode ("Lease says … · Agreement says …"), the one button, the e-sign engine's own
 * approve route carrying the confirmation, and the net under every approval path that never touches that route.
 *
 * The e-sign ENGINE's own approve step (SignatureService::approveAndAdvance — PDF building, the next party, filing) is
 * replaced by a stand-in, because it is the engine's and not this build's; everything on the lease side is real. The
 * middleware, the controller, the confirm service, the lease, its agreement details and its tenancy log are all real
 * and asserted.
 *
 * Mirrors reality (BUILD_STANDARD §5): an agreement edited AGAIN after the agent looked, a different person printed in
 * it, a value that cannot be read, a double press, a completion that skipped the route (wet-ink, unattended), a
 * property that already has an active lease, a record that has already been through a confirm, another agency's user.
 */
final class LeaseAgreementConfirmTest extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgreementFixture();
        Mail::fake();
    }

    /** The engine's own approve step, stood in for: `$times` calls, answering `$results` in turn. */
    private function engineApproves(int $times = 1, array ...$results): void
    {
        $results = $results ?: [['action' => 'completed']];
        $this->partialMock(SignatureService::class, function ($m) use ($times, $results) {
            $m->shouldReceive('outstandingChangeInitials')->andReturn(['count' => 0, 'rows' => []]);
            $m->shouldReceive('approveAndAdvance')->times($times)->andReturn(...$results);
        });
    }

    private function approveUrl(Document $document): string
    {
        return route('docuperfect.signatures.approveAndAdvance', $document);
    }

    private function fingerprintOf(Lease $lease): string
    {
        return (string) app(LeaseAgreementCheck::class)->verdict($lease->fresh())['fingerprint'];
    }

    private function confirmEvents(Lease $lease)
    {
        return LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_DIFFERENCES_CONFIRMED)->orderBy('id')->get();
    }

    // ═══ the screen ═══

    public function test_the_screen_shows_the_leases_value_beside_the_agreements_and_changes_nothing_by_being_opened(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00', 'pets_f' => 'Two cats']);
        $before = [$lease->fresh()->toArray(), $lease->agreementTerms()->first()->toArray(), LeaseEvent::count()];

        $response = $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease));

        $response->assertOk()
            ->assertSee('The agreement was changed in e-sign. Check each highlighted value, then confirm.')
            ->assertSee('Lease says')->assertSee('R8 500')->assertSee('R6 940')
            ->assertSee('Two cats')
            ->assertSee('Confirm lease details and approve')
            ->assertSee(route('docuperfect.signatures.approveAndAdvance', $document), false)
            ->assertSee('Back to the agreement')
            ->assertSee('lease_confirm[fingerprint]', false);
        $this->assertStringContainsString('data-state="differs"', $response->getContent());
        // Fields that agree are shown plainly too.
        $this->assertStringContainsString('data-qa="confirm-row-tenant_name" data-state="agree"', $response->getContent());
        $this->assertSame($before, [$lease->fresh()->toArray(), $lease->agreementTerms()->first()->toArray(), LeaseEvent::count()], 'leaving or opening the screen writes nothing');
    }

    public function test_an_agreement_that_agrees_with_its_lease_says_so_and_offers_no_button(): void
    {
        [$lease] = $this->agreementOut();

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertOk()->assertSee('there is nothing to confirm')->assertDontSee('Confirm lease details and approve');
    }

    public function test_while_the_agreement_is_still_out_the_agent_can_look_but_not_confirm(): void
    {
        [$lease, , $document] = $this->agreementOut(envelopeStatus: SignatureTemplate::STATUS_AWAITING_TENANT);
        $this->print($document, ['rent_f' => '6940.00']);

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertOk()->assertSee('R6 940')->assertSee('You can confirm these details once everyone has signed')
            ->assertDontSee('Confirm lease details and approve');
    }

    public function test_a_different_person_is_flagged_with_the_way_out_and_the_button_is_disabled(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['tenant_f' => 'Sipho Dlamini']);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease));

        $response->assertOk()
            ->assertSee('The agreement names a different person. A change of tenant is a new lease.')
            ->assertSee('Correct the contact — Thandi Nkosi')->assertSee('Re-check')->assertSee('Back to the agreement');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-qa="confirm-submit"/s', $response->getContent(), 'the confirm button is disabled while a different person is open');
    }

    public function test_joint_tenants_printed_in_boxes_of_their_own_but_mapped_as_one_say_what_to_ask_an_administrator(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $second = $this->contact('Sipho', 'Dlamini', '9001015009087');
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $second->id, 'is_primary' => false]);
        // The agreement prints the first tenant in this field and the second in another one the map does not carry.
        $this->print($document, ['tenant_f' => 'Thandi Nkosi']);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease));

        $response->assertOk()->assertSee('There is more than one tenant on this lease')->assertSee('map them one by one');
        // A single-tenant lease is never told this.
        [$single, , $doc2] = $this->agreementOut(['rental_amount' => 9000]);
        $single->tenants()->where('contact_id', $second->id)->delete();
        $this->print($doc2, ['tenant_f' => 'Sipho Dlamini']);
        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $single))->assertOk()->assertDontSee('map them one by one');
    }

    public function test_a_value_that_cannot_be_read_is_a_box_to_type_into(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['end_f' => '']);

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertOk()->assertSee('Type what the agreement says')->assertSee('lease_confirm[entered][end_date]', false);
    }

    public function test_the_wording_changes_are_listed_read_only(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $data = (array) $document->fresh()->web_template_data;
        $data['pending_body_changes'] = [['old' => 'no pets', 'new' => 'one cat allowed', 'actor_name' => 'Thandi Nkosi', 'at' => '2026-10-07T09:00:00+02:00']];
        $document->update(['web_template_data' => $data]);

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertOk()->assertSee('Changes to the wording (read only)')->assertSee('one cat allowed')->assertSee('Thandi Nkosi');
    }

    public function test_a_lease_with_no_agreement_goes_back_to_the_lease_with_a_message(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('error');
    }

    // ═══ the e-sign approve route ═══

    public function test_an_unchanged_agreement_is_approved_with_no_extra_screen(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->engineApproves();

        $response = $this->actingAs($this->agent)->post($this->approveUrl($document));

        $response->assertRedirect(route('docuperfect.esign.myDocuments'));
        $this->assertSame(0, $this->confirmEvents($lease)->count());
    }

    public function test_a_changed_agreement_sends_the_approve_click_to_the_lease_screen_and_the_engine_is_not_asked(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $this->engineApproves(0);

        $this->actingAs($this->agent)->post($this->approveUrl($document))
            ->assertRedirect(route('corex.leases.agreement.confirm', $lease));

        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount, 'nothing is written by being redirected');
        $this->assertSame(0, $this->confirmEvents($lease)->count());
    }

    public function test_the_confirm_button_changes_the_lease_logs_each_change_old_to_new_then_lets_the_engine_approve(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00', 'start_f' => '2026-12-01', 'pets_f' => 'Two cats']);
        $this->engineApproves();

        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertRedirect(route('docuperfect.esign.myDocuments'));

        $lease = $lease->fresh();
        $this->assertSame(6940.0, (float) $lease->rental_amount);
        $this->assertSame('2026-12-01', $lease->start_date->toDateString());
        $this->assertSame('2027-10-31', $lease->end_date->toDateString(), 'what was not changed is not touched');
        $terms = $lease->agreementTerms()->first();
        $this->assertSame('Two cats', $terms->pets);
        $this->assertSame('confirmed', $terms->source);
        $this->assertSame($this->agent->id, $lease->agreement_confirmed_by_user_id);
        $this->assertNotNull($lease->agreement_confirmed_at);
        $this->assertSame($this->fingerprintOf($lease), $lease->agreement_confirmed_fingerprint, 'the fingerprint confirmed is the document as it stands');

        $events = $this->confirmEvents($lease);
        $this->assertCount(3, $events, 'one per changed field');
        $rent = $events->firstWhere('metadata.key', 'rent');
        $this->assertSame('R8 500', $rent->metadata['old']);
        $this->assertSame('R6 940', $rent->metadata['new']);
        $this->assertSame($this->agent->id, $rent->actor_user_id);
        $this->assertStringContainsString('R8 500 → R6 940', $rent->description);
        $this->assertStringContainsString($this->agent->name, $rent->description);

        // And now the lease and its agreement agree — a record that has been through a confirm is clean (§5a).
        $this->assertFalse(app(LeaseAgreementCheck::class)->verdict($lease)['needs_confirmation']);
    }

    public function test_a_second_press_of_approve_after_a_confirm_goes_straight_through(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        // The engine refuses the first time (e.g. an outstanding change initial); the confirmation stands.
        $this->engineApproves(2, ['action' => 'blocked', 'message' => 'Someone still has to initial a change.'], ['action' => 'completed']);

        $first = $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]]);
        $first->assertSessionHas('error', 'Someone still has to initial a change.');
        $this->assertSame(6940.0, (float) $lease->fresh()->rental_amount);

        $this->actingAs($this->agent)->post($this->approveUrl($document))->assertRedirect(route('docuperfect.esign.myDocuments'));

        $this->assertSame(1, $this->confirmEvents($lease)->count(), 'not confirmed twice');
    }

    public function test_an_agreement_edited_again_after_the_agent_looked_reopens_the_screen_and_writes_nothing(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $shown = $this->fingerprintOf($lease);
        $this->print($document, ['rent_f' => '7100.00']); // someone edits it while the agent is reading
        $this->engineApproves(0);

        $response = $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $shown]]);

        $response->assertRedirect(route('corex.leases.agreement.confirm', $lease))->assertSessionHas('error');
        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount);
        $this->assertSame(0, $this->confirmEvents($lease)->count());
        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))->assertSee('R7 100');
    }

    public function test_a_different_person_in_the_agreement_cannot_be_confirmed_even_by_a_forged_post(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['tenant_f' => 'Sipho Dlamini', 'rent_f' => '6940.00']);
        $this->engineApproves(0);

        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertRedirect(route('corex.leases.agreement.confirm', $lease))
            ->assertSessionHas('error');

        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount, 'a block writes nothing — not even the rent beside it');
        $this->assertSame(0, $this->confirmEvents($lease)->count());
    }

    public function test_a_value_the_agent_types_is_used_and_logged_as_typed(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => 'seven thousand', 'end_f' => '']);
        $this->engineApproves();
        $fingerprint = $this->fingerprintOf($lease);

        // Unanswered: refused, naming what is needed.
        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $fingerprint]])
            ->assertSessionHas('error');
        $this->assertSame(0, $this->confirmEvents($lease)->count());

        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => [
            'fingerprint' => $fingerprint, 'entered' => ['rent' => '7000', 'end_date' => '2027-10-31'],
        ]])->assertRedirect(route('docuperfect.esign.myDocuments'));

        $lease = $lease->fresh();
        $this->assertSame(7000.0, (float) $lease->rental_amount);
        $events = $this->confirmEvents($lease);
        $this->assertCount(2, $events);
        $this->assertTrue($events->firstWhere('metadata.key', 'rent')->metadata['entered_by_agent']);
        $this->assertStringContainsString('typed from the document', $events->firstWhere('metadata.key', 'rent')->description);
        $this->assertStringContainsString('entered by ' . $this->agent->name . ' while confirming', $events->firstWhere('metadata.key', 'end_date')->description);
    }

    public function test_dates_that_would_not_make_a_valid_lease_are_refused(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['end_f' => '2026-10-01']);
        $this->engineApproves(0);

        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertSessionHas('error');

        $this->assertSame('2027-10-31', $lease->fresh()->end_date->toDateString());
    }

    public function test_a_month_to_month_lease_takes_the_end_date_the_agreement_prints_and_stops_being_month_to_month(): void
    {
        [$lease, , $document] = $this->agreementOut(['is_month_to_month' => true, 'end_date' => null]);
        $this->engineApproves();

        $this->actingAs($this->agent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertRedirect(route('docuperfect.esign.myDocuments'));

        $lease = $lease->fresh();
        $this->assertSame('2027-10-31', $lease->end_date->toDateString());
        $this->assertFalse((bool) $lease->is_month_to_month);
    }

    public function test_an_approval_part_way_through_signing_is_left_alone(): void
    {
        // The route is also used to approve and pass on to the next party; only the FINAL approval is checked.
        [$lease, , $document] = $this->agreementOut(envelopeStatus: SignatureTemplate::STATUS_AWAITING_SUPERVISOR);
        $this->print($document, ['rent_f' => '6940.00']);
        $this->engineApproves();

        $this->actingAs($this->agent)->post($this->approveUrl($document))->assertRedirect(route('docuperfect.esign.myDocuments'));
    }

    public function test_a_document_that_is_not_a_lease_agreement_is_approved_exactly_as_before(): void
    {
        $document = Document::create([
            'name' => 'Mandate', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => 'h',
            'status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, 'created_by' => $this->agent->id,
        ]);
        $this->engineApproves();

        $this->actingAs($this->agent)->post($this->approveUrl($document))->assertRedirect(route('docuperfect.esign.myDocuments'));
    }

    public function test_a_user_who_may_not_confirm_cannot_use_the_approve_route_to_write_to_the_lease(): void
    {
        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = Branch::create(['agency_id' => $rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $this->engineApproves(0);

        $this->actingAs($rivalAgent)->post($this->approveUrl($document), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]]);

        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount);
        $this->assertSame(0, $this->confirmEvents($lease)->count());
        $this->assertNull($lease->fresh()->agreement_confirmed_at);
    }

    // ═══ scope ═══

    public function test_another_agencys_user_gets_a_404_on_the_screen_and_the_post(): void
    {
        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = Branch::create(['agency_id' => $rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        [$lease] = $this->agreementOut();

        $this->actingAs($rivalAgent)->get(route('corex.leases.agreement.confirm', $lease))->assertNotFound();
        $this->actingAs($rivalAgent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => str_repeat('a', 64)]])->assertNotFound();
    }

    public function test_someone_who_neither_sent_the_agreement_nor_may_create_leases_is_refused(): void
    {
        [$lease] = $this->agreementOut();
        $bystander = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);

        $response = $this->actingAs($bystander)->get(route('corex.leases.agreement.confirm', $lease));

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    // ═══ the net: an approval that never touched the route ═══

    /** What the engine does at completion: the envelope is completed and its signed copy filed, then it announces it. */
    private function complete(SignatureTemplate $envelope, Document $document): void
    {
        $envelope->update([
            'status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(),
            'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED,
        ]);
        event(new SignatureEnvelopeFinalized($envelope->id, $document->id, $this->agency->id));
    }

    public function test_a_lease_signed_with_an_unconfirmed_difference_is_signed_but_stays_a_draft_and_nothing_is_overwritten(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00', 'pets_f' => 'Two cats']);

        $this->complete($envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'never active while it disagrees with its own agreement');
        $this->assertSame(8500.0, (float) $lease->rental_amount, 'the document never silently overwrites the lease');
        $this->assertSame('One cat', $lease->agreementTerms()->first()->pets, 'the harvest does not run over a difference');
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_NEEDS_CONFIRMATION)->exists());
        $this->assertFalse(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING)->exists());

        $step = app(LeaseHubService::class)->nextStep($lease, $this->agent);
        $this->assertSame('Signed — confirm the lease details', $step['label']);
        $this->assertSame('corex.leases.agreement.confirm', $step['route_name']);
        $this->assertSame(route('corex.leases.agreement.confirm', $lease), app(LeaseSigningLauncher::class)->cardFor($lease, $this->agent)['confirm_url']);
    }

    public function test_confirm_and_activate_writes_the_confirmation_then_activates_with_the_confirmed_values(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00', 'start_f' => '2026-12-01']);
        $this->complete($envelope, $document);

        $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $lease))
            ->assertOk()->assertSee('Confirm and activate')->assertSee(route('corex.leases.agreement.confirm.store', $lease), false);

        $this->actingAs($this->agent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success');

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status);
        $this->assertSame(6940.0, (float) $lease->rental_amount);
        $this->assertSame('2026-12-01', $lease->start_date->toDateString());
        $this->assertSame($this->agent->id, $lease->accepted_by_user_id, 'the agent\'s confirmation is the final approval (R5)');
        $this->assertSame(2, $this->confirmEvents($lease)->count());
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING)->exists());
    }

    public function test_confirm_and_activate_with_another_lease_still_active_stays_a_signed_draft_with_the_reason(): void
    {
        $other = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 7000, 'start_date' => '2025-01-01', 'end_date' => '2027-12-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $this->complete($envelope, $document);

        $this->actingAs($this->agent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => $this->fingerprintOf($lease)]])
            ->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('warning');

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame(6940.0, (float) $lease->rental_amount, 'the details are confirmed; only the going-live waits');
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_SIGNED_NOT_ACTIVATED)->exists());
        $this->assertSame(Lease::STATUS_ACTIVE, $other->fresh()->status);
    }

    public function test_confirm_and_activate_is_only_for_a_signed_draft_and_a_double_press_changes_nothing(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $fingerprint = $this->fingerprintOf($lease);

        // Still waiting for approval: not this route's job.
        $this->actingAs($this->agent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => $fingerprint]])
            ->assertSessionHas('error');
        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount);

        $this->complete($envelope, $document);
        $this->actingAs($this->agent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => $fingerprint]]);
        $this->actingAs($this->agent)->post(route('corex.leases.agreement.confirm.store', $lease), ['lease_confirm' => ['fingerprint' => $fingerprint]])
            ->assertSessionHas('error');

        $this->assertSame(1, $this->confirmEvents($lease)->count());
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING)->count());
    }

    public function test_a_completion_that_agrees_with_its_lease_activates_and_harvests_as_before(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();

        $this->complete($envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status);
        $this->assertSame('esign_harvest', $lease->agreementTerms()->first()->source);
        $this->assertFalse(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_NEEDS_CONFIRMATION)->exists());
    }

    public function test_a_completion_where_the_check_itself_fails_does_not_activate_the_lease(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->mock(LeaseAgreementCheck::class, fn ($m) => $m->shouldReceive('verdict')->andThrow(new \RuntimeException('cannot read the document')));

        $this->complete($envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'an agreement nobody could check does not go live by default');
    }

    public function test_a_failing_check_on_the_approve_route_never_blocks_a_valid_approval(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->mock(LeaseAgreementCheck::class, fn ($m) => $m->shouldReceive('verdict')->andThrow(new \RuntimeException('boom')));
        $this->engineApproves();

        $this->actingAs($this->agent)->post($this->approveUrl($document))->assertRedirect(route('docuperfect.esign.myDocuments'));
    }

    // ═══ the safety net on read: "the agreement was changed" ═══

    public function test_opening_the_lease_notes_once_that_the_agreement_was_changed_and_again_only_after_a_further_edit(): void
    {
        [$lease, , $document] = $this->agreementOut(envelopeStatus: SignatureTemplate::STATUS_AWAITING_TENANT);
        $edited = fn () => LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_EDITED)->get();

        $lease->fresh()->reconcileSigning();
        $this->assertCount(0, $edited(), 'nothing changed, nothing noted');

        $this->print($document, ['rent_f' => '6940.00']);
        $lease->fresh()->reconcileSigning();
        $lease->fresh()->reconcileSigning();
        $this->assertCount(1, $edited(), 'once, however many times the page is opened');
        $this->assertStringContainsString('The agreement was changed in e-sign: Monthly rent R8 500 → R6 940', $edited()->first()->description);

        $this->print($document, ['rent_f' => '7100.00']);
        $lease->fresh()->reconcileSigning();
        $this->assertCount(2, $edited());
        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount, 'information only — the lease is untouched');
    }

    public function test_the_hub_says_the_agreement_changed_and_offers_review_changes(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);

        $step = app(LeaseHubService::class)->nextStep($lease->fresh(), $this->agent);
        $this->assertSame('Agreement changed — review before approving', $step['label']);
        $this->assertSame('corex.leases.agreement.confirm', $step['route_name']);

        $card = app(LeaseSigningLauncher::class)->cardFor($lease->fresh(), $this->agent);
        $this->assertSame(route('corex.leases.agreement.confirm', $lease), $card['review_url']);
        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->assertSee('Review changes');

        $this->print($document, ['rent_f' => '8500.00']);
        $this->assertSame('Approve the signed agreement', app(LeaseHubService::class)->nextStep($lease->fresh(), $this->agent)['label']);
        $this->assertNull(app(LeaseSigningLauncher::class)->cardFor($lease->fresh(), $this->agent)['review_url']);
    }

    // ═══ the API ═══

    public function test_the_api_reports_the_differences_and_a_fingerprint_and_confirms_them(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);

        $signing = $this->actingAs($this->agent)->getJson("/api/v1/leases/{$lease->id}/signing");
        $signing->assertOk()->assertJsonPath('differences.0.key', 'rent')
            ->assertJsonPath('differences.0.lease', 'R8 500')->assertJsonPath('differences.0.agreement', 'R6 940');
        $fingerprint = $signing->json('fingerprint');
        $this->assertSame(64, strlen($fingerprint));

        $this->actingAs($this->agent)->postJson("/api/v1/leases/{$lease->id}/signing/confirm", ['fingerprint' => $fingerprint])
            ->assertOk()->assertJsonPath('changed.0.key', 'rent')->assertJsonPath('changed.0.old', 'R8 500')->assertJsonPath('changed.0.new', 'R6 940');

        $this->assertSame(6940.0, (float) $lease->fresh()->rental_amount);
        $this->assertSame(1, $this->confirmEvents($lease)->count());
    }

    public function test_the_api_refuses_a_stale_fingerprint_with_409_and_a_different_person_with_422(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $stale = $this->fingerprintOf($lease);
        $this->print($document, ['rent_f' => '7100.00']);

        $this->actingAs($this->agent)->postJson("/api/v1/leases/{$lease->id}/signing/confirm", ['fingerprint' => $stale])
            ->assertStatus(409)->assertJsonPath('code', 'agreement_changed_again');

        $this->print($document, ['tenant_f' => 'Sipho Dlamini']);
        $this->actingAs($this->agent)->postJson("/api/v1/leases/{$lease->id}/signing/confirm", ['fingerprint' => $this->fingerprintOf($lease)])
            ->assertStatus(422)->assertJsonPath('code', 'different_person_in_agreement');

        $this->assertSame(8500.0, (float) $lease->fresh()->rental_amount);
    }

    public function test_the_api_confirm_of_a_signed_draft_also_activates_it(): void
    {
        [$lease, $envelope, $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);
        $this->complete($envelope, $document);

        $this->actingAs($this->agent)->postJson("/api/v1/leases/{$lease->id}/signing/confirm", ['fingerprint' => $this->fingerprintOf($lease)])
            ->assertOk()->assertJsonPath('activated', true);

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
    }

    public function test_the_api_is_a_404_across_agencies(): void
    {
        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = Branch::create(['agency_id' => $rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        [$lease] = $this->agreementOut();

        // Rival first: the API guard keeps the first caller within one test (Sanctum), so a cross-agency case stands alone.
        $this->actingAs($rivalAgent)->postJson("/api/v1/leases/{$lease->id}/signing/confirm", ['fingerprint' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_the_routes_are_registered_by_name_in_the_catalogue(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('corex.leases.agreement.confirm'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('v1.leases.signing.confirm'));
        $this->assertStringStartsWith('api/v1/', \Illuminate\Support\Facades\Route::getRoutes()->getByName('v1.leases.signing.confirm')->uri());
        $this->assertContains('lease.agreement.confirmed', \Illuminate\Support\Facades\Route::getRoutes()->getByName('docuperfect.signatures.approveAndAdvance')->gatherMiddleware());
    }
}
