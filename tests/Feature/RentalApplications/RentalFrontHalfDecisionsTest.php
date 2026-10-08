<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\RentalApplicationDeclineEmailSetting;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationStatusHistory;
use App\Models\RentalSettingAuditEntry;
use App\Models\User;
use App\Services\RentalApplications\RentalApplicationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals front-half decisions (8 Oct 2026) - the application side. Johan was away, so each recommended default was built as a
 * per-agency setting (default = the recommended value), audited, with the old behaviour one switch away. One test group per decision:
 *  D1 hand the link over yourself (no-email tenant)      D2 "New application" from a property / contact
 *  D3 in-app note to the property's and lease's agents    D4 the authoriser is told at hand-over
 *  D5 the agency's own invite sentence + the property     D6 decline wording reachable from the Setup Wizard
 *  D14 lease dates suggested; "Create lease" on the row   D15 withdraw an approved application that has no lease
 * All mail in this file is faked - nothing leaves the test process.
 */
final class RentalFrontHalfDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;
    private User $otherAgent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        \App\Support\Audit\AuditContext::reset(); // static actor state must not leak in from an earlier test
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->otherAgent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'rental_amount' => 9000,
        ]);
    }

    private function application(string $status, array $extra = [], bool $withEmail = true): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => $withEmail ? 'can.assurance@gmail.com' : null, 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id, 'created_by_user_id' => $this->agent->id,
            'status' => $status, 'full_name' => 'Thandi Nkosi', 'email' => $withEmail ? 'can.assurance@gmail.com' : null,
            'token' => bin2hex(random_bytes(16)), 'token_expires_at' => now()->addDays(7),
        ]);
    }

    private function setting(array $values): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], $values);
    }

    // ── D1 - hand the link over yourself ──────────────────────────────────

    public function test_the_agent_can_hand_a_no_email_tenant_their_link_and_the_link_then_works(): void
    {
        $app = $this->application('draft', [], withEmail: false);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.share-link', $app))->assertSessionHas('success');

        $fresh = $app->fresh();
        $this->assertSame('sent', $fresh->status);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertTrue(RentalApplicationStatusHistory::where('rental_application_id', $app->id)->where('to_status', 'sent')->where('changed_by_user_id', $this->agent->id)->exists(), 'who handed it over is on the history');
        $this->assertTrue(RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'link_handed_over')->exists(), 'and in the audit');

        $this->get(route('rental-applications.public.show', $app->token))->assertOk();
        $this->assertStringNotContainsString('no longer available', strtolower($this->get(route('rental-applications.public.show', $app->token))->getContent()));
    }

    public function test_handing_over_renews_an_expired_link_and_is_off_when_the_agency_switches_it_off(): void
    {
        $expired = $this->application('sent', ['token_expires_at' => now()->subDays(3)], withEmail: false);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.share-link', $expired))->assertSessionHas('success');
        $this->assertTrue($expired->fresh()->token_expires_at->isFuture());

        $this->setting(['allow_manual_link_share' => false]);
        $draft = $this->application('draft', [], withEmail: false);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.share-link', $draft))->assertSessionHas('error');
        $this->assertSame('draft', $draft->fresh()->status);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $draft))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="share-link-form"', $html);
    }

    public function test_handing_over_is_refused_for_a_closed_application_and_for_another_agencys_agent(): void
    {
        $declined = $this->application('declined');
        $this->actingAs($this->agent)->post(route('corex.rental-applications.share-link', $declined))->assertSessionHas('error');

        $otherAgency = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'agent', 'is_active' => true]);
        $draft = $this->application('draft');
        $this->assertNotContains($this->actingAs($stranger)->post(route('corex.rental-applications.share-link', $draft))->getStatusCode(), [200]);
        $this->assertSame('draft', $draft->fresh()->status, "another agency's user cannot touch this application");
    }

    // ── D2 - start an application from the property or the contact ────────

    public function test_new_application_opens_pre_filled_from_a_property_and_from_a_contact(): void
    {
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sipho', 'last_name' => 'Dlamini', 'email' => 'can.assurance@gmail.com']);

        $this->actingAs($this->admin)->get(route('corex.rental-applications.create', ['property_id' => $this->property->id, 'contact_id' => $contact->id]))
            ->assertOk()
            ->assertViewHas('oldProperty', fn ($p) => $p?->id === $this->property->id)
            ->assertViewHas('oldContact', fn ($c) => $c?->id === $contact->id);
    }

    public function test_a_property_or_contact_the_user_may_not_see_pre_fills_nothing(): void
    {
        $otherAgency = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $foreignProperty = Property::create(['agency_id' => $otherAgency->id, 'agent_id' => $this->otherAgent->id, 'title' => 'Not yours', 'status' => 'active', 'listing_type' => 'rental']);
        $foreignContact = Contact::create(['agency_id' => $otherAgency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Zed', 'last_name' => 'Foreign', 'email' => 'zed@example.test']);
        $sale = Property::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->otherAgent->id, 'title' => 'For sale', 'status' => 'active', 'listing_type' => 'sale']);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-applications.create', [
            'property_id' => $foreignProperty->id, 'contact_id' => $foreignContact->id,
        ]))->assertOk()
            ->assertViewHas('oldProperty', null)
            ->assertViewHas('oldContact', null)
            ->getContent();
        $this->assertStringNotContainsString('Foreign', $html);

        $this->actingAs($this->admin)->get(route('corex.rental-applications.create', ['property_id' => $sale->id]))->assertOk()
            ->assertViewHas('oldProperty', null); // a sale listing is not a rental to apply for
    }

    public function test_the_property_page_and_the_contact_tab_offer_new_application_to_someone_who_may_create_one(): void
    {
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sipho', 'last_name' => 'Dlamini', 'email' => 'can.assurance@gmail.com']);

        $property = $this->actingAs($this->admin)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();
        $this->assertStringContainsString('data-test="property-new-application"', $property);
        $this->assertStringContainsString(route('corex.rental-applications.create', ['property_id' => $this->property->id]), html_entity_decode($property));

        $tab = $this->actingAs($this->admin)->get(route('corex.contacts.show', ['contact' => $contact->id, 'tab' => 'rental']))->assertOk()->getContent();
        $this->assertStringContainsString('data-test="contact-new-application"', $tab);
    }

    // ── D3 - in-app note to the property's and lease's agents ─────────────

    public function test_when_a_tenant_submits_the_creating_agent_and_the_property_agent_are_told_in_the_app(): void
    {
        $app = $this->application('returned', ['property_id' => $this->property->id, 'submitted_at' => now()]);

        $reached = app(RentalApplicationNotifier::class)->notifyAgentsInApp($app->fresh());

        $this->assertSame(2, $reached);
        $this->assertSame(1, $this->agent->fresh()->notifications()->count(), 'the agent who created it');
        $this->assertSame(1, $this->otherAgent->fresh()->notifications()->count(), "the property's agent");
        $this->assertTrue(RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'agents_notified_on_return')->exists());
    }

    public function test_the_lease_agents_are_told_when_the_property_is_let_and_the_agency_can_switch_it_off(): void
    {
        $leaseOwnerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'source' => 'manual',
            'owner_agent_user_id' => $leaseOwnerAgent->id, 'tenant_agent_user_id' => $this->otherAgent->id,
        ]);
        $app = $this->application('returned', ['property_id' => $this->property->id, 'submitted_at' => now()]);

        app(RentalApplicationNotifier::class)->notifyAgentsInApp($app->fresh());
        $this->assertSame(1, $leaseOwnerAgent->fresh()->notifications()->count(), "the lease's owner-side agent");

        $this->setting(['notify_agents_on_application_returned' => false]);
        $before = $this->otherAgent->fresh()->notifications()->count();
        $this->assertSame(0, app(RentalApplicationNotifier::class)->notifyAgentsInApp($app->fresh()));
        $this->assertSame($before, $this->otherAgent->fresh()->notifications()->count());
    }

    // ── D4 - the authoriser is told at hand-over ──────────────────────────

    public function test_handing_an_application_to_the_authoriser_notifies_the_authoriser_and_not_the_agent_who_did_it(): void
    {
        $authoriser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$authoriser->id, $this->agent->id]])->save();
        $app = $this->application('returned', ['submitted_at' => now()]);

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.submit-for-approval', $app))->assertOk();

        $this->assertSame('under_assessment', $app->fresh()->status);
        $this->assertSame(1, $authoriser->fresh()->notifications()->count());
        $this->assertSame(0, $this->agent->fresh()->notifications()->count(), 'the person who handed it over is not told what they just did');
        $this->assertTrue(RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'authorisers_notified_on_hand_over')->exists());
    }

    public function test_the_hand_over_notification_is_off_when_the_agency_says_so_and_never_blocks_the_hand_over(): void
    {
        $authoriser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$authoriser->id]])->save();
        $this->setting(['notify_authoriser_on_hand_over' => false]);
        $app = $this->application('returned', ['submitted_at' => now()]);

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.submit-for-approval', $app))->assertOk();

        $this->assertSame('under_assessment', $app->fresh()->status);
        $this->assertSame(0, $authoriser->fresh()->notifications()->count());
    }

    // ── D5 - the agency's own sentence in the invite and the PDF ──────────

    public function test_the_invite_carries_the_agencys_own_sentence_and_the_property_and_nothing_when_there_is_none(): void
    {
        $this->setting(['invite_policy_sentence' => '{agency} only considers applicants who have been pre-approved.']);
        $app = $this->application('draft', ['property_id' => $this->property->id]);
        $this->property->update(['street_name' => 'Marine Drive', 'street_number' => '12']);

        $html = (new RentalApplicationInviteMail($app->fresh()->load(['contact', 'agency', 'property'])))->render();
        $this->assertStringContainsString('Cape Rentals only considers applicants who have been pre-approved.', $html);
        $this->assertStringContainsString('Thank you for your interest in', $html);

        $this->setting(['invite_policy_sentence' => '']);
        $html = (new RentalApplicationInviteMail($app->fresh()->load(['contact', 'agency', 'property'])))->render();
        $this->assertStringNotContainsString('pre-approved', $html);
        $this->assertStringNotContainsString('pre-approval', $html);
    }

    public function test_a_new_agency_has_no_policy_sentence_by_default_and_the_placeholder_names_the_agency(): void
    {
        $this->assertSame('', RentalApplicationQualifyingSetting::invitePolicySentenceFor($this->agency->id));
        $this->setting(['invite_policy_sentence' => '{agency} screens every tenant.']);
        $this->assertSame('Cape Rentals screens every tenant.', RentalApplicationQualifyingSetting::renderedInvitePolicySentenceFor($this->agency->id, 'Cape Rentals'));
    }

    // ── the settings: saved, audited, has()-guarded, permissioned ─────────

    public function test_the_front_half_settings_save_audit_and_never_reset_a_field_that_was_not_posted(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-applications.front-half'), [
            'allow_manual_link_share' => '0', 'invite_policy_sentence' => '  {agency} is careful.  ',
        ])->assertSessionHas('success');

        $this->assertFalse(RentalApplicationQualifyingSetting::allowManualLinkShareFor($this->agency->id));
        $this->assertSame('{agency} is careful.', RentalApplicationQualifyingSetting::invitePolicySentenceFor($this->agency->id));
        $this->assertTrue(RentalApplicationQualifyingSetting::notifyAuthoriserOnHandOverFor($this->agency->id), 'a field that was not posted keeps its value');

        $this->actingAs($this->admin)->post(route('corex.settings.rental-applications.front-half'), ['notify_authoriser_on_hand_over' => '0'])->assertSessionHas('success');
        $this->assertFalse(RentalApplicationQualifyingSetting::allowManualLinkShareFor($this->agency->id), 'the earlier switch is still off');
        $this->assertSame('{agency} is careful.', RentalApplicationQualifyingSetting::invitePolicySentenceFor($this->agency->id), 'and the sentence was not wiped');

        $audit = RentalSettingAuditEntry::where('agency_id', $this->agency->id)->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['allow_manual_link_share', 'invite_policy_sentence', 'notify_authoriser_on_hand_over'], $audit->pluck('setting_key')->all());
        $first = $audit->firstWhere('setting_key', 'allow_manual_link_share');
        $this->assertSame('on', $first->old_value);
        $this->assertSame('off', $first->new_value);
        $this->assertSame($this->admin->id, $first->user_id);
        $this->assertSame('settings', $first->source);

        // The same value again writes no second audit row.
        $this->actingAs($this->admin)->post(route('corex.settings.rental-applications.front-half'), ['allow_manual_link_share' => '0']);
        $this->assertSame(3, RentalSettingAuditEntry::where('agency_id', $this->agency->id)->count());
    }

    /** Real grants (an empty grants table permits everything in the test database): the agent role may apply and view, nothing more. */
    private function giveAgentRoleOnlyApplicationCreate(): void
    {
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach (['rental_applications.create', 'rental_applications.view', 'properties.view'] as $key) {
            \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'agency']);
        }
        \App\Services\PermissionService::clearCache();
    }

    public function test_an_agent_without_the_settings_permission_cannot_change_them(): void
    {
        $this->giveAgentRoleOnlyApplicationCreate();
        $this->assertNotContains($this->actingAs($this->agent)->post(route('corex.settings.rental-applications.front-half'), ['allow_manual_link_share' => '0'])->getStatusCode(), [200]);

        $this->assertTrue(RentalApplicationQualifyingSetting::allowManualLinkShareFor($this->agency->id));
        $this->assertSame(0, RentalSettingAuditEntry::count());
    }

    public function test_another_agencys_settings_are_untouched_by_this_agencys_change(): void
    {
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $this->actingAs($this->admin)->post(route('corex.settings.rental-applications.front-half'), ['allow_manual_link_share' => '0'])->assertSessionHas('success');

        $this->assertTrue(RentalApplicationQualifyingSetting::allowManualLinkShareFor($other->id));
        $this->assertSame('', RentalApplicationQualifyingSetting::invitePolicySentenceFor($other->id));
    }

    // ── D6 - decline wording and templates in the Setup Wizard ────────────

    public function test_the_decline_wording_saves_from_the_wizard_saver_only_for_the_fields_posted_and_audits_it(): void
    {
        $controller = app(\App\Http\Controllers\CoreX\RentalApplicationSettingsController::class);
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['decline_email_subject' => 'About your application']);
        $request->setUserResolver(fn () => $this->admin);

        $controller->updateDeclineEmailWording($request);

        $row = RentalApplicationDeclineEmailSetting::where('agency_id', $this->agency->id)->first();
        $this->assertSame('About your application', $row->subject);
        $this->assertNull($row->body, 'the body was not posted, so it stays on the suggested default');
        $this->assertTrue(RentalSettingAuditEntry::where('setting_key', 'decline_email_subject')->exists());

        // Posting the suggested wording back stores nothing (the agency keeps following the default).
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['decline_email_body' => RentalApplicationDeclineEmailSetting::DEFAULT_BODY]);
        $request->setUserResolver(fn () => $this->admin);
        $controller->updateDeclineEmailWording($request);
        $this->assertNull($row->fresh()->body);
    }

    public function test_the_rentals_wizard_step_carries_the_new_controls_and_savers(): void
    {
        $copy = config('agency-onboarding-copy.leases');
        $keys = collect($copy['controls'])->pluck('key')->all();
        foreach (['allow_manual_link_share', 'notify_agents_on_application_returned', 'notify_authoriser_on_hand_over', 'invite_policy_sentence',
            'prefill_lease_from_application', 'allow_withdraw_after_approval', 'decline_email_subject', 'decline_email_body',
            'require_end_or_month_to_month_for_signing', 'restore_end_date_on_leaving_month_to_month', 'signed_copy_not_live_note'] as $key) {
            $this->assertContains($key, $keys, "$key must reach the Setup Wizard (non-negotiable 10a)");
        }
        $methods = collect($copy['savers'])->pluck('method')->all();
        $this->assertContains('updateFrontHalfDefaults', $methods);
        $this->assertContains('updateDeclineEmailWording', $methods);
        $this->assertContains('agency-setup.steps.rentals-decline-templates', $copy['partial']);
        $this->assertSame(\App\Models\LeaseSetting::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE, collect($copy['controls'])->firstWhere('key', 'signed_copy_not_live_note')['default']);
    }

    // ── D14 - dates suggested from the application; Create lease on the row

    public function test_the_lease_screen_suggests_start_and_end_from_the_application_unless_the_agency_turns_it_off(): void
    {
        $app = $this->application('approved', ['property_id' => $this->property->id, 'occupation_date' => '2026-11-01', 'rental_term_months' => 12]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create', ['property_id' => $this->property->id, 'rental_application_id' => $app->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="start_date" value="2026-11-01"/', $html);
        $this->assertMatchesRegularExpression('/name="end_date" value="2027-10-31"/', $html);

        $this->setting(['prefill_lease_from_application' => false]);
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create', ['property_id' => $this->property->id, 'rental_application_id' => $app->id]))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="start_date" value="2026-11-01"/', $html);
    }

    public function test_an_approved_row_offers_create_lease_until_a_lease_exists(): void
    {
        $app = $this->application('approved', ['property_id' => $this->property->id]);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-applications.index', ['tile' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('data-test="row-create-lease"', $html);

        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000, 'start_date' => '2026-11-01', 'source' => 'manual', 'rental_application_id' => $app->id,
        ]);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-applications.index', ['tile' => 'all']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="row-create-lease"', $html);
    }

    // ── D15 - withdraw an approved application that has no lease ──────────

    public function test_an_approved_application_with_no_lease_can_be_withdrawn_with_a_note_and_it_is_logged(): void
    {
        $app = $this->application('approved', ['property_id' => $this->property->id]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn'])->assertSessionHasErrors('note');
        $this->assertSame('approved', $app->fresh()->status);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn', 'note' => 'Tenant took another flat'])->assertSessionHas('success');

        $this->assertSame('withdrawn', $app->fresh()->status);
        $this->assertTrue(RentalApplicationStatusHistory::where('rental_application_id', $app->id)->where('from_status', 'approved')->where('to_status', 'withdrawn')
            ->where('note', 'Tenant took another flat')->where('changed_by_user_id', $this->agent->id)->exists());
    }

    public function test_withdrawing_an_approved_application_is_refused_once_a_lease_exists_or_when_the_agency_switches_it_off(): void
    {
        $leased = $this->application('approved', ['property_id' => $this->property->id]);
        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000, 'start_date' => '2026-11-01', 'source' => 'manual', 'rental_application_id' => $leased->id,
        ]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $leased), ['status' => 'withdrawn', 'note' => 'x'])->assertSessionHas('error');
        $this->assertSame('approved', $leased->fresh()->status);

        $this->setting(['allow_withdraw_after_approval' => false]);
        $free = $this->application('approved');
        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $free), ['status' => 'withdrawn', 'note' => 'x'])->assertSessionHas('error');
        $this->assertSame('approved', $free->fresh()->status);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $free))->assertOk()->getContent();
        $this->assertStringNotContainsString('Withdraw application', $html);
    }

    public function test_the_application_page_offers_withdraw_for_an_approved_application_with_no_lease(): void
    {
        $app = $this->application('approved');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk()->getContent();

        $this->assertStringContainsString('Withdraw application', $html);
    }
}
