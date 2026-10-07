<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use App\Notifications\RentalInspectionCopiesNotDelivered;
use App\Services\Rentals\RentalInspectionCopiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §45.6 (Build I-4) — copies of a completed inspection report to everyone who should have one, the same facts visible in a
 * "Copies sent" panel with a per-recipient Resend, a failed or skipped copy alerting the inspector, and interim inspections
 * signed by all three parties.
 *
 * MAIL SAFETY: nothing here can reach a real inbox. Every send goes through Mail::fake(); the non-production redirect is set
 * to the one permitted test address; no CommunicationMailbox exists for any user, so the per-mailbox SMTP path is never used.
 */
final class RentalInspectionI4CopiesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\RentalInspections\Concerns\RecordsAttendance;

    private const TEST_ADDRESS = 'can.assurance@gmail.com';
    private const TEST_SIGNATURE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Branch $otherBranch;
    private User $agent;      // created the inspection
    private User $inspector;  // ran it
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        config(['mail.non_production_redirect' => self::TEST_ADDRESS]);
        Mail::fake();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $this->agency->id]);
        $this->otherBranch = Branch::forceCreate(['name' => 'Gardens', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'email' => 'creator@cape.test']);
        $this->inspector = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'email' => 'inspector@cape.test']);
        $this->actingAs($this->agent);

        \App\Models\DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '14 Jackson Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonths(6),
            'created_by_user_id' => $this->agent->id,
        ]);
        // The tenant was captured by a colleague in ANOTHER branch: the creating agent's own contact scope cannot see them.
        $this->tenant = $this->contact('Naledi', 'Dlamini', 'naledi@example.co.za', $this->otherBranch);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = $this->contact('Pieter', 'van Wyk', 'pieter@example.co.za');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
    }

    protected function tearDown(): void
    {
        User::getEventDispatcher()?->forget('eloquent.retrieved: ' . User::class);
        parent::tearDown();
    }

    private function contact(string $first, string $last, ?string $email, ?Branch $branch = null): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => ($branch ?? $this->branch)->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'created_by_user_id' => $branch ? User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id])->id : $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_OUT, array $extra = []): RentalInspection
    {
        $inspection = RentalInspection::create($extra + [
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id, 'inspector_user_id' => $this->inspector->id,
        ]);
        if ($type !== RentalInspection::TYPE_AD_HOC) {
            $inspection->startAwaitingSignature();
            // I-3's completion guard: every expected party needs an attendance outcome before the inspection can complete.
            $this->recordAttendanceForEveryParty($inspection);
        }

        return $inspection;
    }

    /** @param array<int, Contact> $extraTenants further tenants who must sign BEFORE the agent can */
    private function sign(RentalInspection $inspection, bool $tenant = true, bool $landlord = true, bool $agent = true, array $extraTenants = []): void
    {
        if ($tenant) {
            RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
                'party_contact_id' => $this->tenant->id, 'party_signature_path' => '/fake/tenant.png', 'recorded_by_user_id' => $this->agent->id,
            ]);
        }
        if ($landlord) {
            RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_LANDLORD, RentalInspectionSignature::DISPOSITION_SIGNED, [
                'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png', 'recorded_by_user_id' => $this->agent->id,
            ]);
        }
        foreach ($extraTenants as $extra) {
            RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
                'party_contact_id' => $extra->id, 'party_signature_path' => '/fake/extra-tenant.png', 'recorded_by_user_id' => $this->agent->id,
            ]);
        }
        if ($agent) {
            $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
                'party_role' => RentalInspectionSignature::PARTY_AGENT,
                'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
                'signature_image' => self::TEST_SIGNATURE_IMAGE,
            ])->assertStatus(201);
        }
    }

    private function settings(array $attrs): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], $attrs);
    }

    /** @return array<int, array{role:string,email:string,name:string,contact_id:?int}> */
    private function recipients(RentalInspection $inspection): array
    {
        return $inspection->fresh(['lease', 'property', 'inspector', 'createdBy'])->distributionRecipients();
    }

    private function emails(array $recipients): array
    {
        return array_column($recipients, 'email');
    }

    // ───────────────────────────── who gets a copy ─────────────────────────────

    public function test_a_tenant_the_completing_agent_cannot_see_still_gets_their_copy(): void
    {
        $inspection = $this->inspection();
        $this->assertNull(Contact::find($this->tenant->id), 'Precondition: the agent\'s own contact scope hides this tenant.');

        $this->assertContains('naledi@example.co.za', $this->emails($this->recipients($inspection)));
        $this->assertContains('pieter@example.co.za', $this->emails($this->recipients($inspection)));
    }

    public function test_every_tenant_and_every_landlord_gets_a_copy_and_roles_are_right(): void
    {
        $second = $this->contact('Sipho', 'Khumalo', 'sipho@example.co.za');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $second->id]);
        $coOwner = $this->contact('Annelie', 'van Wyk', 'annelie@example.co.za');
        $this->property->contacts()->attach($coOwner->id, ['role' => 'landlord']);

        $byEmail = collect($this->recipients($this->inspection()))->pluck('role', 'email')->all();

        $this->assertSame('tenant', $byEmail['naledi@example.co.za']);
        $this->assertSame('tenant', $byEmail['sipho@example.co.za']);
        $this->assertSame('landlord', $byEmail['pieter@example.co.za']);
        $this->assertSame('landlord', $byEmail['annelie@example.co.za'], 'Signing stays single-landlord; copies go to every landlord.');
    }

    public function test_a_party_with_no_email_is_reported_as_unreachable_not_silently_dropped(): void
    {
        $noEmail = $this->contact('Thandi', 'Mokoena', null);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id]);
        $inspection = $this->inspection()->fresh(['lease', 'property']);

        $this->assertNotContains(null, array_column($inspection->distributionRecipients(), 'email'));
        $unreachable = $inspection->distributionUnreachableRecipients();
        $this->assertCount(1, $unreachable);
        $this->assertSame('tenant', $unreachable[0]['role']);
        $this->assertSame($noEmail->id, $unreachable[0]['contact_id']);
        $this->assertSame('No email address on file', $unreachable[0]['reason']);
    }

    public function test_a_tenant_who_is_also_a_landlord_contact_gets_one_mail(): void
    {
        $this->property->contacts()->attach($this->tenant->id, ['role' => 'landlord']);

        $recipients = $this->recipients($this->inspection());

        $this->assertSame(1, count(array_filter($recipients, fn ($r) => $r['email'] === 'naledi@example.co.za')));
    }

    public function test_agency_copy_addresses_none_one_many_duplicated_and_mixed_case(): void
    {
        $inspection = $this->inspection();
        $this->assertEmpty(array_filter($this->recipients($inspection), fn ($r) => $r['role'] === 'agency'), 'Nothing is assumed: no agency address unless the agency adds one.');

        $this->settings(['report_agency_copy_emails' => 'Rentals@Cape.test']);
        $agency = array_values(array_filter($this->recipients($inspection), fn ($r) => $r['role'] === 'agency'));
        $this->assertSame(['rentals@cape.test'], array_column($agency, 'email'));
        $this->assertSame('Cape Rentals', $agency[0]['name']);

        $this->settings(['report_agency_copy_emails' => 'rentals@cape.test, accounts@cape.test; RENTALS@cape.test']);
        $this->assertSame(['rentals@cape.test', 'accounts@cape.test'], array_column(array_filter($this->recipients($inspection), fn ($r) => $r['role'] === 'agency'), 'email'));
    }

    public function test_inspector_and_creator_are_copied_by_default_and_each_toggle_switches_it_off(): void
    {
        $inspection = $this->inspection();
        $roles = fn () => collect($this->recipients($inspection))->pluck('role', 'email')->all();

        $this->assertSame('inspector', $roles()['inspector@cape.test']);
        $this->assertSame('creator', $roles()['creator@cape.test']);

        $this->settings(['report_copy_inspector' => false]);
        $this->assertArrayNotHasKey('inspector@cape.test', $roles());
        $this->assertArrayHasKey('creator@cape.test', $roles());

        $this->settings(['report_copy_inspector' => true, 'report_copy_creator' => false]);
        $this->assertArrayHasKey('inspector@cape.test', $roles());
        $this->assertArrayNotHasKey('creator@cape.test', $roles());
    }

    public function test_the_creator_who_is_also_the_inspector_gets_one_mail_and_an_inspection_with_no_inspector_still_works(): void
    {
        $self = $this->inspection(RentalInspection::TYPE_OUT, ['inspector_user_id' => $this->agent->id]);
        $this->assertSame(1, count(array_filter($this->recipients($self), fn ($r) => $r['email'] === 'creator@cape.test')));

        $none = $this->inspection(RentalInspection::TYPE_AD_HOC, ['inspector_user_id' => null]);
        $this->assertContains('creator@cape.test', $this->emails($this->recipients($none)));
        $this->assertSame($this->agent->id, $none->fresh()->distributionAgent()->id, 'No inspector: the copies come from the creator\'s mailbox.');
    }

    public function test_copies_are_sent_from_the_inspectors_mailbox(): void
    {
        $this->assertSame($this->inspector->id, $this->inspection()->fresh()->distributionAgent()->id);
    }

    // ───────────────────────────── completing: mail, log, panel, alerts ─────────────────────────────

    public function test_completing_sends_every_copy_and_the_panel_lists_each_one(): void
    {
        $this->settings(['report_agency_copy_emails' => 'rentals@cape.test']);
        $inspection = $this->inspection();
        $this->sign($inspection);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        Mail::assertSent(SignedDocumentDistributionMail::class, 5); // tenant, landlord, agency, inspector, creator
        $roles = SignedDocumentDistributionLog::where('distributable_id', $inspection->id)->where('channel', 'email')->where('status', 'sent')->pluck('recipient_role')->all();
        $this->assertEqualsCanonicalizing(['tenant', 'landlord', 'agency', 'inspector', 'creator'], $roles);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $inspection))
            ->assertOk()->assertSee('Copies sent')->assertSee('naledi@example.co.za')->assertSee('rentals@cape.test')->assertSee('Agency copy');
    }

    public function test_the_agent_who_is_also_a_recipient_is_not_copied_twice(): void
    {
        // `outward_email` is read from the model's attributes (AT-79) but is not a column in this schema, so inject it on retrieval.
        $inspectorId = $this->inspector->id;
        User::retrieved(function (User $u) use ($inspectorId) {
            if ($u->id === $inspectorId) {
                $u->setAttribute('outward_email', 'inspector@cape.test');
            }
        });
        $inspection = $this->inspection();
        $this->sign($inspection);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        // The sending agent already receives their own copy as a recipient, so no mail needs a CC to them as well.
        Mail::assertSent(SignedDocumentDistributionMail::class, fn ($mail) => $mail->recipientName === $this->inspector->name);
        Mail::assertNotSent(SignedDocumentDistributionMail::class, fn ($mail) => $mail->hasCc(self::TEST_ADDRESS));
    }

    public function test_with_the_inspector_copy_switched_off_the_sending_agent_is_still_cced_as_before(): void
    {
        $inspectorId = $this->inspector->id;
        User::retrieved(function (User $u) use ($inspectorId) {
            if ($u->id === $inspectorId) {
                $u->setAttribute('outward_email', 'inspector@cape.test');
            }
        });
        $this->settings(['report_copy_inspector' => false]);
        $inspection = $this->inspection();
        $this->sign($inspection);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        Mail::assertSent(SignedDocumentDistributionMail::class, fn ($mail) => $mail->recipientName === $this->tenant->full_name && $mail->hasCc(self::TEST_ADDRESS));
    }

    public function test_auto_send_off_still_files_but_sends_nothing_and_the_panel_says_so(): void
    {
        $this->settings(['auto_send_report_enabled' => false]);
        $inspection = $this->inspection();
        $this->sign($inspection);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('documents', ['source_type' => 'rental_inspection_report', 'source_id' => $inspection->id]);
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $inspection))->assertOk()->assertSee('No copies have gone out yet');
    }

    public function test_a_party_without_an_email_shows_as_skipped_with_the_reason_and_the_inspector_is_alerted(): void
    {
        $noEmail = $this->contact('Thandi', 'Mokoena', null);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id]);
        $inspection = $this->inspection();
        $this->sign($inspection, extraTenants: [$noEmail]);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        $skipped = SignedDocumentDistributionLog::where('distributable_id', $inspection->id)->where('status', 'skipped')->first();
        $this->assertNotNull($skipped);
        $this->assertSame($noEmail->id, $skipped->recipient_contact_id);
        $this->assertSame('No email address on file', $skipped->error);

        $alert = $this->inspector->notifications()->where('type', RentalInspectionCopiesNotDelivered::class)->first();
        $this->assertNotNull($alert, 'The inspector (not only the creator) is told.');
        $this->assertSame(1, $alert->data['problem_count']);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $inspection))
            ->assertOk()->assertSee('Thandi Mokoena')->assertSee('Skipped')->assertSee('No email address on file');
    }

    public function test_a_failed_send_is_visible_alerts_the_inspector_and_can_be_resent_to_that_one_recipient(): void
    {
        config(['mail.non_production_redirect' => '']); // outside production with no redirect: every send is suppressed = failed
        $inspection = $this->inspection();
        $this->sign($inspection);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk()
            ->assertJsonPath('status', RentalInspection::STATUS_COMPLETED); // a failed send never undoes the completion

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        Mail::assertNothingSent();
        $this->assertNotNull($this->inspector->notifications()->where('type', RentalInspectionCopiesNotDelivered::class)->first());
        $panel = app(RentalInspectionCopiesService::class)->panelFor($inspection->fresh());
        $this->assertNotEmpty($panel);
        $this->assertSame('failed', collect($panel)->firstWhere('email', 'naledi@example.co.za')['status']);

        config(['mail.non_production_redirect' => self::TEST_ADDRESS]);
        $row = SignedDocumentDistributionLog::where('distributable_id', $inspection->id)->where('recipient_email', 'naledi@example.co.za')->first();

        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => $row->id])
            ->assertRedirect(route('corex.rental-inspections.show', $inspection))->assertSessionHas('success', 'Copy sent.');

        Mail::assertSent(SignedDocumentDistributionMail::class, 1);
        $panel = app(RentalInspectionCopiesService::class)->panelFor($inspection->fresh());
        $this->assertSame('sent', collect($panel)->firstWhere('email', 'naledi@example.co.za')['status'], 'The latest outcome wins: failed, then sent, reads as sent.');
        $this->assertSame('failed', collect($panel)->firstWhere('email', 'pieter@example.co.za')['status'], 'Everyone else is untouched.');
    }

    public function test_resend_recipient_refuses_an_unknown_foreign_or_uncompleted_target_without_sending(): void
    {
        $inspection = $this->inspection();
        $this->sign($inspection);

        // not completed yet
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => 1])->assertSessionHasErrors('copies');

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        Mail::fake();

        $other = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $foreignRow = SignedDocumentDistributionLog::create([
            'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $other->id,
            'channel' => 'email', 'recipient_role' => 'tenant', 'recipient_email' => 'naledi@example.co.za', 'status' => 'failed',
        ]);

        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => $foreignRow->id])->assertSessionHasErrors('copies');
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => 999999])->assertSessionHasErrors('copies');
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), [])->assertSessionHasErrors('log_id');
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => 'x; DROP TABLE'])->assertSessionHasErrors('log_id');
        Mail::assertNothingSent();
    }

    public function test_a_party_who_gets_an_email_address_later_can_be_resent_to(): void
    {
        $noEmail = $this->contact('Thandi', 'Mokoena', null);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id]);
        $inspection = $this->inspection();
        $this->sign($inspection, extraTenants: [$noEmail]);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $skipped = SignedDocumentDistributionLog::where('distributable_id', $inspection->id)->where('status', 'skipped')->first();

        // still no address: refused with a plain message, nothing sent
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => $skipped->id])->assertSessionHasErrors('copies');

        $noEmail->forceFill(['email' => 'thandi@example.co.za'])->save();
        Mail::fake();
        $this->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => $skipped->id])->assertSessionHas('success');

        Mail::assertSent(SignedDocumentDistributionMail::class, 1);
        $latest = collect(app(RentalInspectionCopiesService::class)->panelFor($inspection->fresh()))->firstWhere('party', 'Thandi Mokoena');
        $this->assertSame('sent', $latest['status']);
    }

    public function test_the_tab_resend_endpoint_keeps_its_shape_and_returns_skipped_parties_separately(): void
    {
        $noEmail = $this->contact('Thandi', 'Mokoena', null);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id]);
        $inspection = $this->inspection();
        $this->sign($inspection, extraTenants: [$noEmail]);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        $response = $this->postJson(route('corex.rental-inspections.resend-report', $inspection))->assertOk();

        $this->assertNotEmpty($response->json('results'));
        foreach ($response->json('results') as $r) {
            $this->assertNotSame('', $r['email'], 'The tab lists one line per address — a party without one never appears there.');
        }
        $this->assertCount(1, $response->json('skipped'));
        $this->assertSame('No email address on file', $response->json('skipped.0.error'));
    }

    // ───────────────────────────── interim: all three parties sign ─────────────────────────────

    public function test_an_interim_inspection_needs_the_tenant_the_landlord_and_the_agent_like_in_and_out(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_INTERIM);

        try {
            $inspection->markCompleted();
            $this->fail('An interim inspection must not complete unsigned.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('neither signed nor been marked as refusing', $e->getMessage());
        }

        $this->sign($inspection, tenant: true, landlord: false, agent: false);
        try {
            $inspection->markCompleted();
            $this->fail('The landlord is still outstanding.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('the landlord has neither signed', $e->getMessage());
        }

        $this->sign($inspection, tenant: false, landlord: true, agent: false);
        try {
            $inspection->markCompleted();
            $this->fail('The agent has not signed.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('agent', $e->getMessage());
        }

        $this->sign($inspection, tenant: false, landlord: false, agent: true);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        Mail::assertSent(SignedDocumentDistributionMail::class);
    }

    public function test_an_ad_hoc_check_stays_unsigned_as_today(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC);

        $inspection->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    // ───────────────────────────── settings: save, validate, never wipe ─────────────────────────────

    public function test_the_settings_saver_normalises_the_list_and_clears_it(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $url = route('corex.settings.rental-inspections.report-copies');

        $this->actingAs($admin)->post($url, ['report_agency_copy_emails' => " Rentals@Cape.test ,\naccounts@cape.test;rentals@cape.test", 'report_copy_inspector' => '0', 'report_copy_creator' => '1'])
            ->assertRedirect(route('corex.settings.rental-inspections.edit'))->assertSessionHas('success');

        $this->assertSame(['rentals@cape.test', 'accounts@cape.test'], RentalInspectionSetting::reportAgencyCopyEmailsFor($this->agency->id));
        $this->assertFalse(RentalInspectionSetting::reportCopyInspectorFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::reportCopyCreatorFor($this->agency->id));

        $this->actingAs($admin)->post($url, ['report_agency_copy_emails' => ''])->assertSessionHas('success');
        $this->assertSame([], RentalInspectionSetting::reportAgencyCopyEmailsFor($this->agency->id));
        $this->assertFalse(RentalInspectionSetting::reportCopyInspectorFor($this->agency->id), 'Clearing the list never touches the toggles.');
    }

    public function test_a_bad_address_or_too_many_saves_nothing_and_names_the_problem(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $url = route('corex.settings.rental-inspections.report-copies');
        $this->settings(['report_agency_copy_emails' => 'keep@cape.test']);

        $this->actingAs($admin)->post($url, ['report_agency_copy_emails' => 'good@cape.test, not-an-email, also@bad'])
            ->assertSessionHasErrors('report_agency_copy_emails');
        $this->assertSame(['keep@cape.test'], RentalInspectionSetting::reportAgencyCopyEmailsFor($this->agency->id));

        $many = implode(',', array_map(fn ($i) => "a{$i}@cape.test", range(1, 11)));
        $this->actingAs($admin)->post($url, ['report_agency_copy_emails' => $many])->assertSessionHasErrors('report_agency_copy_emails');

        $this->actingAs($admin)->post($url, [])->assertSessionHasErrors('report_copies'); // nothing posted: refused, not a silent no-op
    }

    public function test_a_wizard_post_that_omits_a_field_never_wipes_it(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->settings(['report_agency_copy_emails' => 'rentals@cape.test', 'report_copy_inspector' => false, 'report_copy_creator' => false]);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.report-copies'), ['report_copy_creator' => '1'])->assertSessionHas('success');

        $this->assertSame(['rentals@cape.test'], RentalInspectionSetting::reportAgencyCopyEmailsFor($this->agency->id));
        $this->assertFalse(RentalInspectionSetting::reportCopyInspectorFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::reportCopyCreatorFor($this->agency->id));
    }

    public function test_defaults_for_an_agency_that_never_configured_anything(): void
    {
        $this->assertSame([], RentalInspectionSetting::reportAgencyCopyEmailsFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::reportCopyInspectorFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::reportCopyCreatorFor($this->agency->id));
        $this->assertSame([], RentalInspectionSetting::reportAgencyCopyEmailsFor(null));
    }

    // ───────────────────────────── scoping ─────────────────────────────

    public function test_another_agencys_user_cannot_resend_to_a_recipient_of_this_inspection(): void
    {
        $inspection = $this->inspection();
        $inspection->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $row = SignedDocumentDistributionLog::create([
            'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $inspection->id,
            'channel' => 'email', 'recipient_role' => 'tenant', 'recipient_contact_id' => $this->tenant->id,
            'recipient_email' => 'naledi@example.co.za', 'status' => 'failed',
        ]);

        // BelongsToAgency forces a new record into the ACTING user's agency, so a user of ANOTHER agency must be
        // created with nobody logged in — otherwise this "stranger" is silently a same-agency colleague and the
        // test proves nothing about agency scoping (it passed that way before: 7 Oct 2026, same blind spot as I-3).
        \Illuminate\Support\Facades\Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'HQ', 'agency_id' => $otherAgency->id]);
        // A non-owner role: the global "admin" role is a platform owner who deliberately sees every agency.
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);
        $this->assertSame($otherAgency->id, $stranger->fresh()->agency_id, 'the stranger really is in another agency');
        $this->assertNotSame($this->agency->id, $stranger->fresh()->agency_id);
        Mail::fake();

        $this->actingAs($stranger)->post(route('corex.rental-inspections.resend-recipient', $inspection), ['log_id' => $row->id])->assertNotFound();
        Mail::assertNothingSent();
    }
}
