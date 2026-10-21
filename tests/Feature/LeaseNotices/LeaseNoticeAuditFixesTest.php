<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseNotice;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rental application / lease audit 2026-10-19 - A6 (archived notice), A9 (told means told), A17 (one entry, one permission),
 * A19 (Renewed shown), A28 (one date rule, .view for the history of notices sent). lease-notices.md "Audit fixes 2026-10-19".
 */
final class LeaseNoticeAuditFixesTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        $this->withoutVite();
    }

    private function plus(int $days): string
    {
        return Carbon::today()->addDays($days)->toDateString();
    }

    /** A plain agent (NOT an owner role) holding exactly these permissions, on the fixture's agency + branch. */
    private function agentWith(array $keys): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Pieter Plain']);
        $this->grant($user, $keys);

        return $user;
    }

    // ── A6 ────────────────────────────────────────────────────────────────

    public function test_an_archived_received_notice_does_not_block_a_new_one(): void
    {
        $old = $this->notice();
        app(LeaseNoticeService::class)->archive($old, $this->agent);

        $new = app(LeaseNoticeService::class)->give($this->lease, 'tenant', ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(60)], 'agent', null, $this->agent);

        $this->assertSame(LeaseNotice::STATUS_RECEIVED, $new->status);
        $this->assertTrue($old->fresh()->trashed() || LeaseNotice::withoutGlobalScopes()->whereKey($old->id)->whereNotNull('deleted_at')->exists());
    }

    public function test_a_live_received_notice_still_blocks_a_second_one_from_the_same_side(): void
    {
        $this->notice();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LeaseNoticeService::class)->give($this->lease, 'tenant', ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(60)], 'agent', null, $this->agent);
    }

    public function test_direct_posts_against_an_archived_notice_are_refused_and_change_nothing(): void
    {
        $notice = $this->notice();
        app(LeaseNoticeService::class)->archive($notice, $this->agent);

        $this->actingAs($this->agent)->post(route('corex.lease-notices.accept', $notice->id), [
            'end_date' => $notice->intended_end_date->toDateString(), 'notice_outcome' => 'readvertise', 'note' => 'x',
        ])->assertSessionHasErrors('notice');
        $this->actingAs($this->agent)->post(route('corex.lease-notices.reject', $notice->id), ['reason' => 'No'])->assertSessionHasErrors('notice');
        $this->actingAs($this->agent)->put(route('corex.lease-notices.update', $notice->id), [
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(40),
        ])->assertSessionHasErrors('notice');

        $fresh = LeaseNotice::withoutGlobalScopes()->withTrashed()->findOrFail($notice->id);
        $this->assertSame(LeaseNotice::STATUS_RECEIVED, $fresh->status);
        $this->assertNull($this->lease->fresh()->notice_date, 'the lease must not have been touched');
    }

    public function test_restoring_an_archived_notice_is_refused_when_a_newer_one_for_that_side_is_waiting(): void
    {
        $old = $this->notice();
        app(LeaseNoticeService::class)->archive($old, $this->agent);
        $this->notice(['intended_end_date' => $this->plus(90)]);

        $this->actingAs($this->agent)->post(route('corex.lease-notices.restore', $old->id))->assertSessionHasErrors('notice');
        $this->assertTrue(LeaseNotice::withoutGlobalScopes()->withTrashed()->findOrFail($old->id)->trashed());
    }

    public function test_archive_and_restore_of_the_wrong_state_do_nothing_and_log_nothing(): void
    {
        $notice = $this->notice();
        $before = LeaseEvent::where('lease_id', $this->lease->id)->count();
        app(LeaseNoticeService::class)->restore($notice, $this->agent); // not archived
        $this->assertSame($before, LeaseEvent::where('lease_id', $this->lease->id)->count());
    }

    // ── A9 ────────────────────────────────────────────────────────────────

    public function test_accepting_a_tenant_notice_tells_the_landlord_only_when_an_email_went(): void
    {
        $notice = $this->notice();
        $this->actingAs($this->agent)->post(route('corex.lease-notices.accept', $notice->id), [
            'end_date' => $notice->intended_end_date->toDateString(), 'notice_outcome' => 'readvertise', 'note' => 'Checked',
        ]);

        $notice = $notice->fresh();
        $this->assertNotNull($notice->other_party_told_at, 'the landlord has an email and was told');
        $this->assertStringContainsString('was told the notice was accepted', LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->value('description'));
    }

    public function test_accepting_a_notice_for_a_landlord_with_no_email_does_not_log_told(): void
    {
        $this->landlord->forceFill(['email' => null])->saveQuietly();
        $notice = $this->notice();

        $this->actingAs($this->agent)->post(route('corex.lease-notices.accept', $notice->id), [
            'end_date' => $notice->intended_end_date->toDateString(), 'notice_outcome' => 'readvertise', 'note' => 'Checked',
        ]);

        $notice = $notice->fresh();
        $this->assertSame(LeaseNotice::STATUS_ACCEPTED, $notice->status, 'the acceptance itself stands');
        $this->assertNull($notice->other_party_told_at);
        $line = LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->value('description');
        $this->assertStringContainsString('could NOT be told', $line);
        $this->assertStringContainsString('no email address on file', $line);
        $this->assertStringNotContainsString('was told the notice', $line);

        $this->actingAs($this->agent)->get(route('corex.lease-notices.show', $notice->id))->assertOk()->assertSee('not</strong> been told', false);
    }

    // ── A17 ───────────────────────────────────────────────────────────────

    public function test_a_user_with_renew_but_not_review_cannot_record_a_notice_by_any_route(): void
    {
        $user = $this->agentWith(['leases.view', 'leases.renew']);
        $payload = ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(60)];

        $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $this->lease), $payload)->assertForbidden();
        $this->actingAs($user)->post(route('corex.leases.renewal.landlord-notice', $this->lease), $payload)->assertForbidden();
        $this->actingAs($user)->post(route('corex.leases.notice-cases.store', $this->lease), $payload + ['given_by' => 'tenant'])->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/tenant-notice', $payload)->assertForbidden();
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());
    }

    public function test_a_user_with_review_can_record_by_every_route_and_always_lands_on_a_case_they_can_open(): void
    {
        $user = $this->agentWith(['leases.view', 'leases.renew', 'rental_notices.review']);
        $payload = ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(60)];

        $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $this->lease), $payload)->assertRedirect();
        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)->get(route('corex.lease-notices.show', $notice->id))->assertOk();
    }

    public function test_the_lease_screen_hides_the_notice_dialogs_without_review(): void
    {
        $user = $this->agentWith(['leases.view', 'leases.renew']);
        $this->actingAs($user)->get(route('corex.leases.show', $this->lease))->assertOk()
            ->assertDontSee(route('corex.leases.notice-cases.store', $this->lease), false);
        $this->grant($user, ['rental_notices.review']);
        $this->actingAs($user)->get(route('corex.leases.show', $this->lease))->assertOk()
            ->assertSee(route('corex.leases.notice-cases.store', $this->lease), false);
    }

    // ── A28 ───────────────────────────────────────────────────────────────

    public function test_every_entry_point_refuses_a_move_out_date_that_is_not_after_the_date_given(): void
    {
        $same = ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => Carbon::today()->toDateString()];
        $before = ['notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => $this->plus(-5)];

        foreach ([$same, $before] as $payload) {
            $this->actingAs($this->agent)->post(route('corex.leases.renewal.tenant-notice', $this->lease), $payload)->assertSessionHasErrors('intended_end_date');
            $this->actingAs($this->agent)->post(route('corex.leases.notice-cases.store', $this->lease), $payload + ['given_by' => 'landlord'])->assertSessionHasErrors('intended_end_date');
            $this->actingAs($this->agent)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/landlord-notice', $payload)->assertStatus(422)->assertJsonValidationErrors('intended_end_date');
        }
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());

        $notice = $this->notice();
        $this->actingAs($this->agent)->put(route('corex.lease-notices.update', $notice->id), $same)->assertSessionHasErrors('intended_end_date');
    }

    public function test_the_shared_rule_set_is_the_one_the_portal_uses(): void
    {
        $rules = LeaseNoticeService::dateRules();
        $this->assertContains('after:notice_date', $rules['intended_end_date']);
        $this->assertContains('before_or_equal:today', $rules['notice_date']);
    }

    public function test_the_history_of_notices_sent_needs_view_not_create(): void
    {
        $createOnly = $this->agentWith(['leases.view', 'rental_notices.create']);
        $this->actingAs($createOnly)->get(route('corex.rental-notices.index'))->assertForbidden();

        $viewer = $this->agentWith(['leases.view', 'rental_notices.view']);
        $this->actingAs($viewer)->get(route('corex.rental-notices.index'))->assertOk();
    }

    public function test_the_backfill_migration_keeps_nobody_locked_out(): void
    {
        RolePermission::query()->delete();
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'rental_notices.create', 'scope' => 'branch']);
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'leases.renew', 'scope' => 'own']);
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'viewer', 'permission_key' => 'leases.view', 'scope' => 'all']);

        $migration = require base_path('database/migrations/2026_11_19_100000_grant_rental_notices_view_and_review_audit_a17_a28.php');
        $migration->up();
        $migration->up(); // idempotent
        PermissionService::clearCache();

        $view = RolePermission::where('role', 'agent')->where('permission_key', 'rental_notices.view')->get();
        $review = RolePermission::where('role', 'agent')->where('permission_key', 'rental_notices.review')->get();
        $this->assertCount(1, $view);
        $this->assertSame('branch', $view->first()->scope, 'same scope as the right it comes from');
        $this->assertCount(1, $review);
        $this->assertSame('own', $review->first()->scope);
        $this->assertSame(0, RolePermission::where('role', 'viewer')->whereIn('permission_key', ['rental_notices.view', 'rental_notices.review'])->count());
    }

    // ── A19 ───────────────────────────────────────────────────────────────

    public function test_a_renewed_term_reads_renewed_and_a_plain_expiry_still_reads_expired(): void
    {
        $this->lease->forceFill(['status' => Lease::STATUS_EXPIRED, 'renewed_lease_id' => null])->saveQuietly();
        $this->assertSame('Expired', $this->lease->fresh()->displayStatus());

        $next = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'draft',
            'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => Carbon::today()->addYear()->toDateString(), 'end_date' => Carbon::today()->addYears(2)->toDateString(),
            'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'previous_lease_id' => $this->lease->id,
        ]);
        $this->lease->forceFill(['renewed_lease_id' => $next->id])->saveQuietly();
        $this->assertSame('Renewed', $this->lease->fresh()->displayStatus());
    }
}
