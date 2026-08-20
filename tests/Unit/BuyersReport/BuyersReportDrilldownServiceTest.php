<?php

declare(strict_types=1);

namespace Tests\Unit\BuyersReport;

use App\Services\BuyersReport\BuyersReportDrilldownService;
use App\Services\Performance\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the multi-level "lost" drill Johan specified (2026-08-20,
 * lost-section redesign): "the agent who lost it is critical ... click real
 * losses and shows agent summary of losses and clicking that shows actual
 * buyers lost." Level 1 (agents) must reconcile exactly with level 2
 * (buyers) per agent, real/auto must never mix, and value must only ever
 * be summed from real losses (never auto — a system timeout never captured
 * a pre-approval value in the first place).
 *
 * DB approach: hand-built minimal schema, same ERROR-1419 workaround used
 * throughout tests/Unit/BuyersReport.
 */
final class BuyersReportDrilldownServiceTest extends TestCase
{
    private const AGENCY_ID = 9202;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    private function period(): Period
    {
        return new Period(Carbon::now()->subDays(1)->startOfDay()->toImmutable(), Carbon::now()->addDay()->endOfDay()->toImmutable(), 'This period', 'custom');
    }

    public function test_agent_summary_splits_real_and_auto_and_sums_only_real_value(): void
    {
        $agentA = 2001;
        $agentB = 2002;
        $this->seedUser($agentA, 'Agent A');
        $this->seedUser($agentB, 'Agent B');

        $this->seedLoss(1, $agentA, 'no_activity', null);       // auto
        $this->seedLoss(2, $agentA, 'chose_another', 800000);   // real, captured
        $this->seedLoss(3, $agentA, 'chose_another', null);     // real, not captured
        $this->seedLoss(4, $agentB, 'no_activity', null);       // auto

        $drill = app(BuyersReportDrilldownService::class);
        $userIds = [$agentA, $agentB];

        [$realTotal, $realRows] = $this->invokeRows($drill, 'lost', $userIds, 'real');
        [$autoTotal, $autoRows] = $this->invokeRows($drill, 'lost', $userIds, 'auto');

        $this->assertSame(2, $realTotal, 'Agent A has 2 real losses.');
        $this->assertSame(2, $autoTotal, 'Agent A + Agent B each have 1 auto loss.');

        $rowA = collect($realRows)->firstWhere('agent_id', $agentA);
        $this->assertSame(2, $rowA['count']);
        $this->assertSame(800000.0, $rowA['value'], 'Only the captured real loss value must be summed.');

        $rowAAuto = collect($autoRows)->firstWhere('agent_id', $agentA);
        $this->assertNull($rowAAuto['value'], 'Auto losses must never carry a value figure.');

        $rowBAuto = collect($autoRows)->firstWhere('agent_id', $agentB);
        $this->assertNull($rowBAuto['value']);
    }

    public function test_agent_summary_value_is_null_when_no_real_loss_ever_captured_a_value(): void
    {
        $agent = 2003;
        $this->seedUser($agent, 'No Value Agent');
        $this->seedLoss(5, $agent, 'chose_another', null);

        $drill = app(BuyersReportDrilldownService::class);
        [, $rows] = $this->invokeRows($drill, 'lost', [$agent], 'real');

        $row = collect($rows)->firstWhere('agent_id', $agent);
        $this->assertSame(1, $row['count']);
        $this->assertNull($row['value'], 'Nothing was ever captured -- must be null, not 0, so the UI reads "not captured" rather than R0.');
    }

    public function test_buyer_list_for_agent_reconciles_with_the_agent_summary_count_and_never_leaks_other_agents_or_subtypes(): void
    {
        $agentA = 2004;
        $agentB = 2005;
        $this->seedUser($agentA, 'Agent A');
        $this->seedUser($agentB, 'Agent B');

        $this->seedLoss(6, $agentA, 'chose_another', 500000, 'Alice Buyer');
        $this->seedLoss(7, $agentA, 'chose_another', null, 'Bob Buyer');
        $this->seedLoss(8, $agentA, 'no_activity', null, 'Auto Housekeeping'); // same agent, wrong subtype
        $this->seedLoss(9, $agentB, 'chose_another', 999999, 'Other Agents Buyer'); // different agent

        $drill = app(BuyersReportDrilldownService::class);

        $res = $drill->rows('lost', [$agentA], $this->period(), self::AGENCY_ID, null, 'real', 'buyers', $agentA);

        $this->assertSame(2, $res['count'], 'Only agent A\'s two REAL losses -- not the auto one, not agent B\'s.');
        $names = array_column($res['rows'], 'name');
        $this->assertContains('Alice Buyer', $names);
        $this->assertContains('Bob Buyer', $names);
        $this->assertNotContains('Auto Housekeeping', $names, 'Auto loss must not leak into the real buyer list.');
        $this->assertNotContains('Other Agents Buyer', $names, 'Another agent\'s loss must never appear here.');

        // Reconciliation: level-1 count for agent A (real) must equal level-2's total.
        [, $summaryRows] = $this->invokeRows($drill, 'lost', [$agentA, $agentB], 'real');
        $summaryForA = collect($summaryRows)->firstWhere('agent_id', $agentA);
        $this->assertSame($summaryForA['count'], $res['count'], 'The agent summary count must match the buyer-list total exactly.');
    }

    public function test_lost_value_metric_behaves_identically_to_lost_for_the_multilevel_drill(): void
    {
        $agent = 2006;
        $this->seedUser($agent, 'Value Metric Agent');
        $this->seedLoss(10, $agent, 'chose_another', 250000, 'Value Buyer');

        $drill = app(BuyersReportDrilldownService::class);
        $res = $drill->rows('lost_value', [$agent], $this->period(), self::AGENCY_ID, null, 'real', 'buyers', $agent);

        $this->assertSame(1, $res['count']);
        $this->assertSame(250000.0, $res['rows'][0]['value']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:array[]} */
    private function invokeRows(BuyersReportDrilldownService $drill, string $metric, array $userIds, string $subtype): array
    {
        $res = $drill->rows($metric, $userIds, $this->period(), self::AGENCY_ID, null, $subtype, 'agents');
        return [$res['count'], $res['rows']];
    }

    private function seedUser(int $id, string $name): void
    {
        DB::table('users')->insert(['id' => $id, 'agency_id' => self::AGENCY_ID, 'name' => $name]);
    }

    private function seedLoss(int $contactId, int $agentId, string $reasonCode, ?float $value, string $name = 'Test Buyer'): void
    {
        DB::table('contacts')->insert([
            'id' => $contactId, 'agency_id' => self::AGENCY_ID, 'agent_id' => $agentId,
            'first_name' => $name, 'last_name' => '',
        ]);
        DB::table('buyer_lost_records')->insert([
            'agency_id' => self::AGENCY_ID, 'contact_id' => $contactId,
            'agent_owner_user_id_at_loss' => $agentId,
            'reason_code' => $reasonCode, 'reason_label' => $reasonCode,
            'preapproval_amount_at_loss' => $value,
            'recorded_at' => Carbon::now(), 'recovered_at' => null,
        ]);
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('buyer_lost_records');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('users');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('contacts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('contact_type_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('buyer_lost_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('reason_code', 40)->nullable();
            $table->string('reason_label')->nullable();
            $table->decimal('preapproval_amount_at_loss', 12, 2)->nullable();
            $table->unsignedBigInteger('agent_owner_user_id_at_loss')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });
    }
}
