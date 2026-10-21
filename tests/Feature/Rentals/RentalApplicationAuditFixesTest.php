<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use App\Services\Compliance\FicaWindows;
use App\Services\RentalApplications\ApplicantAccessCode;
use App\Services\RentalApplications\ApplicantFicaLink;
use App\Services\RentalApplications\RentalApplicationAuditService;
use App\Services\RentalApplications\RentalApplicationMailer;
use App\Support\Audit\AuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rental application / lease / notice audit fixes (19 Oct 2026) - the APPLICATION half (cluster 1):
 *  A2  a locked-out applicant always has a way in (fallback factor, agent re-issue with a one-time code)
 *  A3  sent / in-progress / reopened applications have an agent exit
 *  A4/A27 no approver configured -> one-step approval + a warning, the wizard can set approvers
 *  A8  a failed email is not "notified" and can be re-sent
 *  A10 archive guard (in the approver's queue / has a lease)
 *  A11 an administrator may decide
 *  A14 approve / decline are one transaction
 *  A15/A25 one FICA-link helper, link life = the agency's FICA setting
 */
final class RentalApplicationAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        AuditContext::reset();
        Mail::fake();
        Bus::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = $this->user('agent');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function application(string $status, array $extra = []): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi-' . uniqid() . '@example.test', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status, 'full_name' => 'Thandi Nkosi',
            'email' => 'applicant-' . uniqid() . '@example.test', 'token' => Str::random(64),
            'token_expires_at' => now()->addDays(7), 'submitted_at' => now()->subDay(), 'identity_verified_at' => now()->subDay(),
            'current_generation' => 1,
        ]);
    }

    private function setting(array $values): void
    {
        $row = RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first()
            ?? new RentalApplicationQualifyingSetting(['agency_id' => $this->agency->id]);
        $row->fill($values)->save();
    }

    // ── A4 / A27: no approver configured ──────────────────────────────────

    public function test_with_no_approver_configured_approval_is_one_step_by_default_but_only_an_administrator_can_decide(): void
    {
        $this->assertFalse(RentalApplicationQualifyingSetting::hasAuthoriserFor($this->agency->id));
        $this->assertSame('two_step', RentalApplicationQualifyingSetting::approvalModeFor($this->agency->id), 'the saved choice is untouched');
        $this->assertSame('one_step', RentalApplicationQualifyingSetting::effectiveApprovalModeFor($this->agency->id));

        $app = $this->application('returned');

        // Johan, 20 Oct 2026 (paraphrase): until an approver is set up ONLY an agency administrator may approve or decline - not an agent, not even on their own file.
        $this->assertFalse($this->agent->mayDecideRentalApplications($this->agency->id));
        $this->assertFalse($this->agent->mayDecideOwnRentalApplication($this->agency->id));
        $this->actingAs($this->agent)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => '9,500'])
            ->assertForbidden()->assertSee('no approver set up yet', false)->assertSee('agency administrator', false);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.authorisation.decline', $app), ['decline_reason' => 'x'])->assertForbidden();
        $this->assertSame('returned', $app->fresh()->status);

        $this->actingAs($this->admin)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => '9,500'])->assertRedirect();

        $app->refresh();
        $this->assertSame('approved', $app->status);
        $this->assertEquals(9500, $app->approved_rental_amount);
        $this->assertSame('approved', RentalApplicationStatusHistory::where('rental_application_id', $app->id)->latest('id')->value('to_status'));
    }

    public function test_an_application_the_agent_created_is_not_theirs_to_approve_when_there_is_no_approver(): void
    {
        $app = $this->application('returned', ['created_by_user_id' => $this->agent->id]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => '9,500'])->assertForbidden();
        $this->assertSame('returned', $app->fresh()->status);
    }

    public function test_the_authorisation_routes_need_the_authorise_permission(): void
    {
        $app = $this->application('returned');
        $this->agency->update(['rental_application_ro_user_ids' => [$this->agent->id]]);
        $this->setting(['approval_mode' => 'one_step']);

        $this->actingAs($this->agent)->get(route('corex.rental-applications.authorisation.show', $app))->assertOk();

        // an administrator took the permission off this role: the screens close even to a ticked approver
        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_applications.view', 'agency_id' => $this->agency->id], ['scope' => 'agency']);
        \App\Models\RolePermission::withTrashed()->where('role', 'agent')->where('permission_key', 'rental_applications.authorise')->where('agency_id', $this->agency->id)->forceDelete();
        \App\Services\PermissionService::clearCache();
        $this->assertFalse($this->agent->fresh()->hasPermission('rental_applications.authorise'), 'precondition: the role does not hold the key');
        $this->actingAs($this->agent->fresh())->get(route('corex.rental-applications.authorisation.show', $app))->assertForbidden();
        $this->actingAs($this->agent->fresh())->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])->assertForbidden();
    }

    public function test_the_review_screen_and_the_settings_screen_warn_when_nobody_is_an_approver(): void
    {
        $app = $this->application('returned');

        // a plain agent is told, plainly, that an approver must be set up and who can do it - and is not offered the hand-off
        $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))
            ->assertOk()->assertSee('no-authoriser-warning', false)->assertSee('no approver set up yet', false)->assertSee('An agency administrator can choose approvers', false)
            ->assertDontSee('data-qa="submit-for-approval"', false)->assertDontSee('data-qa="one-step-note"', false);
        // an administrator sees the fallback is in force
        $this->actingAs($this->admin)->get(route('corex.rental-applications.review', $app))
            ->assertOk()->assertSee('no-authoriser-warning', false)->assertSee('approved directly by an administrator', false);

        $this->actingAs($this->admin)->get(route('corex.settings.rental-applications.edit'))
            ->assertOk()->assertSee('No approver is set up', false);

        // Once somebody is ticked the warning goes away and the two-step rule is back.
        $this->agency->update(['rental_application_ro_user_ids' => [$this->admin->id]]);
        $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->assertDontSee('no-authoriser-warning', false);
        $this->actingAs($this->admin)->get(route('corex.settings.rental-applications.edit'))->assertOk()->assertDontSee('No approver is set up', false);
    }

    public function test_the_hand_off_is_refused_not_silently_lost_when_nobody_is_an_approver(): void
    {
        $app = $this->application('returned');

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.submit-for-approval', $app))
            ->assertStatus(422)->assertJsonPath('reason', 'no_authoriser');

        $this->assertSame('returned', $app->fresh()->status);
        $this->assertNull($app->fresh()->submitted_for_approval_at);
    }

    public function test_with_an_approver_configured_a_plain_agent_still_cannot_decide(): void
    {
        $this->agency->update(['rental_application_ro_user_ids' => [$this->user('branch_manager')->id]]);
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application('returned');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])->assertStatus(403);

        $this->assertSame('returned', $app->fresh()->status);
    }

    public function test_the_wizard_saver_sets_both_approver_lists_and_an_unrendered_field_changes_nothing(): void
    {
        $ro = $this->user('agent');
        $co = $this->user('branch_manager');

        // Both lists posted (with the control's empty companion value, which must be dropped).
        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'rental_application_ro_user_ids' => ['', (string) $ro->id],
            'rental_application_co_user_ids' => ['', (string) $co->id],
        ]);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\RentalApplicationSettingsController::class)->updateAuthorisersFromWizard($request);

        $this->agency->refresh();
        $this->assertSame([$ro->id], array_map('intval', $this->agency->rental_application_ro_user_ids));
        $this->assertSame([$co->id], array_map('intval', $this->agency->rental_application_co_user_ids));
        $this->assertTrue(RentalApplicationQualifyingSetting::hasAuthoriserFor($this->agency->id));
        $this->assertSame([$ro->id], RentalApplicationQualifyingSetting::rentalApplicationRoUserIdsFor($this->agency->id));

        // A step that did not render the controls posts neither field: nothing is wiped.
        $empty = \Illuminate\Http\Request::create('/x', 'POST', ['something_else' => '1']);
        $empty->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\RentalApplicationSettingsController::class)->updateAuthorisersFromWizard($empty);
        $this->agency->refresh();
        $this->assertSame([$ro->id], array_map('intval', $this->agency->rental_application_ro_user_ids));

        // Only the companion posted = everyone un-ticked = cleared.
        $cleared = \Illuminate\Http\Request::create('/x', 'POST', ['rental_application_ro_user_ids' => ['']]);
        $cleared->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\RentalApplicationSettingsController::class)->updateAuthorisersFromWizard($cleared);
        $this->agency->refresh();
        $this->assertEmpty($this->agency->rental_application_ro_user_ids);
        $this->assertSame([$co->id], array_map('intval', $this->agency->rental_application_co_user_ids));
    }

    public function test_the_wizard_saver_refuses_a_user_from_another_agency(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent', 'is_active' => true]);
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['rental_application_ro_user_ids' => [(string) $stranger->id]]);
        $request->setUserResolver(fn () => $this->admin);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Controllers\CoreX\RentalApplicationSettingsController::class)->updateAuthorisersFromWizard($request);
    }

    public function test_the_approver_controls_are_in_the_wizard_config_and_have_a_resolver(): void
    {
        $controls = collect(config('agency-onboarding-copy.leases.controls'))->keyBy('key');
        foreach (['rental_application_ro_user_ids', 'rental_application_co_user_ids'] as $key) {
            $this->assertTrue($controls->has($key), "$key must be in the wizard");
            $this->assertSame('user_multiselect', $controls[$key]['type']);
            $this->assertNotEmpty($controls[$key]['explain']);
            $this->assertNotEmpty($controls[$key]['affects']);
        }
        $savers = collect(config('agency-onboarding-copy.leases.savers'))->pluck('method')->all();
        $this->assertContains('updateAuthorisersFromWizard', $savers);
    }

    // ── A11: an administrator may decide ──────────────────────────────────

    public function test_an_administrator_who_is_not_ticked_can_approve_and_decline_and_it_is_audited(): void
    {
        $other = $this->user('branch_manager');
        $this->agency->update(['rental_application_ro_user_ids' => [$other->id]]);   // approvers exist, the admin is not one of them
        $pending = $this->application('under_assessment', ['submitted_for_approval_at' => now()]);

        $this->actingAs($this->admin)->post(route('corex.rental-applications.authorisation.approve', $pending), ['approved_rental_amount' => 8000])->assertRedirect();
        $this->assertSame('approved', $pending->fresh()->status);

        // And may change a decision already made (override, reason required).
        $this->actingAs($this->admin)->post(route('corex.rental-applications.authorisation.approve', $pending->fresh()), ['approved_rental_amount' => 8500])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('corex.rental-applications.authorisation.approve', $pending->fresh()), ['approved_rental_amount' => 8500, 'reason' => 'Agreed with the applicant'])->assertRedirect();
        $this->assertEquals(8500, $pending->fresh()->approved_rental_amount);
        $this->assertDatabaseHas('rental_application_audit_log', ['rental_application_id' => $pending->id, 'event_type' => 'approved_override', 'is_override' => 1]);
    }

    // ── A14: approve / decline are one transaction ────────────────────────

    public function test_a_failure_while_recording_an_approval_leaves_the_status_unchanged(): void
    {
        $this->agency->update(['rental_application_ro_user_ids' => [$this->admin->id]]);
        $app = $this->application('under_assessment', ['submitted_for_approval_at' => now()]);
        $this->mock(RentalApplicationAuditService::class, fn ($m) => $m->shouldReceive('log')->andThrow(new \RuntimeException('audit down')));

        $this->actingAs($this->admin)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])->assertStatus(500);

        $app->refresh();
        $this->assertSame('under_assessment', $app->status, 'the status must roll back with the rest');
        $this->assertNull($app->approved_rental_amount);
        $this->assertSame(0, RentalApplicationStatusHistory::where('rental_application_id', $app->id)->where('to_status', 'approved')->count());
    }

    // ── A8: a failed email is not "notified" ──────────────────────────────

    public function test_a_failed_approval_email_does_not_mark_the_applicant_notified_and_can_be_retried(): void
    {
        $app = $this->application('approved', ['approved_rental_amount' => 9000]);
        $mailer = \Mockery::mock(RentalApplicationMailer::class);
        $mailer->shouldReceive('sendApproved')->once()->andReturn(false);
        $this->app->instance(RentalApplicationMailer::class, $mailer);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.send', $app))->assertSessionHas('error');
        $this->assertNull($app->fresh()->applicant_notified_at);

        // The same button works again once the mail goes through.
        $ok = \Mockery::mock(RentalApplicationMailer::class);
        $ok->shouldReceive('sendApproved')->once()->andReturn(true);
        $this->app->instance(RentalApplicationMailer::class, $ok);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.send', $app))->assertSessionHas('success');
        $this->assertNotNull($app->fresh()->applicant_notified_at);
    }

    public function test_a_failed_decline_email_keeps_the_agents_wording_but_does_not_mark_notified(): void
    {
        $app = $this->application('declined');
        $mailer = \Mockery::mock(RentalApplicationMailer::class);
        $mailer->shouldReceive('sendDecline')->once()->andReturn(false);
        $this->app->instance(RentalApplicationMailer::class, $mailer);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.send-decline', $app), ['subject' => 'About your application', 'body' => 'We are sorry.'])
            ->assertSessionHas('error');

        $app->refresh();
        $this->assertNull($app->applicant_notified_at);
        $this->assertSame('We are sorry.', $app->decline_email_body, 'her edited wording is kept as the draft');
    }

    // ── A10: archive guard ────────────────────────────────────────────────

    public function test_an_application_in_the_approvers_queue_or_with_a_lease_cannot_be_archived(): void
    {
        $queued = $this->application('under_assessment', ['submitted_for_approval_at' => now()]);
        $this->actingAs($this->admin)->delete(route('corex.rental-applications.destroy', $queued))->assertSessionHas('error');
        $this->assertNull($queued->fresh()->deleted_at);

        $leased = $this->application('approved', ['approved_rental_amount' => 9000]);
        $property = \App\Models\Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Audit Fix Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'rental_application_id' => $leased->id, 'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000,
            'start_date' => now()->addMonth(), 'created_by_user_id' => $this->agent->id,
        ]);
        $this->actingAs($this->admin)->delete(route('corex.rental-applications.destroy', $leased))->assertSessionHas('error');
        $this->assertNull($leased->fresh()->deleted_at);

        // A plain returned application archives as before.
        $plain = $this->application('returned');
        $this->actingAs($this->admin)->delete(route('corex.rental-applications.destroy', $plain))->assertSessionHas('success');
        $this->assertNotNull(RentalApplication::withTrashed()->find($plain->id)->deleted_at);
    }

    // ── A3: exits for sent / in-progress / reopened ───────────────────────

    public function test_a_sent_in_progress_or_reopened_application_can_be_withdrawn_with_a_note_and_its_link_dies(): void
    {
        foreach (['sent', 'in_progress', 'reopened'] as $status) {
            $app = $this->application($status, $status === 'reopened' ? [] : ['submitted_at' => null]);

            $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn'])
                ->assertSessionHasErrors('note');   // a note is required, same as every withdrawal
            $this->assertSame($status, $app->fresh()->status);

            $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn', 'note' => 'Went quiet, told us by phone'])
                ->assertSessionHas('success');

            $app->refresh();
            $this->assertSame('withdrawn', $app->status, $status);
            $this->assertTrue($app->token_expires_at->lte(now()), 'the link dies');
            $this->assertSame($status, RentalApplicationStatusHistory::where('rental_application_id', $app->id)->latest('id')->value('from_status'));
        }

        // The agent's other status moves are still not available before submission.
        $sent = $this->application('sent', ['submitted_at' => null]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $sent), ['status' => 'under_assessment'])->assertSessionHas('error');
    }

    public function test_a_reopened_application_can_be_taken_back_as_returned(): void
    {
        $app = $this->application('reopened', ['reopened_at' => now()->subDays(3)]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.cancel-reopen', $app), ['note' => 'Never replied'])->assertRedirect();

        $app->refresh();
        $this->assertSame('returned', $app->status);
        $this->assertSame('reopened', RentalApplicationStatusHistory::where('rental_application_id', $app->id)->latest('id')->value('from_status'));
        $this->assertDatabaseHas('rental_application_audit_log', ['rental_application_id' => $app->id, 'event_type' => 'reopen_cancelled']);

        // Only a reopened file can be taken back.
        $this->actingAs($this->agent)->post(route('corex.rental-applications.cancel-reopen', $app), [])->assertSessionHas('error');
    }

    // ── A2: a locked-out applicant always has a way in ────────────────────

    public function test_an_applicant_with_no_id_number_falls_back_to_the_emailed_code(): void
    {
        $app = $this->application('returned', ['id_number' => null]);   // default method is the ID number

        $this->get(route('rental-applications.public.show', $app->token))
            ->assertOk()->assertSee("We've sent a 6-digit code", false);
    }

    public function test_an_applicant_with_no_id_and_no_email_gets_the_agent_code_route_and_the_code_lets_them_in_once(): void
    {
        $app = $this->application('returned', ['id_number' => null, 'email' => null]);
        Contact::find($app->contact_id)->forceFill(['email' => null])->save();

        $this->get(route('rental-applications.public.show', $app->token))
            ->assertOk()->assertSee('Ask', false)->assertSee('one-time code', false);

        // The agent re-issues: fresh link + code.
        $this->actingAs($this->agent)->post(route('corex.rental-applications.reissue-link', $app))
            ->assertRedirect()->assertSessionHas('reissued_link');
        $issued = session('reissued_link');
        $app->refresh();
        $this->assertNotEmpty($issued['code']);
        $this->assertStringContainsString($app->token, $issued['url']);

        auth()->logout();
        $this->flushSession();

        // A wrong code does not get in; the right one does; it works once.
        $this->post(route('rental-applications.public.verify-gate', $app->token), ['agent_code' => '000000'])->assertSessionHasErrors('gate');
        $this->post(route('rental-applications.public.verify-gate', $app->token), ['agent_code' => $issued['code']])->assertRedirect();
        $this->get(route('rental-applications.public.show', $app->token))->assertOk()->assertDontSee("Verify it's you", false);

        $this->flushSession();
        $this->post(route('rental-applications.public.verify-gate', $app->token), ['agent_code' => $issued['code']])->assertSessionHasErrors('gate');
    }

    public function test_reissuing_kills_the_old_link_and_is_refused_for_closed_or_unsent_files(): void
    {
        $app = $this->application('in_progress', ['submitted_at' => null]);
        $oldToken = $app->token;

        $this->actingAs($this->agent)->post(route('corex.rental-applications.reissue-link', $app))->assertRedirect();

        $app->refresh();
        $this->assertNotSame($oldToken, $app->token);
        $this->assertNull(session('reissued_link')['code'], 'no gate before the first submission, so no code');
        $this->get(route('rental-applications.public.show', $oldToken))->assertNotFound();

        foreach (['draft', 'declined', 'withdrawn'] as $status) {
            $closed = $this->application($status);
            $token = $closed->token;
            $this->actingAs($this->agent)->post(route('corex.rental-applications.reissue-link', $closed))->assertSessionHas('error');
            $this->assertSame($token, $closed->fresh()->token, $status);
        }
    }

    public function test_the_agent_code_is_single_use_hashed_and_expires(): void
    {
        $app = $this->application('returned');
        $codes = app(ApplicantAccessCode::class);

        $code = $codes->issue($app, now()->addDay());
        $this->assertTrue($codes->hasLiveCode($app));
        $this->assertDatabaseMissing('client_otps', ['code_hash' => $code]);
        $this->assertTrue($codes->verify($app, " $code "));
        $this->assertFalse($codes->verify($app, $code));

        $expired = $codes->issue($app, now()->subMinute());
        $this->assertFalse($codes->verify($app, $expired));
    }

    // ── A15 / A25: one FICA-link helper, the agency's own link life ───────

    public function test_the_applicant_fica_link_lives_as_long_as_the_agencys_fica_setting(): void
    {
        $app = $this->application('returned');

        $submission = app(ApplicantFicaLink::class)->submissionFor($app);
        $this->assertSame(14, FicaWindows::DEFAULT_LINK_EXPIRY_DAYS);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $submission->token_expires_at->timestamp, 5, 'default 14 days');
        $this->assertSame($this->agent->id, (int) $submission->requested_by);

        // A second agency that wants longer gets longer.
        $this->agency->forceFill(['fica_link_expiry_days' => 30])->save();
        $submission->forceFill(['token_expires_at' => now()->subDay()])->save();
        $again = app(ApplicantFicaLink::class)->submissionFor($app);
        $this->assertSame($submission->id, $again->id, 'the existing draft is reused, not duplicated');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $again->token_expires_at->timestamp, 5);
        $this->assertSame(1, FicaSubmission::where('contact_id', $app->contact_id)->count());
    }

    public function test_a_new_fica_submission_for_an_application_with_no_owner_falls_back_to_the_agency_admin(): void
    {
        $app = $this->application('returned', ['created_by_user_id' => null]);

        $submission = app(ApplicantFicaLink::class)->submissionFor($app);

        $this->assertSame($this->admin->id, (int) $submission->requested_by);
        $this->assertStringContainsString($submission->token, app(ApplicantFicaLink::class)->urlFor($app));
    }

    public function test_no_application_code_still_hard_codes_a_fica_link_life(): void
    {
        foreach ([
            app_path('Http/Controllers/RentalApplicationSigningController.php'),
            app_path('Services/RentalApplications/RentalApplicationMailer.php'),
            app_path('Services/RentalApplications/ApplicantFicaLink.php'),
        ] as $file) {
            $this->assertStringNotContainsString('addDays(14)', (string) file_get_contents($file), basename($file));
        }
    }
}
