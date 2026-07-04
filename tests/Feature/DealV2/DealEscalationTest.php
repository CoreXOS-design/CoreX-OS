<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Mail\DealV2\DealDailyDigestMail;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepEscalation;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Notifications\DealV2\DealStepAlertNotification;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 DR2 WS6 — the notification/escalation gate.
 *
 * An overdue step with a 1-day-BM / 3-day-admin ladder must escalate to the BM
 * exactly once at +1 day and to admin exactly once at +3 days (frozen clock),
 * with NO duplicates when the hourly sweep re-runs. Plus: the RAG timer nudges
 * the responsible agent on the overdue edge, and the morning digest carries the
 * right content.
 */
final class DealEscalationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private User $bm;
    private User $admin;

    public function test_overdue_step_escalates_to_bm_at_1day_admin_at_3days_exactly_once(): void
    {
        Notification::fake();

        Carbon::setTestNow('2026-03-01 09:00:00');
        // Due 2026-03-11; ladder BM @ +1d overdue, admin @ +3d overdue.
        $deal = $this->makeDealWithLadder(dueOffsetDays: 10, ladder: [
            ['role' => 'branch_manager', 'days_overdue' => 1],
            ['role' => 'admin', 'days_overdue' => 3],
        ]);
        $step = $deal->stepInstances()->first();

        // +1 day overdue (03-12): flip overdue (rag timer) then escalate.
        Carbon::setTestNow('2026-03-12 09:00:00');
        Artisan::call('deals:process-rag');
        $this->assertSame('overdue', $step->fresh()->status);

        Artisan::call('deals:process-escalations');
        // BM fired exactly once; admin NOT yet (threshold 3 not reached).
        $this->assertSame(1, $this->escalations($step, 'escalation:branch_manager')->count());
        $this->assertSame(0, $this->escalations($step, 'escalation:admin')->count());
        Notification::assertSentToTimes($this->bm, DealStepAlertNotification::class, 1);
        Notification::assertNotSentTo($this->admin, DealStepAlertNotification::class);

        // Hourly re-run at the same instant: NO duplicate BM escalation.
        Artisan::call('deals:process-escalations');
        Artisan::call('deals:process-escalations');
        $this->assertSame(1, $this->escalations($step, 'escalation:branch_manager')->count(), 'BM rung must not re-fire');
        Notification::assertSentToTimes($this->bm, DealStepAlertNotification::class, 1);

        // +3 days overdue (03-14): admin fires exactly once; BM still not re-fired.
        Carbon::setTestNow('2026-03-14 09:00:00');
        Artisan::call('deals:process-escalations');
        $this->assertSame(1, $this->escalations($step, 'escalation:admin')->count());
        Notification::assertSentToTimes($this->admin, DealStepAlertNotification::class, 1);
        $this->assertSame(1, $this->escalations($step, 'escalation:branch_manager')->count());

        // Re-run again: still exactly one of each.
        Artisan::call('deals:process-escalations');
        $this->assertSame(1, $this->escalations($step, 'escalation:admin')->count());
        Notification::assertSentToTimes($this->admin, DealStepAlertNotification::class, 1);
    }

    public function test_rag_timer_nudges_the_responsible_agent_on_the_overdue_edge(): void
    {
        Notification::fake();

        Carbon::setTestNow('2026-03-01 09:00:00');
        $deal = $this->makeDealWithLadder(10, []);
        $step = $deal->stepInstances()->first();

        // Cross into overdue → the agent (responsible) is nudged once.
        Carbon::setTestNow('2026-03-12 09:00:00');
        Artisan::call('deals:process-rag');

        Notification::assertSentToTimes($this->agent, DealStepAlertNotification::class, 1);
        $this->assertSame(1, $this->escalations($step, 'rag:overdue')->count());

        // Re-run the timer at the same instant: no second nudge (idempotent).
        Artisan::call('deals:process-rag');
        $this->assertSame(1, $this->escalations($step, 'rag:overdue')->count());
    }

    public function test_daily_digest_carries_overdue_and_due_today_for_the_agent(): void
    {
        Mail::fake();

        Carbon::setTestNow('2026-03-12 09:00:00');
        // One step already overdue (due 03-11 via offset 10 from 03-01 would be
        // in the past; build with offset so it is overdue "as of now").
        $deal = $this->makeDealWithLadder(dueOffsetDays: 1, ladder: [], offerDate: '2026-03-11');
        Artisan::call('deals:process-rag'); // due 03-12 = today; mark rag

        Artisan::call('deals:daily-digest');

        Mail::assertSent(DealDailyDigestMail::class, function (DealDailyDigestMail $mail) {
            $hasContent = ! empty($mail->sections['due_today']) || ! empty($mail->sections['overdue']);
            return $mail->hasTo($this->agent->email) && $hasContent;
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function escalations(DealStepInstance $step, string $levelKey)
    {
        return DealStepEscalation::withoutGlobalScopes()
            ->where('deal_step_instance_id', $step->id)
            ->where('level_key', $levelKey)
            ->get();
    }

    private function makeDealWithLadder(int $dueOffsetDays, array $ladder, string $offerDate = '2026-03-01'): DealV2
    {
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        $this->bm    = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'branch_manager', 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '14 Marine Dr', 'address' => '14 Marine Dr, Margate',
            'agent_id' => $this->agent->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->agent->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'position' => 1, 'name' => 'Bond Approval',
            'is_locked' => false, 'is_milestone' => true, 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => $dueOffsetDays,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => true, 'notify_admin' => true,
            'escalation_config' => $ladder ? ['levels' => $ladder] : null,
        ]);

        return app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $this->agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_850_000,
            'commission_amount' => 92_500, 'commission_vat' => 13_875, 'offer_date' => $offerDate,
            'branch_id' => $this->agencyId, 'created_by_id' => $this->agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $this->agent->id]],
        ]);
    }
}
