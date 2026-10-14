<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\RentalInspection;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTermAdvanceService;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rentals go-live walk, cc5's full walk (13 Oct 2026) - faults R, S, T. Johan's rules:
 *
 *  R  a renewal never touches the running lease before its own start date: the current lease stays active until its end, the renewal
 *     is "Signed - starts <date>" and only then becomes active (the old term expires with it); nothing the tenancy reads as "active"
 *     (portal, faults, property status, lease lists, the hub) changes in between.
 *  S  a tenancy ends on its move-out date, not when the out-inspection is completed; the property goes back to its pre-let status
 *     at the right moment (the date, once the inspection confirms vacant).
 *  T  the confirm screen is one clear approve action (no "nothing to confirm" dead end, no browser pop-up), and a renewal never asks
 *     for an in-inspection.
 *
 * Real services, HTTP routes and the e-sign completion event; nothing stubbed. Dates are set against the real clock (the renewal starts
 * in 2027, the move-out is in 2028), and `leases:advance-terms --date` plays the day the date comes.
 */
final class RenewalWalkFixesTest extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgreementFixture();
        Mail::fake();
    }

    /** The running lease (active, ends 31 Oct 2027) and its renewal whose agreement is waiting for the agent's approval (starts 1 Nov 2027). */
    private function renewalOut(string $start = '2027-11-01', string $end = '2028-10-31'): array
    {
        $previous = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $previous->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        app(LeaseActivationService::class)->activate($previous);
        // the tenant moved in a year ago: the running lease has its completed in-inspection
        RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $previous->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN, 'status' => RentalInspection::STATUS_COMPLETED,
        ]);

        [$renewal, $envelope, $document] = $this->agreementOut(['previous_lease_id' => $previous->id, 'start_date' => $start, 'end_date' => $end]);
        $this->print($document, ['start_f' => $start, 'end_f' => $end]);

        return [$previous->fresh(), $renewal, $envelope, $document];
    }

    private function complete(SignatureTemplate $envelope, Document $document): void
    {
        $envelope->update([
            'status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(),
            'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED,
        ]);
        event(new SignatureEnvelopeFinalized($envelope->id, $document->id, $this->agency->id));
    }

    // ═══ R - the renewal waits for its start date ═══

    public function test_a_renewal_signed_ahead_of_its_start_leaves_the_running_lease_exactly_as_it_was(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut();
        $propertyBefore = $this->property->fresh()->status;

        $this->complete($envelope, $document);

        $previous = $previous->fresh();
        $renewal = $renewal->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->status, 'the running lease stays active');
        $this->assertNull($previous->renewed_lease_id, 'and is not marked as renewed yet');
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->status, 'the renewal waits');
        $this->assertSame(Lease::SIGNING_SIGNED, $renewal->signing_status, 'signed, not lost');
        $this->assertNotNull($renewal->accepted_at, 'and approved');
        $this->assertTrue($renewal->isSignedRenewalAwaitingStart());
        $this->assertSame('Signed - starts 1 Nov 2027', $renewal->displayStatus());
        $this->assertSame('Active', $previous->displayStatus());
        $this->assertSame($propertyBefore, $this->property->fresh()->status, 'the property is not touched');
        $this->assertTrue(LeaseEvent::where('lease_id', $renewal->id)->where('event_type', LeaseEvent::TYPE_RENEWAL_SIGNED_AWAITING_START)->exists());
        $this->assertFalse(LeaseEvent::where('lease_id', $renewal->id)->where('event_type', LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING)->exists());
    }

    public function test_everything_that_keys_on_active_keeps_reading_the_running_lease_while_the_renewal_waits(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut();
        $this->complete($envelope, $document);

        // the tenant portal: the running lease is the tenant's live lease, the waiting renewal is not (yet)
        $this->assertSame([$previous->id], app(RentalPortalScopeService::class)->tenantLeaseIds($this->tenant));

        // the hub of the running lease says what is coming and offers nothing to click; the renewal's hub says the same
        $hub = app(LeaseHubService::class);
        $this->assertSame('Renewal signed - starts 1 Nov 2027', $hub->nextStep($previous, $this->agent)['label']);
        $this->assertNull($hub->nextStep($previous, $this->agent)['route_name']);
        $this->assertStringContainsString('Signed - starts 1 Nov 2027', (string) $hub->nextStep($renewal->fresh(), $this->agent)['label']);

        // the lease list and both lease screens
        $list = $this->actingAs($this->agent)->get(route('corex.leases.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Signed - starts 1 Nov 2027', $list);
        $this->actingAs($this->agent)->get(route('corex.leases.show', $renewal))->assertOk()->assertSee('Signed - starts 1 Nov 2027');
        $this->actingAs($this->agent)->get(route('corex.leases.show', $previous))->assertOk()->assertSee('Active');

        // cc5's re-walk (lease 119): the list's TILE and FILTER read the same status as the page - a signed renewal waiting for its start date is not a "Draft"
        $res = $this->actingAs($this->agent)->get(route('corex.leases.index'))->assertOk();
        $counts = $res->viewData('tileCounts');
        $this->assertSame(0, $counts['draft'], 'not counted as a draft');
        $this->assertSame(1, $counts['signed_awaiting_start']);
        $this->assertStringContainsString('Signed - starts 1 Nov 2027', $this->actingAs($this->agent)->get(route('corex.leases.index', ['status' => 'signed_awaiting_start']))->assertOk()->getContent());
        $this->assertStringNotContainsString('Signed - starts 1 Nov 2027', $this->actingAs($this->agent)->get(route('corex.leases.index', ['status' => 'draft']))->assertOk()->getContent());
        // an ordinary draft (same renewal, not signed) still counts as a draft
        $renewal->forceFill(['signing_status' => Lease::SIGNING_NOT_SENT, 'accepted_at' => null])->saveQuietly();
        $counts = $this->actingAs($this->agent)->get(route('corex.leases.index'))->viewData('tileCounts');
        $this->assertSame(1, $counts['draft']);
        $this->assertSame(0, $counts['signed_awaiting_start']);
        $renewal->forceFill(['signing_status' => Lease::SIGNING_SIGNED, 'accepted_at' => now()])->saveQuietly();

        // the running lease is still the property's one active lease
        $this->assertSame([$previous->id], Lease::withoutGlobalScopes()->where('property_id', $this->property->id)->where('status', Lease::STATUS_ACTIVE)->pluck('id')->all());

        // not switched to month-to-month by the daily job even after its end date passes - a renewal is in flight
        Artisan::call('leases:auto-month-to-month', ['--dry-run' => true]);
        $this->assertFalse($previous->fresh()->is_month_to_month);
    }

    public function test_on_its_start_date_the_renewal_goes_active_and_the_old_term_expires_with_it(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut();
        $this->complete($envelope, $document);
        $service = app(LeaseTermAdvanceService::class);

        // the day before: nothing
        $this->assertSame([], $service->activateDueRenewals('2027-10-31'));
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);

        // the day itself - a dry run changes nothing, the real run does
        $dry = $service->activateDueRenewals('2027-11-01', true);
        $this->assertSame('would_activate', $dry[0]['action']);
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);

        Artisan::call('leases:advance-terms', ['--date' => '2027-11-01']);

        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
        $this->assertSame($renewal->id, $previous->fresh()->renewed_lease_id);
        $this->assertSame('let_out', $this->property->fresh()->status, 'the property stays let');
        $this->assertContains($renewal->id, app(RentalPortalScopeService::class)->tenantLeaseIds($this->tenant), 'the tenant carries straight on: the renewal is live in the portal');

        // and a second run changes nothing
        Artisan::call('leases:advance-terms', ['--date' => '2027-11-02']);
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
    }

    public function test_a_renewal_that_starts_today_or_earlier_activates_at_signing_as_before(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut(now()->toDateString(), now()->addYear()->toDateString());

        $this->complete($envelope, $document);

        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
    }

    public function test_a_renewal_signed_with_an_unconfirmed_difference_is_not_activated_by_the_daily_job(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut();
        $this->print($document, ['rent_f' => '9900.00']);          // the agreement now disagrees with the lease
        $this->complete($envelope, $document);
        $renewal = $renewal->fresh();
        $this->assertSame(Lease::SIGNING_SIGNED, $renewal->signing_status);
        $this->assertTrue(app(LeaseHubService::class)->awaitingConfirmation($renewal));

        $out = app(LeaseTermAdvanceService::class)->activateDueRenewals('2027-11-05');

        $this->assertSame('skipped', $out[0]['action']);
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
    }

    public function test_a_signed_paper_copy_of_a_future_renewal_waits_for_its_start_date_too(): void
    {
        Storage::fake('local');
        [$previous] = $this->renewalOut();
        // the fixture's draft renewal is not wanted here: the capture screen creates its own
        Lease::where('previous_lease_id', $previous->id)->delete();

        $this->actingAs($this->agent)->post(route('corex.leases.renewal.store', $previous), [
            'intent' => 'paper_copy', 'start_date' => '2027-11-01', 'end_date' => '2028-10-31', 'rental_amount' => '9200',
            'signed_document' => UploadedFile::fake()->create('renewal.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $new = Lease::where('previous_lease_id', $previous->id)->firstOrFail();
        $this->assertSame(Lease::STATUS_DRAFT, $new->status);
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $new->signing_status);
        $this->assertTrue($new->isSignedRenewalAwaitingStart());
        $this->assertStringContainsString('starts on 1 Nov 2027', (string) session('success'));
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status, 'the running lease stays active');

        Artisan::call('leases:advance-terms', ['--date' => '2027-11-01']);
        $this->assertSame(Lease::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
    }

    // ═══ S - the tenancy ends on the move-out date ═══

    private function runningLeaseWithNotice(string $moveOut): Lease
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        app(LeaseActivationService::class)->activate($lease);
        $lease->forceFill(['notice_date' => now()->toDateString(), 'notice_given_by' => 'tenant', 'move_out_date' => $moveOut])->save();

        return $lease->fresh();
    }

    private function completeOutInspection(Lease $lease): RentalInspection
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'status' => 'scheduled',
        ]);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        return $inspection;
    }

    public function test_completing_the_out_inspection_before_the_move_out_date_does_not_end_the_lease(): void
    {
        $lease = $this->runningLeaseWithNotice('2028-01-31');
        $this->assertSame('let_out', $this->property->fresh()->status);

        $this->completeOutInspection($lease);

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'the lease ends on the move-out date, not on the inspection');
        $this->assertSame('let_out', $this->property->fresh()->status, 'the property is still let');
        $this->assertFalse(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_TENANCY_ENDED)->exists());
    }

    public function test_on_the_move_out_date_the_daily_job_ends_the_lease_and_puts_the_property_back_on_the_market(): void
    {
        $lease = $this->runningLeaseWithNotice('2028-01-31');
        $this->completeOutInspection($lease);
        $service = app(LeaseTermAdvanceService::class);

        $this->assertSame([], $service->endVacatedTenancies('2028-01-30'), 'the day before: nothing');
        $this->assertSame('would_end', $service->endVacatedTenancies('2028-01-31', true)[0]['action']);
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'a dry run changes nothing');

        Artisan::call('leases:advance-terms', ['--date' => '2028-01-31']);

        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
        $property = $this->property->fresh();
        $this->assertSame('active', $property->status, 'back to the status it had before it was let');
        $this->assertTrue($property->isOnMarket());
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_TENANCY_ENDED)->exists());
    }

    public function test_an_out_inspection_completed_on_or_after_the_move_out_date_ends_the_lease_at_once(): void
    {
        $lease = $this->runningLeaseWithNotice(now()->toDateString());

        $this->completeOutInspection($lease);

        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
        $this->assertSame('active', $this->property->fresh()->status);
    }

    public function test_without_an_out_inspection_the_move_out_date_alone_ends_nothing(): void
    {
        $lease = $this->runningLeaseWithNotice('2028-01-31');

        Artisan::call('leases:advance-terms', ['--date' => '2028-02-15']);

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'the agent still has to see the property empty');
    }

    // ═══ T - one clear approve action, and no in-inspection for a renewal ═══

    public function test_the_confirm_screen_with_nothing_to_confirm_is_the_approval_itself(): void
    {
        [, $renewal, , $document] = $this->renewalOut();     // the agreement is waiting for the agent's approval and agrees with the lease

        $html = $this->actingAs($this->agent)->get(route('corex.leases.agreement.confirm', $renewal))->assertOk()->getContent();

        $this->assertStringNotContainsString('nothing to confirm', $html);
        $this->assertStringContainsString('data-qa="confirm-clean"', $html);
        $this->assertStringContainsString('Approve and finalise', $html, 'the one approve action is on this screen');
        $this->assertStringContainsString('action="' . route('docuperfect.signatures.approveAndAdvance', $document) . '"', $html);
        $this->assertStringNotContainsString('lease_confirm[fingerprint]', $html, 'a clean approval carries no confirmation payload');
        $this->assertStringNotContainsString('confirm(', $html, 'no browser pop-up');
    }

    public function test_the_e_sign_review_page_asks_through_the_apps_own_dialog_never_the_browsers(): void
    {
        $source = (string) file_get_contents(resource_path('views/docuperfect/signatures/review.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\bconfirm\(/', $source, 'no window.confirm() on the approve page');
        $this->assertStringContainsString('data-confirm=', $source);
    }

    public function test_a_renewal_never_asks_for_an_in_inspection_but_a_first_lease_still_does(): void
    {
        [$previous, $renewal, $envelope, $document] = $this->renewalOut(now()->toDateString(), now()->addYear()->toDateString());
        $this->complete($envelope, $document);
        $renewal = $renewal->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->status);
        $hub = app(LeaseHubService::class);

        $this->assertNotSame('Start in-inspection', $hub->nextStep($renewal, $this->agent)['label'] ?? null);
        $inspectionStep = collect($hub->lifecycle($renewal))->firstWhere('key', 'in_inspection');
        $this->assertSame('done', $inspectionStep['state']);

        // a first lease with no in-inspection on file still gets the prompt
        $first = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2026-01-01', 'end_date' => '2028-12-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame('Start in-inspection', $hub->nextStep($first, $this->agent)['label']);
    }

    public function test_a_renewal_carries_both_agents_and_the_property_details_into_its_agreement(): void
    {
        [$previous] = $this->renewalOut();
        $previous->forceFill(['owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id])->save();
        $this->property->forceFill(['street_number' => '14', 'street_name' => 'Beach Road', 'unit_number' => '12', 'complex_name' => 'Ocean Court', 'property_number' => 'ERF 1234'])->save();

        $renewal = app(\App\Services\Rentals\LeaseRenewalService::class)->createRenewalTerm($previous, [
            'start_date' => '2027-11-01', 'end_date' => '2028-10-31', 'rental_amount' => 9000,
        ], $this->agent);
        $snapshot = app(\App\Services\Rentals\LeaseSigningLauncher::class)->propertySnapshot($this->property->fresh());

        $this->assertSame($this->agent->id, $renewal->owner_agent_user_id);
        $this->assertSame($this->agent->id, $renewal->tenant_agent_user_id);
        $this->assertSame('12', $snapshot['unit_number']);
        $this->assertSame('Ocean Court', $snapshot['complex_name']);
        $this->assertSame('ERF 1234', $snapshot['erf']);
        $this->assertStringContainsString('Beach Road', (string) $snapshot['street']);
    }
}
