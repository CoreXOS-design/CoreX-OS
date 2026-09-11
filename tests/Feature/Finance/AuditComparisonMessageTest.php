<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\FinanceAuditRun;
use App\Services\Finance\RollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DR2 Financial Audit F3 (AT-409) — every severity=error (and every
 * severity=warn) row this self-audit tool has ever written carried a fixed
 * message naming a root cause ("engine uses period field; legacy uses
 * deal_date") that does not correspond to any code that currently exists —
 * both RollupService and every Legacy reader bucket by deals.period alike.
 * The message described an earlier version of this logic and was never
 * updated when it changed, misdirecting the AT-408/F2 investigation this
 * audit itself did until the real deals were checked by hand.
 *
 * Fixed by stating only what was actually measured (the real expected/
 * actual/diff numbers), never a presumed cause that can go stale again
 * silently. Uses reflection to invoke the private writeComparisonItem()
 * directly with controlled inputs — exercising the real shadow-audit
 * pipeline end-to-end (seeding data that makes RollupService and a Legacy
 * reader genuinely disagree) is a much larger undertaking outside this
 * finding's scope; this proves the message-generation logic itself, which
 * is exactly what was wrong.
 */
final class AuditComparisonMessageTest extends TestCase
{
    use RefreshDatabase;

    private function writeComparisonItem(FinanceAuditRun $run, float $expected, ?float $actual): \App\Models\FinanceAuditItem
    {
        $service = new RollupService();
        $method = new \ReflectionMethod(RollupService::class, 'writeComparisonItem');
        $method->setAccessible(true);
        $method->invoke($service, $run, 'company_period', 1, '2026-06', 'company_period.money.total_nondeclined.ledger_company_income_ex_vat', $expected, $actual);

        return \App\Models\FinanceAuditItem::where('audit_run_id', $run->id)->latest('id')->first();
    }

    private function makeRun(int $agencyId): FinanceAuditRun
    {
        return FinanceAuditRun::create([
            'agency_id' => $agencyId, 'period' => '2026-06', 'scope' => json_encode(['trigger' => 'test']),
            'status' => 'running', 'engine_version' => 'v0', 'started_at' => now(),
        ]);
    }

    public function test_an_error_row_states_the_real_numbers_not_a_presumed_cause(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Audit Msg Co', 'slug' => 'am-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = $this->makeRun($agencyId);

        $item = $this->writeComparisonItem($run, 1000.00, 950.00);

        $this->assertSame('error', $item->severity);
        $this->assertStringNotContainsString('deal_date', $item->message, 'the old message named a root cause that does not exist in the code — must never reappear');
        $this->assertStringContainsString('1000', $item->message);
        $this->assertStringContainsString('950', $item->message);
        $this->assertStringContainsString('investigate directly', $item->message);
    }

    public function test_a_warn_row_no_longer_names_deal_date_as_the_cause(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Audit Msg Co 2', 'slug' => 'am2-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = $this->makeRun($agencyId);

        $item = $this->writeComparisonItem($run, 1000.00, null);

        $this->assertSame('warn', $item->severity);
        $this->assertStringNotContainsString('deal_date', $item->message);
    }

    public function test_a_matching_row_is_unaffected(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Audit Msg Co 3', 'slug' => 'am3-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = $this->makeRun($agencyId);

        $item = $this->writeComparisonItem($run, 1000.00, 1000.005);

        $this->assertSame('info', $item->severity);
        $this->assertSame('match', $item->message);
    }
}
