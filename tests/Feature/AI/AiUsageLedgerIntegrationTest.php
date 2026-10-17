<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\AiUsageEvent;
use App\Models\Agency;
use App\Models\User;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\IntentExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end proof that the unified ledger captures the surfaces that were
 * previously invisible, and that AI Spend Safety (AT-456) sees that spend.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §9 (acceptance criteria 1, 3).
 */
class AiUsageLedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.usd_to_zar', 16.5);
        config()->set('services.anthropic.pricing', [
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        ]);
    }

    /**
     * The headline: a mobile Ellie voice call (IntentExtractionService) — which
     * recorded NOTHING before — now lands a mobile_voice ledger row with the
     * model's reported tokens and a non-zero cost.
     */
    public function test_mobile_voice_call_lands_a_ledger_row(): void
    {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"intent":"unknown","slots":{}}']],
                'usage'   => ['input_tokens' => 1_000_000, 'output_tokens' => 0],
                'model'   => 'claude-haiku-4-5-20251001',
            ], 200),
        ]);

        app(IntentExtractionService::class)->extract('what is the weather');

        $row = AiUsageEvent::query()->where('source', AiUsageEvent::SOURCE_MOBILE_VOICE)->firstOrFail();
        $this->assertSame(1_000_000, $row->input_tokens);
        $this->assertGreaterThan(0, (float) $row->cost_zar);
    }

    /**
     * Acceptance #3, carried into AT-456 — AI Spend Safety sees ledger spend
     * from ANY source (here: mobile_voice), not just MIC narratives.
     */
    public function test_spend_safety_sees_ledger_spend_from_any_source(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $agency = Agency::create(['name' => 'Capped Co', 'slug' => 'capped-co']);
        $guard  = app(AiSpendGuard::class);

        $this->assertFalse($guard->isPaused($agency->id), 'Empty ledger → not paused.');

        // R55 of mobile-voice spend in the last 24h — past the R50 cut-out.
        AiUsageEvent::create([
            'agency_id'    => $agency->id,
            'source'       => AiUsageEvent::SOURCE_MOBILE_VOICE,
            'model'        => 'claude-haiku-4-5',
            'input_tokens' => 0, 'output_tokens' => 0,
            'cost_zar'     => 55.00,
            'occurred_at'  => now(),
        ]);
        $guard->evaluate($agency->id);

        $this->assertTrue($guard->isPaused($agency->id), 'Mobile-voice spend must count toward the cut-out.');
    }

    /**
     * Cost attribution is isolated per agency — agency A's usage total never
     * includes agency B's rows. (Tenancy is enforced at write time on this
     * append-only admin-reporting ledger; see AiUsageEvent docblock.)
     */
    public function test_agency_usage_is_isolated(): void
    {
        $a = Agency::create(['name' => 'A Co', 'slug' => 'a-co']);
        $b = Agency::create(['name' => 'B Co', 'slug' => 'b-co']);

        AiUsageEvent::create([
            'agency_id' => $a->id, 'source' => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model' => 'claude-haiku-4-5', 'cost_zar' => 5.00, 'occurred_at' => now(),
        ]);
        AiUsageEvent::create([
            'agency_id' => $b->id, 'source' => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model' => 'claude-haiku-4-5', 'cost_zar' => 9.00, 'occurred_at' => now(),
        ]);

        $this->assertSame(5.0, $a->aiBudgetUsedZar());
        $this->assertSame(9.0, $b->aiBudgetUsedZar());
    }
}
