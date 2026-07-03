<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS0 (AT-158 / DR2) — the RAG timer gate.
 *
 * `deals:process-rag` must, purely by the passage of (frozen) time, transition
 * a step's persisted current_rag green→amber→red→overdue, refresh the deal's
 * cached overall_rag (the previously-uncalled updateDealOverallRag), flip the
 * step to 'overdue', AND repaint the linked calendar event's colour + status —
 * so the deal board and the calendar tile agree without any user activity.
 */
final class ProcessDealRagTest extends TestCase
{
    use RefreshDatabase;

    public function test_rag_timer_transitions_step_deal_and_calendar_on_the_clock(): void
    {
        // Offer today; a single on_creation step due in 20 days.
        // Thresholds: green ≥15 left, amber 7–14, red ≤3, overdue past due.
        Carbon::setTestNow('2026-03-01 09:00:00');
        $deal = $this->makeSingleStepDeal(dueOffsetDays: 20, green: 15, amber: 7, red: 3);
        $step = $deal->stepInstances()->first();
        $this->assertSame('2026-03-21', $step->due_date->format('Y-m-d'));

        // Baseline: created green, calendar event painted green.
        $this->assertSame('green', $step->current_rag);
        $this->assertSame('green', $deal->fresh()->overall_rag);
        $this->assertSame(DealPipelineService::ragColour('green'), $this->event($step)->colour);

        // calculateRag zones: red when ≤ red_days(3), amber when ≤ amber_days(7),
        // else green; overdue when past due. (green_days is not used by the
        // engine's RAG — see WS0 note.)
        // 6 days remaining (03-15) → AMBER.
        $this->assertRagAt('2026-03-15 09:00:00', $step, $deal, 'amber', 'active', 'pending');

        // 2 days remaining (03-19) → RED.
        $this->assertRagAt('2026-03-19 09:00:00', $step, $deal, 'red', 'active', 'pending');

        // 1 day PAST due (03-22) → OVERDUE (step + event flip).
        $this->assertRagAt('2026-03-22 09:00:00', $step, $deal, 'overdue', 'overdue', 'overdue');
    }

    public function test_rag_timer_is_idempotent_and_skips_non_active_deals(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        $deal = $this->makeSingleStepDeal(20, 15, 7, 3);
        $step = $deal->stepInstances()->first();

        Carbon::setTestNow('2026-03-15 09:00:00'); // 6 remaining → amber
        Artisan::call('deals:process-rag');
        $firstColour = $this->event($step)->colour;
        $firstUpdated = $this->event($step)->updated_at;

        // Re-run at the same instant: no change (idempotent — event not re-touched).
        Artisan::call('deals:process-rag');
        $this->assertSame('amber', $step->fresh()->current_rag);
        $this->assertEquals($firstUpdated, $this->event($step)->updated_at, 're-run must not rewrite an unchanged event');
        $this->assertSame($firstColour, $this->event($step)->colour);

        // On-hold deal: the timer must not accrue RAG.
        $deal->update(['status' => 'on_hold']);
        Carbon::setTestNow('2026-03-25 09:00:00'); // well past due
        Artisan::call('deals:process-rag');
        $this->assertSame('amber', $step->fresh()->current_rag, 'on-hold deal step RAG is frozen');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function assertRagAt(string $when, DealStepInstance $step, $deal, string $rag, string $stepStatus, string $eventStatus): void
    {
        Carbon::setTestNow($when);
        Artisan::call('deals:process-rag');

        $step->refresh();
        $this->assertSame($rag, $step->current_rag, "step current_rag at {$when}");
        $this->assertSame($stepStatus, $step->status, "step status at {$when}");
        $this->assertSame($rag, $deal->fresh()->overall_rag, "deal overall_rag at {$when}");

        $ev = $this->event($step);
        $this->assertSame(DealPipelineService::ragColour($rag), $ev->colour, "calendar colour at {$when}");
        $this->assertSame($eventStatus, $ev->status, "calendar status at {$when}");
    }

    private function event(DealStepInstance $step): CalendarEvent
    {
        return CalendarEvent::withoutGlobalScopes()
            ->where('source_type', DealStepInstance::class)
            ->where('source_id', $step->id)
            ->where('category', 'deal_step_deadline')
            ->firstOrFail();
    }

    private function makeSingleStepDeal(int $dueOffsetDays, int $green, int $amber, int $red)
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '5 Ocean Rd', 'address' => '5 Ocean Rd',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $template = DealPipelineTemplate::create([
            'name' => 'One Step', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $agent->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'position' => 1, 'name' => 'Bond Due',
            'is_locked' => false, 'is_milestone' => true, 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => $dueOffsetDays,
            'rag_green_days' => $green, 'rag_amber_days' => $amber, 'rag_red_days' => $red,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
        ]);

        return app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_500_000,
            'commission_amount' => 75_000, 'commission_vat' => 11_250, 'offer_date' => '2026-03-01',
            'branch_id' => $agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);
    }
}
