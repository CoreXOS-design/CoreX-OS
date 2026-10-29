<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\ComplianceDocumentAudit;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVersion;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\StaffTrainingService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * "Build staff training from this version" - one shared service for an RMCP version and a policy version (compliance.md).
 */
final class StaffTrainingTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private int $agencyId;
    private User $admin;
    private User $signed;
    private User $unsigned;
    private User $inactive;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        Mail::fake();

        $this->agencyId = $this->seedAgency();
        $this->admin    = $this->makeUser($this->agencyId);
        $this->signed   = $this->makeUser($this->agencyId, 'agent');
        $this->unsigned = $this->makeUser($this->agencyId, 'agent');
        $this->inactive = $this->makeUser($this->agencyId, 'agent');
        $this->inactive->forceFill(['is_active' => false])->save();
        $this->stranger = $this->makeUser($this->seedAgency(), 'agent');
    }

    private function told(User $u): int
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->where('notifiable_type', get_class($u))->count();
    }

    private function rmcp(int $number, string $status): RmcpVersion
    {
        $v = RmcpVersion::withoutGlobalScopes()->create(['agency_id' => $this->agencyId, 'version_number' => $number, 'title' => 'RMCP', 'status' => $status,
            'approved_by' => $status === 'draft' ? null : $this->admin->id, 'approved_at' => $status === 'draft' ? null : now()->subWeek()]);
        RmcpSection::withoutGlobalScopes()->create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section',
            'display_order' => 1, 'section_number' => '1', 'title' => 'Purpose', 'body_html' => '<p>Why.</p>']);

        return $v;
    }

    private function rmcpAck(User $u, RmcpVersion $v, string $status = 'completed'): RmcpAcknowledgement
    {
        return RmcpAcknowledgement::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v->id, 'user_id' => $u->id, 'status' => $status,
            'started_at' => now()->subDay(), 'completed_at' => $status === 'completed' ? now()->subDay() : null,
            'valid_until' => $status === 'completed' ? now()->addMonths(6) : null, 'sections_acknowledged_count' => 1, 'sections_total_count' => 1]);
    }

    public function test_counts_only_active_staff_of_the_agency_and_those_who_have_not_signed_this_version(): void
    {
        $v1 = $this->rmcp(1, 'superseded');
        $v2 = $this->rmcp(2, 'active');
        $this->rmcpAck($this->signed, $v2);
        $this->rmcpAck($this->unsigned, $v1); // signed an EARLIER version: still has to sign this one

        $c = app(StaffTrainingService::class)->counts($v2);

        self::assertSame(['total' => 3, 'signed' => 1, 'asked' => 2], $c, 'admin + signed + unsigned; not the inactive user or the other agency');
    }

    public function test_sending_an_rmcp_supersedes_earlier_signatures_asks_only_the_unsigned_and_records_the_send(): void
    {
        $v1 = $this->rmcp(1, 'superseded');
        $v2 = $this->rmcp(2, 'active');
        $old = $this->rmcpAck($this->unsigned, $v1);
        $mine = $this->rmcpAck($this->signed, $v2);

        $r = app(StaffTrainingService::class)->send($v2, $this->admin, StaffTrainingService::MODE_BUTTON);

        self::assertSame(1, $r['superseded']);
        self::assertSame('superseded', $old->fresh()->status, 'kept, never deleted');
        self::assertSame('completed', $mine->fresh()->status, 'a valid signature on THIS version is left alone');
        self::assertSame(2, $r['asked']);
        self::assertSame(1, $this->told($this->admin));
        self::assertSame(1, $this->told($this->unsigned));
        self::assertSame(0, $this->told($this->signed), 'already signed this version');
        self::assertSame(0, $this->told($this->inactive));
        self::assertSame(0, $this->told($this->stranger));
        Mail::assertNothingSent();

        $v = $v2->fresh();
        self::assertNotNull($v->staff_training_sent_at);
        self::assertSame(2, (int) $v->staff_training_sent_count);
        self::assertSame($this->admin->id, (int) $v->staff_training_sent_by);
        $audit = ComplianceDocumentAudit::withoutGlobalScopes()->where('version_id', $v2->id)->where('event_type', 'training_sent')->first();
        self::assertSame('rmcp', $audit->document_kind);
        self::assertSame(2, $audit->meta['asked']);
    }

    public function test_pressing_again_asks_again_but_the_approval_mode_asks_everyone(): void
    {
        $v2 = $this->rmcp(2, 'active');
        $svc = app(StaffTrainingService::class);

        $svc->send($v2, $this->admin);
        $svc->send($v2, $this->admin);
        self::assertSame(2, $this->told($this->unsigned), 'a deliberate second press is not swallowed by the notification gateway');

        $this->rmcpAck($this->signed, $v2);
        $r = $svc->send($v2, $this->admin, StaffTrainingService::MODE_APPROVAL);
        self::assertSame(3, $r['asked'], 'approval has always asked every active staff member');
    }

    public function test_the_button_needs_the_approve_permission_and_an_active_version_and_names_the_numbers(): void
    {
        $v2 = $this->rmcp(2, 'active');
        $draft = $this->rmcp(3, 'draft');

        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $v2))
            ->assertOk()->assertSee('Build staff training from this version')->assertSee('3 of 3 active staff will be asked', false)
            ->assertSee('Not sent yet · 0 of 3 signed', false);
        $this->get(route('compliance.rmcp.show', $draft))->assertOk()->assertDontSee('Build staff training from this version');

        $this->post(route('compliance.rmcp.staff-training', $draft))->assertRedirect()->assertSessionHas('error');
        self::assertSame(0, $this->told($this->unsigned), 'a draft is not in force, so nobody is asked');

        $this->post(route('compliance.rmcp.staff-training', $v2))->assertRedirect(route('compliance.rmcp.show', $v2))->assertSessionHas('success');
        self::assertSame(1, $this->told($this->unsigned));
        $this->get(route('compliance.rmcp.show', $v2))->assertSee('Last sent', false)->assertSee('0 of 3 signed', false);

        // Without approve_rmcp: refused and nothing sent.
        Auth::logout();
        foreach (['access_rmcp'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $this->agencyId]);
        }
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_rmcp', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();
        $before = $this->told($this->unsigned);
        $this->actingAs($this->signed)->post(route('compliance.rmcp.staff-training', $v2))->assertForbidden();
        self::assertSame($before, $this->told($this->unsigned));
        $this->get(route('compliance.rmcp.show', $v2))->assertOk()->assertDontSee('Build staff training from this version');
    }

    public function test_the_rmcp_dashboard_prints_last_sent_and_how_many_signed(): void
    {
        $v2 = $this->rmcp(2, 'active');
        $this->rmcpAck($this->signed, $v2);
        $this->actingAs($this->admin);

        $this->get(route('compliance.rmcp.dashboard.index'))->assertOk()->assertSee('Staff training not sent yet · 1 of 3 signed', false);

        app(StaffTrainingService::class)->send($v2->fresh(), $this->admin);
        $this->get(route('compliance.rmcp.dashboard.index'))->assertOk()->assertSee('Last sent', false)->assertSee('· 1 of 3 signed', false);
    }

    public function test_a_policy_is_sent_the_same_way_and_only_its_own_earlier_signatures_are_superseded(): void
    {
        $policy = $this->makePolicy($this->agencyId, 'direct_marketing', 'Direct Marketing Policy');
        $other  = $this->makePolicy($this->agencyId, 'other_policy', 'Other Policy');
        $p1 = $this->makeVersion($policy, 1, 'superseded');
        $p2 = $this->makeVersion($policy, 2, 'active');
        $o1 = $this->makeVersion($other, 1, 'active');
        $oldAck   = $this->completeAck($this->unsigned, $p1);
        $otherAck = $this->completeAck($this->unsigned, $o1);
        $this->completeAck($this->signed, $p2);

        $r = app(StaffTrainingService::class)->send($p2, $this->admin);

        self::assertSame('superseded', $oldAck->fresh()->status);
        self::assertSame('completed', $otherAck->fresh()->status, 'another policy is untouched');
        self::assertSame(2, $r['asked']);
        self::assertSame(1, $this->told($this->unsigned));
        self::assertSame(0, $this->told($this->signed));
        self::assertSame('policy', ComplianceDocumentAudit::withoutGlobalScopes()->where('version_id', $p2->id)->first()->document_kind);

        // Page, button and dashboard.
        $this->actingAs($this->admin)->get(route('compliance.policy.show', $p2))
            ->assertOk()->assertSee('Build staff training from this version')->assertSee('Last sent', false)->assertSee('1 of 3 signed', false);
        $this->post(route('compliance.policy.staff-training', $p2))->assertRedirect(route('compliance.policy.show', $p2))->assertSessionHas('success');
        self::assertSame(2, $this->told($this->unsigned));
        $this->get(route('compliance.policy.dashboard.index', ['policy' => 'direct_marketing']))->assertOk()->assertSee('Last sent', false);
    }

    public function test_approving_a_policy_now_re_signs_staff_like_the_rmcp_does(): void
    {
        $policy = $this->makePolicy($this->agencyId, 'direct_marketing', 'Direct Marketing Policy');
        $p1 = $this->makeVersion($policy, 1, 'active');
        $p2 = $this->makeVersion($policy, 2, 'draft');
        $old = $this->completeAck($this->unsigned, $p1);

        $this->actingAs($this->admin)->post(route('compliance.policy.approve', $p2), [
            'approver_title' => 'Principal', 'effective_from' => now()->toDateString(), 'next_review_due' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('compliance.policy.show', $p2))->assertSessionHas('success');

        self::assertSame('active', $p2->fresh()->status);
        self::assertSame('superseded', $old->fresh()->status, 'the earlier signature stops counting');
        self::assertSame('not_started', $this->unsigned->fresh()->policyAcknowledgementStatus('direct_marketing'));
        self::assertGreaterThanOrEqual(1, $this->told($this->unsigned), 'staff are told in-app');
        self::assertSame(0, $this->told($this->stranger));
        self::assertNotNull($p2->fresh()->staff_training_sent_at);
    }
}
