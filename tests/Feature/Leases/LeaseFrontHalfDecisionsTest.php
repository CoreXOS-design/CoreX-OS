<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Mail\ClientAuthOtpMail;
use App\Mail\Signatures\SignedDocumentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalSettingAuditEntry;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseAutoMonthToMonthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals front-half decisions (8 Oct 2026) - the lease side. Each is a per-agency setting whose default is the recommended value:
 *  D12 reversing month-to-month puts the original end date back (when it is still ahead)
 *  D13 the signed-copy mail carries the agency's one sentence when the lease cannot go live yet
 *  D11 the sign-in code mail names the agency when the person belongs to exactly one
 *  and the lease settings save audited, has()-guarded and permissioned. (D8 - the end date / month-to-month rule - is pinned in
 *  LeaseCaptureTest, where the capture helpers live.)
 * All mail is faked.
 */
final class LeaseFrontHalfDecisionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLeaseAgreementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        \App\Support\Audit\AuditContext::reset(); // static actor state must not leak in from an earlier test
        $this->setUpAgreementFixture();
    }

    private function activeLease(array $overrides = []): Lease
    {
        return Lease::create($overrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8500, 'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->addMonths(9)->toDateString(), 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'signing_status' => Lease::SIGNING_NOT_SENT,
        ]);
    }

    // ── D12 - reversing month-to-month restores the end date ──────────────

    public function test_reversing_a_month_to_month_switch_by_the_agent_puts_the_original_end_date_back(): void
    {
        $lease = $this->activeLease();
        $end = $lease->end_date->toDateString();

        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month', $lease), ['note' => 'tenant stays'])->assertSessionHas('success');
        $this->assertNull($lease->fresh()->end_date);
        $this->assertTrue($lease->fresh()->is_month_to_month);

        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month.reverse', $lease))->assertSessionHas('success');

        $fresh = $lease->fresh();
        $this->assertFalse($fresh->is_month_to_month);
        $this->assertSame($end, $fresh->end_date->toDateString());
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_MONTH_TO_MONTH_REVERSED)->latest('id')->first();
        $this->assertSame($end, $event->metadata['restored_end_date'], 'the restoration is on the tenancy log');
    }

    public function test_the_original_end_date_is_not_put_back_when_it_is_already_past_or_the_agency_switched_it_off_or_it_was_never_recorded(): void
    {
        // Already past: restoring it would send the lease straight back to month-to-month tonight.
        $past = $this->activeLease(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subMonth()->toDateString()]);
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month', $past));
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month.reverse', $past));
        $this->assertNull($past->fresh()->end_date);

        // Switched off.
        $off = $this->activeLease(['property_id' => $this->secondProperty()->id]);
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['restore_end_date_on_leaving_month_to_month' => false]);
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month', $off));
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month.reverse', $off));
        $this->assertNull($off->fresh()->end_date);

        // A switch made before the date was recorded has nothing to restore (and nothing is invented).
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['restore_end_date_on_leaving_month_to_month' => true]);
        $old = $this->activeLease(['property_id' => $this->secondProperty()->id, 'is_month_to_month' => true, 'end_date' => null]);
        LeaseEvent::create(['lease_id' => $old->id, 'event_type' => LeaseEvent::TYPE_MONTH_TO_MONTH_SET, 'description' => 'earlier', 'metadata' => ['note' => null], 'occurred_at' => now(), 'created_at' => now()]);
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.month-to-month.reverse', $old));
        $this->assertNull($old->fresh()->end_date);
    }

    private function secondProperty(): \App\Models\Property
    {
        return \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Second', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    public function test_the_automatic_switch_records_the_end_date_it_removes(): void
    {
        $lease = $this->activeLease(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subDays(3)->toDateString()]);

        $this->assertTrue(app(LeaseAutoMonthToMonthService::class)->convert($lease));

        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_MONTH_TO_MONTH_SET)->first();
        $this->assertSame(now()->subDays(3)->toDateString(), $event->metadata['previous_end_date']);
    }

    // ── D13 - the signed copy says so when the lease cannot go live yet ───

    private function noteFor(Lease $lease): ?string
    {
        $envelope = \App\Models\Docuperfect\SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => \App\Models\Docuperfect\Document::create([
                'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id,
            ])->id, 'document_hash' => str_repeat('a', 64), 'status' => 'completed', 'created_by' => $this->agent->id,
        ]);
        $lease->update(['signature_template_id' => $envelope->id]);
        $method = new \ReflectionMethod(SignatureService::class, 'leaseNotLiveNoteFor');
        $method->setAccessible(true);

        return $method->invoke(app(SignatureService::class), $envelope);
    }

    public function test_a_signed_lease_that_is_blocked_by_another_active_lease_carries_the_agencys_sentence(): void
    {
        $other = $this->activeLease();
        $draft = $this->activeLease(['status' => Lease::STATUS_DRAFT, 'property_id' => $other->property_id, 'start_date' => now()->addMonths(10)->toDateString(), 'end_date' => now()->addMonths(22)->toDateString()]);

        $this->assertNotNull(app(LeaseActivationService::class)->blockingLease($draft));
        $this->assertSame(LeaseSetting::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE, $this->noteFor($draft));

        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signed_copy_not_live_note' => 'Your lease starts once the current one ends.']);
        $this->assertSame('Your lease starts once the current one ends.', $this->noteFor($draft));

        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signed_copy_not_live_note' => '']);
        $this->assertNull($this->noteFor($draft), 'an empty sentence says nothing extra');
    }

    public function test_no_sentence_when_the_lease_can_go_live_or_is_the_renewal_of_the_active_one(): void
    {
        $clear = $this->activeLease(['status' => Lease::STATUS_DRAFT]);
        $this->assertNull(app(LeaseActivationService::class)->blockingLease($clear));
        $this->assertNull($this->noteFor($clear));

        $current = $this->activeLease(['property_id' => $this->secondProperty()->id]);
        $renewal = $this->activeLease(['status' => Lease::STATUS_DRAFT, 'property_id' => $current->property_id, 'previous_lease_id' => $current->id]);
        $this->assertNull(app(LeaseActivationService::class)->blockingLease($renewal), 'its own predecessor never blocks a renewal');
        $this->assertNull($this->noteFor($renewal));
    }

    public function test_the_signed_copy_mail_prints_the_sentence_only_when_it_has_one(): void
    {
        $with = (new SignedDocumentMail('Thandi', 'Lease', null, [], null, null, [], null, 'Not active yet.'))->render();
        $without = (new SignedDocumentMail('Thandi', 'Lease'))->render();

        $this->assertStringContainsString('Not active yet.', $with);
        $this->assertStringContainsString('data-lease-note', $with);
        $this->assertStringNotContainsString('data-lease-note', $without);
    }

    // ── D11 - the sign-in code names the agency ───────────────────────────

    private function clientWith(array $agencyNames): string
    {
        $email = 'can.assurance@gmail.com';
        $clientUser = ClientUser::create(['email' => $email]);
        foreach ($agencyNames as $name) {
            $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . uniqid())]);
            $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
            Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'N', 'email' => $email, 'client_user_id' => $clientUser->id]);
        }

        return $email;
    }

    public function test_the_sign_in_code_names_the_agency_when_the_person_belongs_to_exactly_one(): void
    {
        $email = $this->clientWith(['Karoo Lettings']);

        $this->postJson('/api/v1/client-auth/otp/send', ['email' => $email])->assertOk();

        Mail::assertSent(ClientAuthOtpMail::class, function (ClientAuthOtpMail $mail) {
            return $mail->agencyName === 'Karoo Lettings' && str_contains($mail->render(), 'Karoo Lettings portal') && ! str_contains($mail->render(), 'mobile app');
        });
    }

    public function test_the_sign_in_code_is_neutral_for_a_person_in_several_agencies_and_for_an_unknown_address(): void
    {
        $email = $this->clientWith(['Karoo Lettings', 'Coast Rentals']);
        $this->postJson('/api/v1/client-auth/otp/send', ['email' => $email])->assertOk();
        Mail::assertSent(ClientAuthOtpMail::class, fn (ClientAuthOtpMail $mail) => $mail->agencyName === null && str_contains($mail->render(), 'Enter this code to sign in'));

        $this->postJson('/api/v1/client-auth/otp/send', ['email' => 'nobody-' . uniqid() . '@example.test'])->assertOk();
        Mail::assertSent(ClientAuthOtpMail::class, fn (ClientAuthOtpMail $mail) => $mail->agencyName === null && $mail->to !== []);
    }

    // ── the lease settings: saved, audited, has()-guarded ─────────────────

    public function test_the_lease_settings_save_audit_and_leave_unposted_fields_alone(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60, 'restore_end_date_on_leaving_month_to_month' => '0', 'signed_copy_not_live_note' => '  Wait for your agent.  ',
        ])->assertSessionHas('success');

        $this->assertFalse(LeaseSetting::restoreEndDateOnLeavingMonthToMonthFor($this->agency->id));
        $this->assertSame('Wait for your agent.', LeaseSetting::signedCopyNotLiveNoteFor($this->agency->id));
        $this->assertTrue(LeaseSetting::requireEndOrMonthToMonthForSigningFor($this->agency->id), 'not posted, so untouched');

        $keys = RentalSettingAuditEntry::where('agency_id', $this->agency->id)->pluck('setting_key')->all();
        $this->assertEqualsCanonicalizing(['restore_end_date_on_leaving_month_to_month', 'signed_copy_not_live_note'], $keys);
        $row = RentalSettingAuditEntry::where('setting_key', 'restore_end_date_on_leaving_month_to_month')->first();
        $this->assertSame(['on', 'off', $admin->id], [$row->old_value, $row->new_value, $row->user_id]);
    }

    public function test_the_new_defaults_are_the_recommended_values_for_an_agency_that_never_set_them(): void
    {
        $this->assertTrue(LeaseSetting::requireEndOrMonthToMonthForSigningFor($this->agency->id));
        $this->assertTrue(LeaseSetting::restoreEndDateOnLeavingMonthToMonthFor($this->agency->id));
        $this->assertSame(LeaseSetting::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE, LeaseSetting::signedCopyNotLiveNoteFor($this->agency->id));
        $this->assertSame(
            LeaseSetting::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE,
            collect(config('agency-onboarding-copy.leases.controls'))->firstWhere('key', 'signed_copy_not_live_note')['default'],
            'the wizard and the model agree on the default wording'
        );
    }
}
