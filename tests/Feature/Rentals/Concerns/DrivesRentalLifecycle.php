<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\Concerns;

use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Jobs\FileRentalApplicationDecisionPdfJob;
use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Mail\RentalApplicationApprovedMail;
use App\Mail\RentalApplicationInviteMail;
use App\Mail\RentalApplicationReturnedMail;
use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalInspection;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalFaultProgressService as Progress;
use App\Services\Rentals\RentalMailDispatcher;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The whole rental life, driven through the real endpoints and services - one world, one chain, shared by the lifecycle tests.
 *
 *   rental listing -> application (invite, submit, FICA gate lifts on submitted) -> review -> approve -> lease (owner's agent,
 *   tenant's agent, notice terms) -> e-sign prepared, signed -> lease active, portal logins -> incoming inspection captured,
 *   signed, distributed -> tenant reports a fault on the portal -> agents notified, Command Centre "Review fault" -> agent
 *   prepares the owner version, sends -> owner decides on the Faults screen -> work order -> ... -> closed with who-pays.
 *
 * Every stage method runs its steps through step() (RunsLifecycleSteps) so a break names the step and the lane that owns it.
 * Only the e-sign engine's final PDF (Chromium) is not run: the envelope is completed by announcing it exactly as the engine does.
 * No application code is mocked; mail and notifications are faked and asserted. Every address is the can.assurance mailbox.
 */
trait DrivesRentalLifecycle
{
    use RunsLifecycleSteps;

    protected const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    protected const TENANT_EMAIL = 'can.assurance+tenant@gmail.com';
    protected const OWNER_EMAIL = 'can.assurance+owner@gmail.com';

    protected Agency $agency;
    protected Branch $branch;
    protected Branch $otherBranch;
    protected User $agent;          // creates the application; the property's agent
    protected User $authoriser;     // the application reviewer
    protected User $ownerAgent;     // the lease's owner-side agent
    protected User $tenantAgent;    // the lease's tenant-side agent
    protected Property $property;
    protected Contact $tenant;
    protected Contact $landlord;
    protected RentalLeaseTemplate $agreement;
    protected RentalFaultType $faultType;
    protected RentalCrew $crew;
    protected object $mailer;

    protected ?RentalApplication $application = null;
    protected ?Lease $lease = null;
    protected ?Flow $flow = null;
    protected ?RentalInspection $inspection = null;
    protected ?RentalFaultReport $fault = null;

    /** @var array<int, ClientUser> */
    private array $logins = [];

    // ═════════════════════════════════════ world ═════════════════════════════════════

    protected function buildLifecycleWorld(): void
    {
        Auth::logout();
        AuditContext::reset(); // static actor state must not leak in from an earlier test
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
        Notification::fake();
        Bus::fake([FileRentalApplicationDecisionPdfJob::class]); // the approval PDF is Puppeteer
        config(['mail.non_production_redirect' => 'can.assurance@gmail.com']);
        \App\Models\DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);

        $this->agency = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Coast', 'code' => 'C-' . $this->agency->id, 'is_active' => true]);
        $staff = fn (string $name, string $email) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true, 'name' => $name, 'email' => $email,
        ]);
        $this->agent = $staff('Aileen Agent', 'can.assurance+agent@gmail.com');
        $this->authoriser = $staff('Reggie Reviewer', 'can.assurance+reviewer@gmail.com');
        $this->ownerAgent = $staff('Olive Owner-side', 'can.assurance+oagent@gmail.com');
        $this->tenantAgent = $staff('Tom Tenant-side', 'can.assurance+tagent@gmail.com');
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$this->authoriser->id]])->save();
        \App\Models\RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['identity_gate_enabled' => false]);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'title' => 'Sea view flat',
            'status' => 'active', 'listing_type' => 'rental', 'property_type' => 'apartment', 'suburb' => 'Ramsgate', 'city' => 'Margate',
            'province' => 'KwaZulu-Natal', 'address' => '14 Ocean View Drive', 'rental_amount' => 9000, 'deposit_amount' => 9000,
        ]);
        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => self::TENANT_EMAIL, 'id_number' => '8002025009081', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);
        $this->landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Pieter', 'last_name' => 'van der Merwe',
            'email' => self::OWNER_EMAIL, 'id_number' => '7001015009087', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);
        ContactProperty::create(['contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);

        $template = new Template(['name' => 'Residential lease', 'render_type' => 'pdf', 'is_esign' => true, 'signing_parties' => ['owner_party', 'acquiring_party', 'agent']]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $this->agreement = RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Residential lease', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
            'field_map' => ['rent' => ['field' => 'monthly_rental'], 'start_date' => ['field' => 'lease_start'], 'end_date' => ['field' => 'lease_end'],
                'tenant_name' => ['field' => 'lessee_name'], 'landlord_name' => ['field' => 'lessor_name']],
        ]);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->crew = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team Ramsgate', 'email' => 'can.assurance+crew@gmail.com', 'is_active' => true]);

        // The agency-mailbox mail path (contractor / owner quote / completion check ...) is recorded, not sent.
        $this->mailer = new class extends RentalMailDispatcher {
            /** @var array<int, array{0: ?string, 1: BaseSignatureMail}> */
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }

            public function sentOf(string $mailClass): array
            {
                return array_values(array_filter($this->sent, fn ($m) => $m[1] instanceof $mailClass));
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $this->mailer);
    }

    // ═════════════════════════════ who is calling ═════════════════════════════

    protected function asStaff(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        $this->actingAs($user ?? $this->agent);
    }

    protected function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
    }

    protected function loginOf(Contact $c): ClientUser
    {
        return $this->logins[$c->id] ??= ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
    }

    /** Sign the portal in as the login CoreX itself issued at signing (falls back to creating one so later stages still run). */
    protected function asPortal(Contact $c): void
    {
        $login = ($c->fresh()->client_user_id ? ClientUser::find($c->fresh()->client_user_id) : null) ?? $this->loginOf($c);
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        Sanctum::actingAs($login, ['client']);
    }

    protected function portalGet(string $uri)
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Accept' => 'application/json'])->get('/api/v1/client/rentals' . $uri);
    }

    // ═════════════════════ STAGE 1 - the rental listing ═════════════════════

    protected function driveListing(): void
    {
        $this->step('1.1', 'the rental listing is found by the application picker (agent, scoped search)', 'cc3', function () {
            $this->asStaff();
            $ids = collect($this->getJson(route('corex.rental-applications.search-properties', ['q' => 'Ramsgate']))->assertOk()->json())->pluck('id')->all();
            $this->assertContains($this->property->id, $ids);
        });
        $this->step('1.2', 'the rental property page offers "New rental application"', 'cc3', function () {
            $this->asStaff();
            $this->get(route('corex.properties.show', $this->property))->assertOk()->assertSee('data-test="property-new-application"', false);
        });
    }

    // ═════════════════ STAGE 2 - application, invite, submit, FICA ═════════════════

    protected function driveApplication(): void
    {
        $this->application = $this->step('2.1', 'agent creates the application for the tenant on this property (draft, link minted)', 'cc3', function () {
            $this->asStaff();
            $this->get(route('corex.rental-applications.create', ['property_id' => $this->property->id, 'contact_id' => $this->tenant->id]))
                ->assertOk()->assertViewHas('oldProperty', fn ($p) => $p?->id === $this->property->id);
            $this->post(route('corex.rental-applications.store'), ['contact_id' => $this->tenant->id, 'property_id' => $this->property->id]);
            $app = RentalApplication::where('contact_id', $this->tenant->id)->latest('id')->firstOrFail();
            $this->assertSame('draft', $app->status);
            $this->assertSame($this->property->id, (int) $app->property_id);
            $this->assertNotEmpty($app->token);

            return $app;
        });

        $this->step('2.2', 'agent sends the invite - queued to the tenant, status sent', 'cc3', function () {
            $this->asStaff();
            $this->post(route('corex.rental-applications.send', $this->application))->assertSessionHas('success');
            Mail::assertQueued(RentalApplicationInviteMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL));
            $this->assertSame('sent', $this->application->fresh()->status);
        }, ['2.1']);

        $this->step('2.3', 'applicant opens the public link and autosaves', 'cc3', function () {
            $this->asGuest();
            $token = $this->application->token;
            $this->get(route('rental-applications.public.show', $token))->assertOk();
            $this->postJson(route('rental-applications.public.autosave', $token), ['full_name' => 'Thandi Nkosi', 'id_number' => '8002025009081'])->assertOk()->assertJson(['saved' => true]);
        }, ['2.2']);

        $this->step('2.4', 'applicant submits - returned, signatures stored, FICA hand-off (draft)', 'cc3', function () {
            $this->asGuest();
            $res = $this->post(route('rental-applications.public.submit', $this->application->token), [
                'full_name' => 'Thandi Nkosi', 'id_number' => '8002025009081', 'email' => self::TENANT_EMAIL,
                'current_residential_address' => '1 Example Road, Ramsgate', 'monthly_salary' => 40000, 'rental_term_months' => 12,
                'occupation_date' => '2026-11-01', 'declaration_signature' => self::PNG, 'tpn_consent_signature' => self::PNG,
            ]);
            $this->assertStringContainsString('/fica/', (string) $res->headers->get('Location'));
            $app = $this->application->fresh();
            $this->assertSame('returned', $app->status);
            $this->assertNotNull($app->submitted_at);
            $this->assertSame(2, $app->signatures()->count());
            $this->assertFalse($app->ficaGateDescribe()['open'], 'FICA is not submitted yet, so the gate is closed');
        }, ['2.3']);

        $this->step('2.5', 'the agent is told: returned mail queued; property agent gets the in-app note', 'cc3', function () {
            Mail::assertQueued(RentalApplicationReturnedMail::class, fn ($m) => $m->hasTo($this->agent->email));
            Notification::assertSentTo($this->agent, PillarEventNotification::class, fn ($n) => $n->eventKey === 'rental_application.returned');
        }, ['2.4']);

        $this->step('2.6', 'FICA gate lifts on SUBMITTED (not only on approved)', 'cc3', function () {
            FicaSubmission::where('contact_id', $this->tenant->id)->update(['status' => 'submitted']);
            $this->assertTrue($this->application->fresh()->ficaGateDescribe()['open']);
        }, ['2.4']);
    }

    // ═════════════════════ STAGE 3 - review and approve ═════════════════════

    protected function driveApproval(): void
    {
        $this->step('3.1', 'review screen opens for the agent', 'cc3', function () {
            $this->asStaff();
            $this->get(route('corex.rental-applications.review', $this->application))->assertOk();
        }, ['2.4']);

        $this->step('3.2', 'agent hands the application to the authoriser - under assessment, reviewer notified', 'cc3', function () {
            $this->asStaff();
            $this->postJson(route('corex.rental-applications.review.submit-for-approval', $this->application), ['expected_generation' => 1])->assertOk()->assertJsonPath('ok', true);
            $app = $this->application->fresh();
            $this->assertSame('under_assessment', $app->status);
            $this->assertTrue($app->isPendingAuthorisation());
            Notification::assertSentTo($this->authoriser, PillarEventNotification::class, fn ($n) => $n->eventKey === 'rental_application.handed_over');
        }, ['2.4']);

        $this->step('3.3', 'the authoriser (a different person) approves', 'cc3', function () {
            $this->asStaff($this->authoriser);
            $this->post(route('corex.rental-applications.authorisation.approve', $this->application), ['approved_rental_amount' => '10000', 'approved_deposit_amount' => '10000'])
                ->assertSessionDoesntHaveErrors();
            $this->assertSame('approved', $this->application->fresh()->status);
        }, ['3.2']);

        $this->step('3.4', 'agent sends the approval - queued to the applicant, once', 'cc3', function () {
            $this->asStaff();
            $this->post(route('corex.rental-applications.review.send', $this->application))->assertSessionHas('success');
            Mail::assertQueued(RentalApplicationApprovedMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL));
            $this->assertNotNull($this->application->fresh()->applicant_notified_at);
        }, ['3.3']);
    }

    // ═════════ STAGE 4 - the lease, with both agents and the notice terms ═════════

    protected function driveLeaseCreation(): void
    {
        $this->step('4.1', 'the lease is created from the approved application, prepared for signing (button b)', 'cc1', function () {
            $this->asStaff();
            $res = $this->post(route('corex.leases.store'), [
                'intent' => 'lease_and_sign', 'agreement_id' => $this->agreement->id, 'property_id' => $this->property->id,
                'rental_application_id' => $this->application->id, 'tenant_contact_ids' => [$this->tenant->id],
                'rental_amount' => '9000', 'deposit_amount' => '9000', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
                'capture_key' => bin2hex(random_bytes(8)),
                'owner_agent_user_id' => $this->ownerAgent->id, 'tenant_agent_user_id' => $this->tenantAgent->id,
                'notice' => ['notice_period' => '60', 'notice_period_unit' => 'days', 'earliest_notice_date' => '2027-02-01', 'earliest_termination_date' => '2027-04-30',
                    'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => '20', 'early_cancellation_notice_unit' => 'days',
                    'early_cancellation_penalty' => 'Two months rent'],
            ]);
            $res->assertSessionHasNoErrors();
            $this->lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->firstOrFail();
            $this->flow = Flow::withoutGlobalScopes()->where('lease_id', $this->lease->id)->firstOrFail();
            $res->assertRedirect(route('docuperfect.esign.step', ['flow' => $this->flow->id, 'step' => 5]));
            $this->assertSame(Lease::STATUS_DRAFT, $this->lease->status);
            $this->assertSame(Lease::SIGNING_PREPARED, $this->lease->signing_status);
        }, ['3.4']);

        $this->step('4.2', "the lease carries the owner's agent and the tenant's agent", 'cc1', function () {
            $lease = $this->lease->fresh();
            $this->assertSame($this->ownerAgent->id, (int) $lease->owner_agent_user_id);
            $this->assertSame($this->tenantAgent->id, (int) $lease->tenant_agent_user_id);
        }, ['4.1']);

        $this->step('4.3', 'the notice terms were captured on the lease agreement terms', 'cc1', function () {
            $terms = LeaseAgreementTerms::withoutGlobalScopes()->where('lease_id', $this->lease->id)->firstOrFail();
            $this->assertSame(60, (int) $terms->notice_period);
            $this->assertSame('days', $terms->notice_period_unit);
            $this->assertSame('2027-02-01', $terms->earliest_notice_date->toDateString());
            $this->assertSame('2027-04-30', $terms->earliest_termination_date->toDateString());
            $this->assertSame('captured', $terms->notice_terms_source);
        }, ['4.1']);

        $this->step('4.4', 'the application is linked to its lease and the lease screen opens', 'cc1', function () {
            $this->asStaff();
            $this->get(route('corex.leases.show', $this->lease))->assertOk();
            $this->assertSame($this->application->id, (int) $this->lease->fresh()->rental_application_id);
        }, ['4.1']);
    }

    // ═════════ STAGE 5 - e-sign prepared, all parties sign, lease active, portal logins ═════════

    protected function driveSigning(): void
    {
        $this->step('5.1', 'e-sign: the prepared flow is turned into a signing envelope over HTTP (prepareSigning)', 'cc1', function () {
            $this->asStaff();
            $this->postJson(route('docuperfect.esign.prepareSigning', $this->flow), [])->assertSuccessful();
            $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $this->lease->fresh()->signing_status, 'the lease follows its envelope out for signing');
        }, ['4.1']);

        // If the real prepare step could not run, link an envelope the way the engine's hook does so the rest of the chain still runs.
        $this->step('5.2', 'the lease is out for signing (real hook: LeaseSigningLauncher::linkEnvelope)', 'cc1', function () {
            if ($this->lease->fresh()->signing_status !== Lease::SIGNING_OUT_FOR_SIGNING) {
                $document = Document::create([
                    'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
                    'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                    'web_template_data' => ['canonical_html' => '<div><span data-field="monthly_rental">9000.00</span><span data-field="lease_start">2026-11-01</span>'
                        . '<span data-field="lease_end">2027-10-31</span><span data-field="lessee_name">Thandi Nkosi</span><span data-field="lessor_name">Pieter van der Merwe</span></div>'],
                ]);
                $envelope = SignatureTemplate::create([
                    'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
                    'status' => SignatureTemplate::STATUS_AWAITING_TENANT, 'created_by' => $this->agent->id,
                ]);
                app(LeaseSigningLauncher::class)->linkEnvelope($this->flow->fresh(), $envelope, $document);
            }
            $lease = $this->lease->fresh();
            $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->signing_status);
            $this->assertNotNull($lease->signature_template_id);
            $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'not live while the agreement is out');
        }, ['4.1']);

        $this->step('5.3', 'manual Activate is refused while the agreement is out for signing', 'cc1', function () {
            $this->asStaff();
            $this->post(route('corex.leases.activate', $this->lease))->assertSessionHasErrors('lease');
            $this->assertSame(Lease::STATUS_DRAFT, $this->lease->fresh()->status);
        }, ['5.2']);

        $this->step('5.4', 'all parties sign - the envelope completes as the engine announces it; lease signed and ACTIVE', 'cc1', function () {
            $lease = $this->lease->fresh();
            // The fill step of the wizard (which this test does not click through) is what prints the lease's values into the
            // agreement; write exactly those printed values so the signed document and the lease agree, as they do for a real agency.
            $document = Document::withoutGlobalScopes()->findOrFail($lease->agreement_document_id);
            $data = (array) ($document->web_template_data ?? []);
            $data['canonical_html'] = '<div><span data-field="monthly_rental">9000.00</span><span data-field="lease_start">2026-11-01</span>'
                . '<span data-field="lease_end">2027-10-31</span><span data-field="lessee_name">Thandi Nkosi</span><span data-field="lessor_name">Pieter van der Merwe</span></div>';
            $document->forceFill(['web_template_data' => $data])->save();
            $envelope = SignatureTemplate::withoutGlobalScopes()->findOrFail($lease->signature_template_id);
            $envelope->update(['status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(), 'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED]);
            event(new SignatureEnvelopeFinalized($envelope->id, (int) $envelope->document_id, $this->agency->id));
            $lease = $this->lease->fresh();
            $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
            $events = \App\Models\LeaseEvent::where('lease_id', $lease->id)->orderBy('id')->pluck('event_type')->implode(', ');
            $this->assertSame(Lease::STATUS_ACTIVE, $lease->status, "a signed agreement that matches its lease goes live (lease is {$lease->status}/{$lease->signing_status}; events: {$events})");
            $this->assertNotNull($lease->signed_at);
        }, ['5.2']);

        $this->step('5.5', 'portal logins were issued to the tenant and the owner', 'cc6', function () {
            $this->assertNotNull($this->tenant->fresh()->client_user_id);
            $this->assertNotNull($this->landlord->fresh()->client_user_id);
            $this->assertSame($this->agency->id, (int) ClientUser::findOrFail($this->tenant->fresh()->client_user_id)->current_agency_id);
        }, ['5.4']);

        $this->step('5.6', 'the tenant sees the live lease on the portal; the owner sees the property', 'cc6', function () {
            $this->asPortal($this->tenant);
            $leases = $this->portalGet('/leases')->assertOk()->json('leases');
            $this->assertContains($this->lease->id, array_column($leases, 'id'));
            $this->asPortal($this->landlord);
            $props = $this->withHeaders(['Origin' => 'http://localhost', 'Accept' => 'application/json'])->get('/api/v1/client/rentals/landlord/properties')->assertOk()->json();
            $this->assertStringContainsString((string) $this->property->id, json_encode($props));
        }, ['5.5']);
    }

    // ═════════ STAGE 6 - the incoming inspection: captured, signed, distributed ═════════

    protected function driveIncomingInspection(): void
    {
        $this->step('6.1', 'agent starts the incoming (move-in) inspection on the live lease', 'cc4', function () {
            $this->asStaff();
            $this->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'in'])->assertSessionHasNoErrors();
            $this->inspection = RentalInspection::withoutGlobalScopes()->where('lease_id', $this->lease->id)->where('type', 'in')->firstOrFail();
            $this->assertSame($this->lease->id, (int) $this->inspection->lease_id);
        }, ['5.4']);

        $this->step('6.2', 'rooms and items are captured and every item graded', 'cc4', function () {
            $this->asStaff();
            $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), ['kind' => 'space', 'label' => 'Bedroom 1', 'space_type' => 'Bedroom'])->assertSuccessful();
            $this->postJson(route('corex.rental-inspections.mark-all-good', $this->inspection))->assertOk();
        }, ['6.1']);

        $this->step('6.3', 'attendance of every party is recorded (tenant, landlord, agent)', 'cc4', function () {
            $this->asStaff();
            $this->postJson(route('corex.rental-inspections.attendance.store', $this->inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id, 'outcome' => 'attended', 'attended_as' => 'self'])->assertStatus(201);
            $this->postJson(route('corex.rental-inspections.attendance.store', $this->inspection), ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id, 'outcome' => 'attended', 'attended_as' => 'self'])->assertStatus(201);
            $this->postJson(route('corex.rental-inspections.attendance.store', $this->inspection), ['party_role' => 'agent', 'party_user_id' => $this->agent->id, 'outcome' => 'attended'])->assertStatus(201);
        }, ['6.2']);

        $this->step('6.4', 'the signing window opens', 'cc4', function () {
            $this->asStaff();
            $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $this->inspection))->assertOk();
            $this->assertSame('awaiting_signature', $this->inspection->fresh()->status);
        }, ['6.3']);

        $this->step('6.5', 'the tenant signs by personal link; the landlord and then the agent sign in CoreX', 'cc4', function () {
            $this->asStaff();
            $linkId = $this->postJson(route('corex.rental-inspections.signing-links.issue', $this->inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id])
                ->assertStatus(201)->json('link_id');
            $token = \App\Models\RentalInspectionSigningLink::findOrFail($linkId)->token;
            $this->asGuest();
            $this->postJson(route('rental-inspections.sign.submit', $token), ['action' => 'sign', 'typed_name' => 'Thandi Nkosi', 'signature_image' => self::PNG, 'read_confirmed' => true, 'comment' => null])->assertStatus(201);
            $this->asStaff();
            $this->postJson(route('corex.rental-inspections.signatures.store', $this->inspection), ['party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG])->assertStatus(201);
            $this->postJson(route('corex.rental-inspections.signatures.store', $this->inspection), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertStatus(201);
        }, ['6.4']);

        $this->step('6.6', 'the inspection is completed', 'cc4', function () {
            $this->asStaff();
            $this->postJson(route('corex.rental-inspections.complete', $this->inspection))->assertOk();
            $this->assertSame('completed', $this->inspection->fresh()->status);
        }, ['6.5']);

        $this->step('6.7', 'the signed report is filed and distributed to the tenant and the owner', 'cc4', function () {
            $this->assertDatabaseHas('documents', ['source_type' => 'rental_inspection_report', 'source_id' => $this->inspection->id]);
            Mail::assertSent(SignedDocumentDistributionMail::class, fn ($m) => $m->recipientName === $this->tenant->fresh()->full_name);
            Mail::assertSent(SignedDocumentDistributionMail::class, fn ($m) => $m->recipientName === $this->landlord->fresh()->full_name);
        }, ['6.6']);

        $this->step('6.8', 'the tenant sees the completed inspection on the portal; a stranger would not', 'cc4', function () {
            $this->asPortal($this->tenant);
            $ids = array_column($this->portalGet('/inspections')->assertOk()->json('inspections'), 'id');
            $this->assertContains($this->inspection->id, $ids);
        }, ['6.6', '5.5']);
    }

    // ═════════ STAGE 7 - the tenant reports a fault; the agents review and send it ═════════

    protected function driveFaultToOwner(): void
    {
        $this->fault = $this->step('7.1', 'tenant reports a fault on the portal (201, status reported)', 'cc6', function () {
            $this->asPortal($this->tenant);
            $res = $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", [
                'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Kitchen tap leaking', 'description' => 'Drips all night',
            ], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertStatus(201);
            $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));
            $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->status);
            $this->assertSame($this->lease->id, (int) $fault->lease_id);

            return $fault;
        }, ['5.5']);

        $this->step('7.2', "the lease's owner-side and tenant-side agents are told; the owner is not (yet)", 'cc6', function () {
            foreach ([$this->ownerAgent, $this->tenantAgent] as $agent) {
                Notification::assertSentTo($agent, PillarEventNotification::class, fn ($n) => $n->eventKey === 'rental_fault_report.created');
            }
            Mail::assertNotQueued(RentalLandlordDecisionNeededMail::class);
            $this->asPortal($this->landlord);
            $this->assertCount(0, $this->portalGet('/landlord/fault-reports')->assertOk()->json('fault_reports'));
        }, ['7.1']);

        $this->step('7.3', 'the Command Centre shows "Review fault" (service and page)', 'cc6', function () {
            $this->asStaff();
            $items = app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_to_review');
            $this->assertSame($this->fault->id, (int) $items->first()['route_params']['rentalFaultReport']);
            $this->assertSame('Review fault', $items->first()['label']);
            $this->get(route('corex.rentals.command-centre.index'))->assertOk()->assertSee('Review fault')->assertSee('Kitchen tap leaking');
        }, ['7.1']);

        $this->step('7.4', "agent prepares the owner's version and sends it - owner and tenant mails queued, line moves", 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.owner-version.store', $this->fault), [
                'owner_title' => 'Leaking kitchen tap', 'owner_description' => 'The kitchen tap drips.', 'owner_agent_note' => 'I recommend repair.',
            ])->assertRedirect();
            $this->assertSame('agent_reviewing', $this->progress('tenant')['current']);
            $this->post(route('corex.rental-fault-reports.send-to-owner', $this->fault))->assertRedirect();
            $this->assertSame(RentalFaultReport::STATUS_AWAITING_APPROVAL, $this->fault->fresh()->status);
            Mail::assertQueued(RentalLandlordDecisionNeededMail::class, fn ($m) => $m->hasTo(self::OWNER_EMAIL));
            Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL) && $m->stepLabel === 'Sent to owner for approval');
            $this->assertSame('sent_to_owner', $this->progress('tenant')['current']);
        }, ['7.1']);

        $this->step('7.5', 'the owner sees it on the Faults screen with the agent\'s version, never the tenant\'s raw words', 'cc6', function () {
            $this->asPortal($this->landlord);
            $row = collect($this->portalGet('/landlord/fault-reports')->assertOk()->json('fault_reports'))->firstWhere('id', $this->fault->id);
            $this->assertNotNull($row);
            $this->assertSame('Leaking kitchen tap', $row['title']);
            $this->assertTrue((bool) $row['needs_decision']);
            $this->assertStringNotContainsString('Drips all night', json_encode($this->portalGet("/landlord/fault-reports/{$this->fault->id}")->assertOk()->json()));
        }, ['7.4']);
    }

    /** A supplier on the owner's per-trade list (Plumbing), as the owner is offered it on the Faults screen. */
    protected function makeSupplierOnTheOwnersList(): AgencyServiceProvider
    {
        $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(['agency_id' => $this->agency->id, 'code' => 'Plumbing'], ['label' => 'Plumbing', 'sort_order' => 1, 'is_active' => true]);
        $supplier = AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Ramsgate Plumbing', 'phone' => '0315550000', 'email' => 'can.assurance+plumber@gmail.com',
            'is_active' => true, 'specialty' => 'other', 'created_by_id' => $this->agent->id,
        ]);
        AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'service_provider_id' => $supplier->id, 'service_type' => $type->code]);

        return $supplier;
    }

    protected function progress(string $audience): array
    {
        return app(Progress::class)->forFault($this->fault->fresh(), $audience);
    }

    // ═════════ STAGE 8/9 - the owner decides; three routes to a closed job ═════════

    /** @param array<string, mixed> $decision */
    protected function ownerDecides(array $decision, string $stepId = '8.1', string $label = 'the owner approves on the Faults screen'): void
    {
        $this->step($stepId, $label, 'cc6', function () use ($decision) {
            $this->asPortal($this->landlord);
            $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}/decision", $decision, ['X-Submission-Key' => 'e2e-' . uniqid()])->assertOk();
            $this->assertSame('owner_decided', $this->progress('tenant')['current']);
            Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL) && $m->stepLabel === 'Owner approved');
        }, ['7.4']);
    }

    /** Appointment -> tenant mailed -> line moves -> started. Returns nothing; asserts the line at each step. */
    protected function appointmentAndStart(?\App\Models\RentalWorkOrder $wo, string $who, string $idPrefix, string $ready = '9.0'): void
    {
        $this->step("{$idPrefix}.a", 'appointment set - tenant notified, progress line shows it', 'cc6', function () use ($wo, $who) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.appointment.store', $wo), [
                'appointment_at' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'), 'appointment_note' => 'Gate code 1234',
            ])->assertSessionHasNoErrors();
            Mail::assertQueued(\App\Mail\Rentals\RentalWorkOrderAppointmentMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL) && $m->changed === false);
            $line = $this->progress('tenant');
            $this->assertSame('appointment_set', $line['current']);
            $this->assertStringContainsString($who, collect($line['steps'])->firstWhere('key', 'appointment_set')['detail']);
        }, [$ready]);

        $this->step("{$idPrefix}.b", 'work started - line shows work in progress for tenant and owner', 'cc6', function () use ($wo) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.start-progress', $wo))->assertSessionHasNoErrors();
            $this->assertSame('in_progress', $this->progress('tenant')['current']);
            $this->assertSame('in_progress', $this->progress('owner')['current']);
        }, ["{$idPrefix}.a"]);
    }

    /** Tenant confirms the work is done (portal), the agent closes with who-pays, the fault is resolved. */
    protected function completeConfirmAndClose(?\App\Models\RentalWorkOrder $wo, string $idPrefix, string $paidBy, bool $internal): void
    {
        $this->step("{$idPrefix}.c", 'work reported complete - the tenant is asked (optionally) to check; nothing is held ("Work reported finished" until the agent closes)', 'cc6', function () use ($wo, $internal) {
            $this->asStaff();
            if ($internal) {
                $card = $wo->fresh()->jobCard;
                $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->agent)['raw_token'];
                $this->asGuest();
                $this->post("/secure/job-cards/{$token}/complete", ['full_name' => 'Crew Chief', 'confirm' => '1'])->assertSessionHasNoErrors();
            } else {
                $this->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'note' => 'Done', 'date_done' => now()->toDateString()])->assertSessionHasNoErrors();
            }
            $this->assertSame('Work reported finished', $this->progress('tenant')['current_label'], 'T1 (9 Oct 2026): no tenant hold');
            $this->assertCount(1, $this->mailer->sentOf(\App\Mail\Rentals\RentalTenantCompletionCheckMail::class));
        }, ["{$idPrefix}.b"]);

        $this->step("{$idPrefix}.d", 'the tenant confirms the repair is fixed on the portal (optional)', 'cc6', function () use ($wo) {
            $this->asPortal($this->tenant);
            $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => true], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertSuccessful();
        }, ["{$idPrefix}.c"]);

        $this->step("{$idPrefix}.e", "the job is closed with who-pays; the tenant's line reads completed", 'cc6', function () use ($wo, $paidBy, $internal) {
            $this->asStaff();
            if ($internal) {
                $card = $wo->fresh()->jobCard;
                $this->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
                $this->post(route('corex.rental-job-cards.complete', $card), ['paid_by' => $paidBy])->assertSessionHasNoErrors();
            } else {
                $this->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => $paidBy, 'cost_amount' => 450, 'completion_notes' => 'Tap replaced'])->assertSessionHasNoErrors();
            }
            $fresh = $wo->fresh();
            $this->assertSame(\App\Models\RentalWorkOrder::STATUS_COMPLETED, $fresh->status);
            $this->assertSame($paidBy, $fresh->paid_by);
            $this->assertSame('completed', $this->progress('tenant')['current']);
        }, ["{$idPrefix}.d"]);

        $this->step("{$idPrefix}.f", 'the agent records the outcome - the fault is resolved', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.outcome.store', $this->fault), ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertSessionHasNoErrors();
            $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        }, ["{$idPrefix}.e"]);
    }

    /** What the tenant must never learn on the internal-crew route, asserted on every tenant surface. */
    protected function assertTenantAndOwnerNeverSeeTheJobCard(): void
    {
        $this->asPortal($this->tenant);
        $this->getJson('/api/v1/client/rentals/job-cards')->assertNotFound();
        $surfaces = [];
        foreach (['/work-orders', "/fault-reports/{$this->fault->id}", '/fault-reports'] as $uri) {
            $surfaces['tenant ' . $uri] = $this->portalGet($uri)->getContent();
        }
        $this->asPortal($this->landlord);
        $this->getJson('/api/v1/client/rentals/landlord/job-cards')->assertNotFound();
        foreach (['/landlord/work-orders', "/landlord/fault-reports/{$this->fault->id}"] as $uri) {
            $surfaces['owner ' . $uri] = $this->portalGet($uri)->getContent();
        }
        $leaks = [];
        foreach ($surfaces as $where => $body) {
            foreach (['job_card', 'jobCard', 'crew_completion', 'unit_cost', 'cost_total', 'markup', 'margin', 'Crew Chief'] as $needle) {
                if (str_contains($body, $needle)) {
                    $leaks[] = "'{$needle}' in {$where}";
                }
            }
        }
        $this->assertSame([], $leaks, 'internal-crew details leaked to a portal surface: ' . implode('; ', $leaks));
    }

    // ═══════════════════ the whole front of the chain, in one call ═══════════════════

    protected function driveToActiveLeaseWithInspection(): void
    {
        $this->driveListing();
        $this->driveApplication();
        $this->driveApproval();
        $this->driveLeaseCreation();
        $this->driveSigning();
        $this->driveIncomingInspection();
        $this->driveFaultToOwner();
    }
}
