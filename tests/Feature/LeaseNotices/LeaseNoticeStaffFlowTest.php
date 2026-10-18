<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Mail\Rentals\LeaseNoticeAcceptedMail;
use App\Mail\Rentals\RentalNoticeMail;
use App\Mail\Rentals\RentalOwnerNoticeToAgentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseNotice;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * lease-notices.md §5 - the agent's side: record on behalf, review, decide, send to the other party; the lease's move-out date moves only on
 * acceptance; the list, scoping, archive / restore and the audit trail. All mail is faked and rendered; nothing is really sent.
 */
final class LeaseNoticeStaffFlowTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function tomorrowPlus(int $days): string
    {
        return Carbon::today()->addDays($days)->toDateString();
    }

    private function accept(LeaseNotice $notice, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.lease-notices.accept', $notice->id), $over + [
            'end_date' => $notice->intended_end_date->toDateString(), 'notice_outcome' => 'readvertise', 'note' => 'Checked against the lease',
        ]);
    }

    // ── 1. record on behalf of the tenant / landlord ──────────────────────

    public function test_the_agent_records_a_notice_on_behalf_of_the_tenant_and_lands_on_its_review_with_nothing_sent_or_set(): void
    {
        $res = $this->actingAs($this->agent)->post(route('corex.leases.renewal.tenant-notice', $this->lease), [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->tomorrowPlus(70), 'reason' => 'Job transfer',
            'attachment' => UploadedFile::fake()->create('email.pdf', 40, 'application/pdf'),
        ]);

        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $res->assertRedirect(route('corex.lease-notices.show', $notice->id));
        $this->assertSame('agent', $notice->via);
        $this->assertSame($this->agent->id, $notice->recorded_by_user_id);
        $this->assertTrue($notice->hasAttachment());
        $this->assertNull($this->lease->fresh()->notice_date, 'the lease is untouched until acceptance');
        Mail::assertNotQueued(RentalNoticeMail::class);
        // the recording agent is not alerted about their own entry; their colleague is
        Mail::assertNotQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent->email));
        Mail::assertQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent2->email));
    }

    public function test_the_landlord_dialog_records_a_landlord_notice_and_the_lease_screen_shows_the_panel(): void
    {
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.landlord-notice', $this->lease), [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString(),
        ])->assertRedirect();
        $this->assertSame('landlord', LeaseNotice::withoutGlobalScopes()->firstOrFail()->given_by);

        $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()
            ->assertSee('data-qa="lease-notice-panel"', false)->assertSee('Notice received - needs review')->assertSee('the landlord gave notice');
    }

    public function test_the_old_dialog_fields_are_gone_and_validation_reopens_the_same_dialog(): void
    {
        $this->actingAs($this->agent)->from(route('corex.leases.show', $this->lease))
            ->post(route('corex.leases.renewal.tenant-notice', $this->lease), ['_lease_action' => 'tenant-notice', 'notice_date' => Carbon::today()->toDateString()])
            ->assertSessionHasErrors('intended_end_date');
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->getContent();
        $this->assertStringContainsString('name="intended_end_date"', $html);
        $this->assertStringContainsString('name="attachment"', $html);
        $this->assertStringNotContainsString('name="move_out_date"', $html);
    }

    public function test_a_lease_that_already_has_a_notice_waiting_or_accepted_takes_no_second_one(): void
    {
        $this->notice(['via' => 'agent']);
        $this->actingAs($this->agent)->post(route('corex.leases.renewal.tenant-notice', $this->lease), [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->tomorrowPlus(70),
        ])->assertSessionHasErrors('lease');
        $this->assertSame(1, LeaseNotice::withoutGlobalScopes()->count());
    }

    // ── 2. review ──────────────────────────────────────────────────────────

    public function test_the_review_screen_shows_the_notice_beside_the_leases_terms_and_the_flags(): void
    {
        $notice = $this->notice(['intended_end_date' => $this->tomorrowPlus(10)]);

        $html = $this->actingAs($this->agent)->get(route('corex.lease-notices.show', $notice->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="notice-details"', $html);
        $this->assertStringContainsString('Moving to Cape Town for work', $html);
        $this->assertStringContainsString('data-qa="notice-terms"', $html);
        $this->assertStringContainsString('30 days', $html, 'the notice period in force');
        $this->assertStringContainsString("20 business days' written notice", $html);
        $this->assertStringContainsString('data-flag="cpa_early_cancellation"', $html);
        $this->assertStringContainsString('data-flag="cpa_notice_too_short"', $html);
        $this->assertStringContainsString('Accept notice', $html);
        $this->assertStringContainsString('Reject notice', $html);
        $this->assertStringNotContainsString('Send to the other party', $html, 'not before it is accepted');
    }

    // ── 3. decide ──────────────────────────────────────────────────────────

    public function test_accepting_as_given_with_no_warnings_sets_the_move_out_date_and_logs_it(): void
    {
        $notice = $this->notice(['intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString()]);

        $this->accept($notice, ['note' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $lease = $this->lease->fresh();
        $this->assertSame(Carbon::parse($this->lease->end_date)->toDateString(), $lease->move_out_date->toDateString());
        $this->assertSame('tenant', $lease->notice_given_by);
        $this->assertSame($notice->notice_date->toDateString(), $lease->notice_date->toDateString(), 'the date notice was GIVEN, not the date it was accepted');
        $this->assertSame('readvertise', $lease->notice_outcome);
        $fresh = $notice->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame('as_given', $fresh->decision_kind);
        $this->assertSame($this->agent->id, $fresh->decided_by_user_id);
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_DECIDED)->exists());
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_RECORDED)->exists(), 'the existing record-notice mechanism ran');
    }

    public function test_accepting_over_a_warning_needs_a_note_and_nothing_is_set_without_it(): void
    {
        $notice = $this->notice(['intended_end_date' => $this->tomorrowPlus(10)]);   // CPA: too short

        $this->accept($notice, ['note' => ''])->assertSessionHasErrors('note');
        $this->assertNull($this->lease->fresh()->move_out_date);
        $this->assertSame('received', $notice->fresh()->status);

        $this->accept($notice, ['note' => 'Tenant is in breach, landlord agrees in writing'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $notice->fresh()->status);
        $this->assertNotEmpty($notice->fresh()->flags, 'the warnings are kept with the decision');
    }

    public function test_accepting_with_a_corrected_date_needs_a_reason_and_records_the_corrected_date(): void
    {
        $notice = $this->notice(['intended_end_date' => $this->tomorrowPlus(10)]);
        $corrected = app(\App\Services\Rentals\LeaseNoticeReviewService::class)->addBusinessDays(Carbon::today(), 20)->toDateString();

        $this->accept($notice, ['end_date' => $corrected, 'note' => ''])->assertSessionHasErrors('note');
        $this->accept($notice, ['end_date' => $corrected, 'note' => 'CPA: 20 business days'])->assertSessionHasNoErrors();

        $fresh = $notice->fresh();
        $this->assertSame('corrected', $fresh->decision_kind);
        $this->assertSame($corrected, $fresh->accepted_end_date->toDateString());
        $this->assertSame($this->tomorrowPlus(10), $fresh->intended_end_date->toDateString(), 'what was given is kept');
        $this->assertSame($corrected, $this->lease->fresh()->move_out_date->toDateString());
    }

    public function test_the_property_outcome_is_required_when_accepting(): void
    {
        $notice = $this->notice(['intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString()]);
        $this->actingAs($this->agent)->post(route('corex.lease-notices.accept', $notice->id), ['end_date' => $notice->intended_end_date->toDateString()])->assertSessionHasErrors('notice_outcome');
        $this->assertNull($this->lease->fresh()->notice_date);
    }

    public function test_rejecting_needs_a_reason_leaves_the_lease_alone_and_tells_nobody_but_the_giver(): void
    {
        $notice = $this->notice();

        $this->actingAs($this->agent)->post(route('corex.lease-notices.reject', $notice->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent)->post(route('corex.lease-notices.reject', $notice->id), ['reason' => 'Wrong lease'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $notice->fresh()->status);
        $this->assertNull($this->lease->fresh()->notice_date);
        $this->assertSame('Wrong lease', $notice->fresh()->decision_note);
        // a second decision is refused
        $this->accept($notice->fresh())->assertSessionHasErrors('notice');
    }

    public function test_reversing_an_accepted_notice_withdraws_its_case(): void
    {
        $notice = $this->notice(['intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString()]);
        $this->accept($notice);

        $this->actingAs($this->agent)->post(route('corex.leases.renewal.notice.reverse', $this->lease))->assertRedirect();

        $this->assertSame('withdrawn', $notice->fresh()->status);
        $this->assertNull($this->lease->fresh()->notice_date);
    }

    // ── 4. acceptance tells the other party ────────────────────────────────

    private function acceptedCase(array $over = []): LeaseNotice
    {
        $notice = $this->notice($over + ['intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString()]);
        $this->accept($notice)->assertSessionHasNoErrors();

        return $notice->fresh();
    }

    public function test_accepting_a_tenants_notice_tells_the_landlord_by_mail_as_the_agent_and_nobody_else(): void
    {
        $notice = $this->acceptedCase(['reason' => 'Private: my mother is ill']);

        Mail::assertSent(LeaseNoticeAcceptedMail::class, function (LeaseNoticeAcceptedMail $m) use ($notice) {
            $html = $m->render();

            return $m->hasTo($this->landlord->email) && ! $m->hasTo($this->tenant->email)
                && str_contains($html, 'Karoo Lettings')
                && str_contains($html, 'your tenant')
                && str_contains($html, $notice->effectiveEndDate()->format('j F Y'))
                && ! str_contains($html, 'Private: my mother is ill')   // the reason is between the person and their agent
                && stripos($html, 'corex') === false
                && str_contains((string) $m->envelope()->subject, 'notice received')
                && $m->sendingAgentId() === $this->agent->id;
        });
        $this->assertNotNull($notice->other_party_told_at);
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->where('description', 'like', '%was told the notice was accepted%')->exists());
    }

    public function test_accepting_a_landlords_notice_tells_the_tenant(): void
    {
        $this->acceptedCase(['given_by' => 'landlord', 'given_by_contact_id' => $this->landlord->id]);

        Mail::assertSent(LeaseNoticeAcceptedMail::class, fn (LeaseNoticeAcceptedMail $m) => $m->hasTo($this->tenant->email) && ! $m->hasTo($this->landlord->email) && str_contains($m->render(), 'your landlord'));
    }

    public function test_rejecting_tells_the_other_party_nothing_and_a_waiting_notice_is_invisible_to_them(): void
    {
        $notice = $this->notice();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->assertSame([], $this->getJson('/api/v1/client/rentals/landlord/notices')->json('from_other_party'), 'a notice waiting for review is not shown to the other party');

        app(LeaseNoticeService::class)->reject($notice, $this->agent, 'Wrong lease');
        Mail::assertNotSent(LeaseNoticeAcceptedMail::class);
        $this->assertSame([], $this->getJson('/api/v1/client/rentals/landlord/notices')->json('from_other_party'));
    }

    public function test_the_other_party_sees_the_accepted_notice_in_their_portal_and_the_giver_does_not_see_it_twice(): void
    {
        $notice = $this->acceptedCase();

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $landlord = $this->getJson('/api/v1/client/rentals/landlord/notices')->assertOk()->json();
        $this->assertCount(1, $landlord['from_other_party']);
        $this->assertStringContainsString('Your tenant gave notice', $landlord['from_other_party'][0]['status_line']);
        $this->assertStringContainsString($notice->effectiveEndDate()->format('j F Y'), $landlord['from_other_party'][0]['status_line']);
        $this->assertArrayNotHasKey('reason', $landlord['from_other_party'][0], 'dates only - never the reason');
        $this->assertSame([], $landlord['notices'], 'the landlord gave no notice themselves');

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $tenant = $this->getJson('/api/v1/client/rentals/notices')->assertOk()->json();
        $this->assertCount(1, $tenant['notices']);
        $this->assertSame([], $tenant['from_other_party']);
    }

    public function test_the_legacy_send_notice_screen_is_not_part_of_the_review(): void
    {
        $notice = $this->acceptedCase();
        $html = $this->actingAs($this->agent)->get(route('corex.lease-notices.show', $notice->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="notice-other-party"', $html);
        $this->assertStringNotContainsString('Send to the other party', $html);
        $this->assertStringNotContainsString('rental_notice_template_id', $html);
        Mail::assertNotQueued(RentalNoticeMail::class);
    }

    // ── 5. list, scoping, archive / restore, audit ─────────────────────────

    public function test_the_list_searches_sorts_and_filters_and_defaults_to_needs_review(): void
    {
        $waiting = $this->notice(['reason' => 'Emigrating to Perth']);
        $done = $this->notice(['status' => 'rejected', 'given_by' => 'landlord', 'reason' => 'Selling the flat', 'notice_date' => Carbon::today()->subDays(3)->toDateString()]);

        $default = $this->actingAs($this->agent)->get(route('corex.lease-notices.index'))->assertOk();
        $default->assertSee('data-notice-row="' . $waiting->id . '"', false)->assertDontSee('data-notice-row="' . $done->id . '"', false);

        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '']))->assertSee('data-notice-row="' . $done->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'q' => 'Perth']))->assertSee('data-notice-row="' . $waiting->id . '"', false)->assertDontSee('data-notice-row="' . $done->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'q' => 'Vlei Court']))->assertSee('data-notice-row="' . $waiting->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'given_by' => 'landlord']))->assertSee('data-notice-row="' . $done->id . '"', false)->assertDontSee('data-notice-row="' . $waiting->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'date_from' => Carbon::today()->subDay()->toDateString()]))->assertDontSee('data-notice-row="' . $done->id . '"', false);
        foreach (['notice_date', 'intended_end_date', 'status', 'property'] as $sort) {
            $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'sort' => $sort, 'dir' => 'asc']))->assertOk();
        }
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['q' => 'nothing matches this']))->assertOk()->assertSee('data-qa="lease-notices-empty"', false)->assertSee('No notices match');
    }

    public function test_an_empty_list_says_what_will_appear_there(): void
    {
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index'))->assertOk()->assertSee('Nothing waiting for review');
    }

    public function test_another_agencys_notice_is_a_404_and_a_user_without_the_permission_is_a_403(): void
    {
        $agency2 = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $agency2->id, 'name' => 'Main', 'code' => 'C-' . $agency2->id, 'is_active' => true]);
        $stranger = User::factory()->create(['agency_id' => $agency2->id, 'branch_id' => $branch2->id, 'role' => 'admin']);
        $notice = $this->notice();

        $this->actingAs($stranger)->get(route('corex.lease-notices.show', $notice->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('corex.lease-notices.reject', $notice->id), ['reason' => 'x'])->assertNotFound();

        $plain = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer_notices']);
        RolePermission::create(['role' => 'viewer_notices', 'permission_key' => 'leases.view', 'scope' => 'all']);
        PermissionService::clearCache();
        $this->actingAs($plain)->get(route('corex.lease-notices.show', $notice->id))->assertForbidden();
        $this->actingAs($plain)->get(route('corex.lease-notices.index'))->assertForbidden();
    }

    public function test_branch_and_own_scope_follow_the_lease(): void
    {
        $branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Second', 'code' => 'S-' . $this->agency->id, 'is_active' => true]);
        $branchAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch2->id, 'role' => 'branch_two_agent']);
        foreach (['rental_notices.review', 'leases.view'] as $key) {
            RolePermission::create(['role' => 'branch_two_agent', 'permission_key' => $key, 'scope' => 'branch']);
        }
        PermissionService::clearCache();
        $notice = $this->notice();

        $this->actingAs($branchAgent)->get(route('corex.lease-notices.show', $notice->id))->assertForbidden();
        $list = $this->actingAs($branchAgent)->get(route('corex.lease-notices.index', ['status' => '']));
        $list->assertOk()->assertDontSee('data-notice-row="' . $notice->id . '"', false);
    }

    public function test_archive_and_restore_are_soft_and_logged(): void
    {
        $notice = $this->notice();

        $this->actingAs($this->agent)->post(route('corex.lease-notices.archive', $notice->id))->assertRedirect(route('corex.lease-notices.index'));
        $this->assertSoftDeleted('lease_notices', ['id' => $notice->id]);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '']))->assertDontSee('data-notice-row="' . $notice->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index', ['status' => '', 'archived' => 1]))->assertSee('data-notice-row="' . $notice->id . '"', false);

        $this->actingAs($this->agent)->post(route('corex.lease-notices.restore', $notice->id))->assertRedirect();
        $this->assertNull($notice->fresh()->deleted_at);
        $this->assertSame(2, LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_EDITED)->count());
    }

    public function test_a_waiting_notice_can_be_corrected_and_the_change_is_logged(): void
    {
        $notice = $this->notice();
        $new = $this->tomorrowPlus(90);

        $this->actingAs($this->agent)->put(route('corex.lease-notices.update', $notice->id), ['notice_date' => $notice->notice_date->toDateString(), 'intended_end_date' => $new, 'reason' => 'Changed'])->assertRedirect();

        $this->assertSame($new, $notice->fresh()->intended_end_date->toDateString());
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_EDITED)->where('description', 'like', '%corrected before review%')->exists());

        $this->accept($notice, ['end_date' => $new, 'note' => 'ok']);
        $this->actingAs($this->agent)->put(route('corex.lease-notices.update', $notice->id), ['notice_date' => $notice->notice_date->toDateString(), 'intended_end_date' => $this->tomorrowPlus(10)])->assertSessionHasErrors('notice');
    }

    public function test_the_attachment_downloads_for_a_user_who_can_see_the_lease_only(): void
    {
        $notice = $this->notice();
        app(LeaseNoticeService::class)->attach($notice, UploadedFile::fake()->create('their-letter.pdf', 30, 'application/pdf'));

        $this->actingAs($this->agent)->get(route('corex.lease-notices.attachment', $notice->id))->assertOk()->assertDownload('their-letter.pdf');
    }

    public function test_the_tenancy_log_shows_the_whole_life_of_the_notice(): void
    {
        $notice = app(LeaseNoticeService::class)->give($this->lease, 'tenant', [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString(), 'reason' => 'x',
        ], 'portal', $this->tenant, null);
        $this->accept($notice);
        $notice = $notice->fresh();
        $lines = app(\App\Services\Rentals\LeaseTimelineService::class)->allEntriesFor($this->lease->fresh())->pluck('description')->implode(' | ');
        $this->assertStringContainsString('waiting for review', $lines);
        $this->assertStringContainsString('Notice accepted', $lines);
        $this->assertStringContainsString('was told the notice was accepted', $lines);
    }

    /** Audit 2026-10-18 (A-M8): the API used to write the move-out date and outcome straight onto the lease, skipping review. */
    public function test_the_api_notice_endpoints_record_a_case_for_review_and_never_touch_the_lease(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/tenant-notice', [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->tomorrowPlus(70), 'reason' => 'Job transfer',
        ])->assertOk()->assertJsonPath('notice.status', LeaseNotice::STATUS_RECEIVED)->assertJsonPath('notice.via', LeaseNotice::VIA_AGENT);

        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(Lease::NOTICE_BY_TENANT, $notice->given_by);
        $this->assertSame($this->agent->id, $notice->recorded_by_user_id);
        $lease = $this->lease->fresh();
        $this->assertNull($lease->notice_date, 'the lease is untouched until a reviewer accepts the notice');
        $this->assertNull($lease->notice_given_by);

        // a second landlord notice is its own case; a repeat of the same party is refused by the service, not written
        $this->actingAs($this->agent)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/tenant-notice', [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->tomorrowPlus(80),
        ])->assertStatus(422);
        $this->assertSame(1, LeaseNotice::withoutGlobalScopes()->count());
    }

    /** The mobile app's ORIGINAL payload still works: pending case, same response keys, outcome ignored. */
    public function test_the_api_still_accepts_the_old_notice_payload_and_keeps_the_old_response_keys(): void
    {
        $res = $this->actingAs($this->agent)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/landlord-notice', [
            'move_out_date' => $this->tomorrowPlus(90), 'note' => 'Selling the flat', 'notice_outcome' => 'withdraw',
        ])->assertOk()->assertJsonStructure(['lease' => ['id'], 'notice' => ['id', 'status']]);

        $this->assertSame($this->lease->id, $res->json('lease.id'));
        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LeaseNotice::STATUS_RECEIVED, $notice->status);
        $this->assertSame(Lease::NOTICE_BY_LANDLORD, $notice->given_by);
        $this->assertSame($this->tomorrowPlus(90), $notice->intended_end_date->toDateString());
        $this->assertSame('Selling the flat', $notice->reason);
        $this->assertSame(Carbon::today()->toDateString(), $notice->notice_date->toDateString());
        $lease = $this->lease->fresh();
        $this->assertNull($lease->notice_date, 'the old payload no longer writes the lease directly');
        $this->assertNull($lease->notice_outcome ?? null, 'the outcome is chosen at review');
    }
}
