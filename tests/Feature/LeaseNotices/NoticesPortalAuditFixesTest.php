<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Models\Lease;
use App\Models\RentalNoticeTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Html\NoticeHtmlCleaner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit fixes 2026-10-20 (rental notices / portal / navigation audit) - the parts that live with the lease notices:
 *  finding 5  reverse / change-outcome of a recorded notice need rental_notices.review on every entry
 *  finding 4  the A17/A28 grant migration never restores a deliberately revoked grant
 *  finding 2  the new rental_applications.authorise grant never restores a revoked row either
 *  the note   notice template HTML is cleaned of anything active, on save and on render
 */
final class NoticesPortalAuditFixesTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    private const MIG_GRANT = 'database/migrations/2026_11_19_100000_grant_rental_notices_view_and_review_audit_a17_a28.php';
    private const MIG_AUTHORISE = 'database/migrations/2026_11_26_100000_grant_rental_applications_authorise_audit_2026_10_20.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        $this->withoutVite();
    }

    private function agentWith(array $keys): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Pieter Plain']);
        $this->grant($user, $keys);

        return $user;
    }

    private function withActiveNotice(): void
    {
        $this->lease->forceFill([
            'notice_date' => Carbon::today()->toDateString(), 'notice_given_by' => 'tenant', 'move_out_date' => Carbon::today()->addDays(40)->toDateString(),
            'notice_outcome' => Lease::NOTICE_OUTCOME_LEAVE,
        ])->saveQuietly();
    }

    // ── finding 5 ─────────────────────────────────────────────────────────

    public function test_reverse_and_change_outcome_need_review_on_the_web_and_the_mobile_api(): void
    {
        $this->withActiveNotice();
        $renewOnly = $this->agentWith(['leases.view', 'leases.renew']);
        $outcome = ['notice_outcome' => Lease::NOTICE_OUTCOME_WITHDRAW];

        $this->actingAs($renewOnly)->post(route('corex.leases.renewal.notice.reverse', $this->lease))->assertForbidden();
        $this->actingAs($renewOnly)->post(route('corex.leases.renewal.notice.change-outcome', $this->lease), $outcome)->assertForbidden();
        $this->actingAs($renewOnly)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/notice/reverse')->assertForbidden();
        $this->actingAs($renewOnly)->postJson('/api/v1/leases/' . $this->lease->id . '/renewal/notice/change-outcome', $outcome)->assertForbidden();
        $this->assertNotNull($this->lease->fresh()->notice_date, 'the notice was not reversed');
        $this->assertSame(Lease::NOTICE_OUTCOME_LEAVE, $this->lease->fresh()->notice_outcome, 'the outcome was not changed');
    }

    public function test_a_user_with_review_can_still_reverse_a_notice(): void
    {
        $this->withActiveNotice();
        $reviewer = $this->agentWith(['leases.view', 'leases.renew', 'rental_notices.review']);

        $this->actingAs($reviewer)->post(route('corex.leases.renewal.notice.reverse', $this->lease))->assertRedirect(route('corex.leases.show', $this->lease));

        $this->assertNull($this->lease->fresh()->notice_date);
    }

    public function test_the_controllers_check_the_permission_themselves_not_only_the_route(): void
    {
        $this->withActiveNotice();
        $renewOnly = $this->agentWith(['leases.view', 'leases.renew']);
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['notice_outcome' => Lease::NOTICE_OUTCOME_WITHDRAW]);
        $request->setUserResolver(fn () => $renewOnly);

        foreach (['reverseNotice', 'changeNoticeOutcome'] as $method) {
            try {
                app(\App\Http\Controllers\CoreX\LeaseRenewalController::class)->{$method}($request, $this->lease);
                $this->fail("web {$method} ran without rental_notices.review");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            try {
                app(\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class)->{$method}($request, $this->lease);
                $this->fail("api {$method} ran without rental_notices.review");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_the_lease_actions_menu_offers_reverse_and_change_outcome_only_with_review(): void
    {
        $this->withActiveNotice();
        $renewOnly = $this->agentWith(['leases.view', 'leases.renew']);
        $reverse = "lease-dialog-reverse-notice')";

        $this->actingAs($renewOnly)->get(route('corex.leases.show', $this->lease))->assertOk()->assertDontSee($reverse, false);
        $this->grant($renewOnly, ['rental_notices.review']);
        $this->actingAs($renewOnly)->get(route('corex.leases.show', $this->lease))->assertOk()->assertSee($reverse, false);
    }

    // ── finding 4 ─────────────────────────────────────────────────────────

    private function seedGrants(): void
    {
        RolePermission::withTrashed()->forceDelete();
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'rental_notices.create', 'scope' => 'branch']);
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'leases.renew', 'scope' => 'own']);
    }

    public function test_the_grant_migration_leaves_a_deliberately_revoked_grant_revoked(): void
    {
        $this->seedGrants();
        // an administrator took .review away from the Agent role (a soft-deleted row) and it still holds leases.renew
        $revoked = RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'rental_notices.review', 'scope' => 'own']);
        $revoked->delete();

        $migration = require base_path(self::MIG_GRANT);
        $migration->up();
        $migration->up();
        PermissionService::clearCache();

        $this->assertTrue(RolePermission::withTrashed()->findOrFail($revoked->id)->trashed(), 'the revoked grant stays revoked');
        $this->assertSame(0, RolePermission::where('role', 'agent')->where('permission_key', 'rental_notices.review')->count());
        // the grant that never existed is still created
        $this->assertSame(1, RolePermission::where('role', 'agent')->where('permission_key', 'rental_notices.view')->count());
    }

    // ── finding 2: the new key's grant ────────────────────────────────────

    public function test_the_authorise_grant_goes_to_every_role_holding_application_view_and_never_restores_a_revoked_row(): void
    {
        RolePermission::withTrashed()->forceDelete();
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'rental_applications.view', 'scope' => 'own']);
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'branch_manager', 'permission_key' => 'rental_applications.view', 'scope' => 'branch']);
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'viewer', 'permission_key' => 'leases.view', 'scope' => 'all']);
        $revoked = RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'branch_manager', 'permission_key' => 'rental_applications.authorise']);
        $revoked->delete();

        $migration = require base_path(self::MIG_AUTHORISE);
        $migration->up();
        $migration->up();

        $this->assertSame(1, RolePermission::where('role', 'agent')->where('permission_key', 'rental_applications.authorise')->count());
        $this->assertSame(0, RolePermission::where('role', 'viewer')->where('permission_key', 'rental_applications.authorise')->count());
        $this->assertTrue(RolePermission::withTrashed()->findOrFail($revoked->id)->trashed(), 'a revoked row stays revoked');
        $this->assertContains('rental_applications.authorise', array_column(config('corex-permissions.permissions'), 'key'));
    }

    // ── the note: template HTML ───────────────────────────────────────────

    public function test_the_cleaner_removes_everything_active_and_keeps_the_letter(): void
    {
        $dirty = '<h2 onclick="x()">Notice</h2><p style="color:red">Hello <b>{{tenant_name}}</b> <a href="javascript:alert(1)">bad</a> <a href=" JaVa&#x0A;script:alert(1)">bad2</a> <a href="https://example.test/ok" onmouseover="y()">ok</a></p>'
            . '<script>alert(1)</script><iframe src="https://evil.test"></iframe><img src="x" onerror="z()"><img src="data:image/png;base64,AAAA"><img src="data:text/html;base64,AAAA">'
            . '<form action="/x"><input name="a"></form><svg onload="q()"><circle/></svg><!-- comment --><p style="width:expression(alert(1))">e</p><ul><li>one</li></ul><table><tr><td>cell</td></tr></table>';

        $clean = NoticeHtmlCleaner::clean($dirty);

        foreach (['onclick', 'onerror', 'onmouseover', 'onload', 'javascript', 'java', '<script', '<iframe', '<form', '<input', '<svg', 'text/html', 'expression', 'comment', 'alert'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $clean, "'{$bad}' is gone");
        }
        foreach (['<h2>Notice</h2>', '{{tenant_name}}', '<b>', 'href="https://example.test/ok"', 'data:image/png;base64,AAAA', '<li>one</li>', '<td>cell</td>', 'color:red'] as $kept) {
            $this->assertStringContainsString($kept, $clean, "'{$kept}' is kept");
        }
        $this->assertSame('Plain {{x}} text', NoticeHtmlCleaner::clean('Plain {{x}} text'));
        $this->assertSame('', NoticeHtmlCleaner::clean(null));
    }

    public function test_a_template_saved_with_script_is_stored_clean_and_an_old_dirty_one_renders_clean(): void
    {
        $body = '<p>Dear {{tenant_name}}</p><script>alert("saved")</script><p onclick="x()">Pay by {{remedy_deadline}}</p>';
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), ['name' => 'Dirty', 'notice_type' => 'breach_other', 'body_html' => $body])
            ->assertSessionHasNoErrors();
        $saved = RentalNoticeTemplate::withoutGlobalScopes()->where('name', 'Dirty')->firstOrFail();
        $this->assertStringNotContainsString('<script', $saved->body_html);
        $this->assertStringNotContainsString('onclick', $saved->body_html);
        $this->assertStringContainsString('{{tenant_name}}', $saved->body_html);

        // a template saved BEFORE the clean-up (dirty in the database) is cleaned again when it is shown
        $old = RentalNoticeTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Old', 'notice_type' => 'breach_other', 'is_active' => true, 'body_html' => $body]);
        $html = app(\App\Services\Rentals\RentalNoticeService::class)->render($old, ['tenant_name' => 'Tina <b>Tenant</b>'], $this->lease);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('Dear Tina &lt;b&gt;Tenant&lt;/b&gt;', $html, 'placeholder values are still escaped');
    }
}
