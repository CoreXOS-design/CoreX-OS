<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\Signatures\SignedDocumentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalPortalSetting;
use App\Models\User;
use App\Services\Rentals\LeaseSignedCopyMailer;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §18 — Johan, 2026-10-07: when a paper-signed (wet ink) lease is attached, its tenant(s)
 * and landlord(s) get the lease copy by email AND their portal link, the same as when it is signed in e-sign: who receives
 * (tenants and landlords, never the agent, one mail per address), portal access created, the agency's portal switches
 * honoured, the agent's own mail path, and never twice — a double submit, a retry or a re-upload mails nobody again.
 */
final class LeasePaperCopyMailTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'address' => '401 Margate Boulevard',
        ]);
        $this->tenant = $this->contact('Ayanda', 'ayanda@example.test');
        $this->owner = $this->contact('Siyabonga', 'siya@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->owner->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contact(string $first, ?string $email): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => 'Test', 'email' => $email,
        ]);

        return $c->fresh();
    }

    private function setting(array $values): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], $values);
    }

    /** What the agent does on the New Lease screen: tenant(s), a start date, and "I already have the signed copy — attach it". */
    private function attachPaperCopy(array $overrides = [], ?UploadedFile $file = null)
    {
        return $this->actingAs($this->agent)->post(route('corex.leases.store'), array_merge([
            'property_id' => $this->property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01',
            'tenant_contact_ids' => [$this->tenant->id], 'intent' => 'paper_copy',
            'signed_document' => $file ?? UploadedFile::fake()->create('signed-lease.pdf', 100, 'application/pdf'),
        ], $overrides));
    }

    private function mailsTo(string $email)
    {
        return Mail::sent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => $m->hasTo($email));
    }

    public function test_the_tenant_and_the_owner_each_get_the_copy_with_their_own_portal_link(): void
    {
        $this->attachPaperCopy()->assertRedirect();

        $lease = Lease::firstOrFail();
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $lease->signing_status);
        Mail::assertSent(SignedDocumentMail::class, 2);

        $tenantMail = $this->mailsTo('ayanda@example.test')->first();
        $ownerMail = $this->mailsTo('siya@example.test')->first();
        $this->assertNotNull($tenantMail);
        $this->assertNotNull($ownerMail);
        $this->assertCount(1, $tenantMail->attachments(), 'the signed copy is attached');
        $t = $tenantMail->render();
        $this->assertStringContainsString('Your CoreX portal', $t);
        $this->assertStringContainsString(url('/portal') . '?email=ayanda%40example.test', $t);
        $this->assertStringContainsString('report a fault', $t);
        $this->assertStringContainsString('Lease agreement', $t);
        $o = $ownerMail->render();
        $this->assertStringContainsString(url('/portal') . '?email=siya%40example.test', $o);
        $this->assertStringContainsString('approve or decline repair decisions', strtolower($o));
        $this->assertStringNotContainsString('ayanda%40example.test', $o);

        Mail::assertNotSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => $m->hasTo($this->agent->email), 'the agent is never a recipient');
        $this->assertNotNull(Lease::withoutGlobalScopes()->find($lease->id)->signed_copy_emailed_at);
    }

    public function test_portal_access_is_created_for_both_before_the_link_goes_out(): void
    {
        $this->attachPaperCopy();

        $this->assertNotNull($this->tenant->fresh()->client_user_id);
        $this->assertNotNull($this->owner->fresh()->client_user_id);
        $this->assertSame(2, ClientUser::count());
    }

    public function test_it_is_written_to_the_tenancy_log(): void
    {
        $this->attachPaperCopy();

        $event = LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED)->firstOrFail();
        $this->assertStringContainsString('ayanda@example.test', $event->description);
        $this->assertStringContainsString('siya@example.test', $event->description);
        $this->assertEqualsCanonicalizing(['ayanda@example.test', 'siya@example.test'], $event->metadata['sent']);
    }

    public function test_a_person_with_one_address_on_two_roles_gets_one_mail_listing_both(): void
    {
        DB::table('contact_property')->insert([
            'contact_id' => $this->tenant->id, 'property_id' => $this->property->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->attachPaperCopy();

        $this->assertCount(1, $this->mailsTo('ayanda@example.test'));
        $this->assertCount(1, $this->mailsTo('siya@example.test'));
        $html = $this->mailsTo('ayanda@example.test')->first()->render();
        $this->assertStringContainsString('report a fault', $html);
        $this->assertStringContainsString('approve or decline repair decisions', strtolower($html));
    }

    public function test_two_tenants_sharing_one_address_get_one_mail(): void
    {
        $partner = $this->contact('Partner', 'ayanda@example.test');

        $this->attachPaperCopy(['tenant_contact_ids' => [$this->tenant->id, $partner->id]]);

        $this->assertCount(1, $this->mailsTo('ayanda@example.test'));
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    public function test_a_party_without_an_email_is_skipped_and_named_in_the_log(): void
    {
        $this->owner->forceFill(['email' => null])->saveQuietly();

        $this->attachPaperCopy();

        Mail::assertSent(SignedDocumentMail::class, 1);
        $this->assertCount(1, $this->mailsTo('ayanda@example.test'));
        $event = LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED)->firstOrFail();
        $this->assertStringContainsString('no email saved for', $event->description);
        $this->assertStringContainsString('Siyabonga', $event->description);
    }

    public function test_nobody_with_an_email_means_no_mail_no_claim_and_a_log_line(): void
    {
        $this->tenant->forceFill(['email' => null])->saveQuietly();
        $this->owner->forceFill(['email' => null])->saveQuietly();

        $this->attachPaperCopy()->assertRedirect();

        Mail::assertNothingSent();
        $lease = Lease::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($lease->signed_copy_emailed_at);
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $lease->signing_status, 'the lease itself is unaffected');
        $this->assertSame(1, LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED)->count());
    }

    // ── the agency's portal switches ─────────────────────────────────────────────────────────

    public function test_with_one_portal_off_that_person_still_gets_the_copy_but_no_link(): void
    {
        $this->setting(['tenant_portal_enabled' => false]);

        $this->attachPaperCopy();

        Mail::assertSent(SignedDocumentMail::class, 2);
        $this->assertStringNotContainsString('Your CoreX portal', $this->mailsTo('ayanda@example.test')->first()->render());
        $this->assertStringContainsString('Your CoreX portal', $this->mailsTo('siya@example.test')->first()->render());
        $this->assertNull($this->tenant->fresh()->client_user_id);
    }

    public function test_with_automatic_access_off_the_copy_goes_out_with_no_link_and_no_login(): void
    {
        $this->setting(['auto_portal_access_on_signing' => false]);

        $this->attachPaperCopy();

        Mail::assertSent(SignedDocumentMail::class, 2);
        Mail::assertNotSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => str_contains($m->render(), 'Your CoreX portal'));
        $this->assertSame(0, ClientUser::count());
    }

    // ── never twice: double submit, retry, re-upload ─────────────────────────────────────────

    public function test_submitting_the_same_screen_twice_mails_nobody_a_second_time(): void
    {
        $this->attachPaperCopy(['capture_key' => 'screen-1']);
        $this->attachPaperCopy(['capture_key' => 'screen-1'], UploadedFile::fake()->create('signed-lease.pdf', 100, 'application/pdf'));

        $this->assertSame(1, Lease::withoutGlobalScopes()->count());
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    public function test_a_retry_or_a_replaced_copy_on_the_same_lease_sends_nothing_again(): void
    {
        $this->attachPaperCopy();
        $lease = Lease::withoutGlobalScopes()->firstOrFail();
        Mail::assertSent(SignedDocumentMail::class, 2);

        // A second signed copy filed against the same lease (a re-upload / replacement), then the mailer runs again.
        \App\Models\Document::create([
            'original_name' => 'signed-lease-corrected.pdf', 'storage_path' => 'lease-signed-copies/' . $lease->id . '/corrected.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 10, 'source_type' => 'lease', 'source_id' => $lease->id,
            'uploaded_by' => $this->agent->id,
        ]);
        Storage::disk('local')->put('lease-signed-copies/' . $lease->id . '/corrected.pdf', '%PDF-1.4 corrected');

        $result = app(LeaseSignedCopyMailer::class)->sendOnPaperAttach($lease->fresh(), $this->agent);

        $this->assertSame('already_sent', $result['status']);
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    public function test_when_nothing_can_be_delivered_the_claim_is_released_and_a_later_run_sends(): void
    {
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->attachPaperCopy()->assertRedirect();

        $lease = Lease::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status, 'a mail fault never undoes the lease');
        $this->assertNull($lease->signed_copy_emailed_at, 'nobody received it, so the lease is not marked sent');
        $this->assertStringContainsString('could not be emailed', LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED)->firstOrFail()->description);

        $this->app->forgetInstance(RentalMailDispatcher::class);
        $this->app->forgetInstance(LeaseSignedCopyMailer::class);
        $this->app->bind(RentalMailDispatcher::class, fn () => new RentalMailDispatcher());
        $result = app(LeaseSignedCopyMailer::class)->sendOnPaperAttach($lease->fresh(), $this->agent);

        $this->assertSame('sent', $result['status']);
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    public function test_one_failing_address_does_not_stop_the_others_and_the_lease_stays_marked_sent(): void
    {
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void
            {
                if ($recipientEmail === 'siya@example.test') {
                    throw new \RuntimeException('mailbox full');
                }
                \Illuminate\Support\Facades\Mail::to($recipientEmail)->send($mail);
            }
        });

        $this->attachPaperCopy();

        $this->assertCount(1, $this->mailsTo('ayanda@example.test'));
        $this->assertCount(0, $this->mailsTo('siya@example.test'));
        $this->assertNotNull(Lease::withoutGlobalScopes()->firstOrFail()->signed_copy_emailed_at);
        $this->assertStringContainsString('could not reach siya@example.test', LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED)->firstOrFail()->description);
    }

    // ── the other ways a paper copy arrives ──────────────────────────────────────────────────

    public function test_a_renewal_recorded_from_a_paper_copy_mails_the_renewal_terms_parties(): void
    {
        $current = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $current->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $this->actingAs($this->agent)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'paper_copy', 'start_date' => '2026-11-01', 'rental_amount' => '8600', 'deposit_amount' => '',
            'signed_document' => UploadedFile::fake()->create('renewal-signed.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $renewal = Lease::withoutGlobalScopes()->where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertNotNull($renewal->signed_copy_emailed_at);
        $this->assertNull($current->fresh()->signed_copy_emailed_at);
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    public function test_a_photo_or_word_upload_is_attached_with_its_own_type_not_as_a_pdf(): void
    {
        $this->attachPaperCopy([], UploadedFile::fake()->image('signed-lease.jpg', 800, 1000));

        $mail = $this->mailsTo('ayanda@example.test')->first();
        $this->assertSame('image/jpeg', $mail->attachments()[0]->mime);
        $this->assertSame('signed-lease.jpg', $mail->attachments()[0]->as);
    }

    public function test_a_lease_only_capture_sends_nothing(): void
    {
        $this->actingAs($this->agent)->post(route('corex.leases.store'), [
            'property_id' => $this->property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01',
            'tenant_contact_ids' => [$this->tenant->id], 'intent' => 'lease_only',
        ])->assertRedirect();

        Mail::assertNothingSent();
    }
}
