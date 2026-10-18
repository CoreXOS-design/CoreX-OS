<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.37 (T1, Johan 9 Oct 2026) - "silence = accepted" (§17.10.8) is RETIRED. The tenant's check is an optional record,
 * so `rentals:settle-completion-rounds` (still scheduled, harmless) settles nothing: no round is ever accepted by silence, no one is told, and a
 * tenant may still answer after the old window - on the link and on the portal.
 */
final class SettleSilentRoundsCommandTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $this->notifier);
    }

    private function openRound(?RentalWorkOrder $workOrder = null, array $overrides = []): RentalWorkCompletionRound
    {
        $workOrder ??= $this->internalJob()[0];
        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        if ($overrides) {
            $round->forceFill($overrides)->save();
        }

        return $round->fresh();
    }

    public function test_the_command_settles_nothing_even_long_after_the_old_window(): void
    {
        $old = $this->openRound(null, ['window_ends_at' => now()->subDays(30), 'opened_at' => now()->subDays(35)]);

        $this->artisan('rentals:settle-completion-rounds')->expectsOutputToContain('Settled 0')->assertSuccessful();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $old->fresh()->outcome);
        $this->assertSame(0, $old->workOrder->updates()->where('update_type', 'completion_accepted')->count());
        $this->notifier->shouldNotHaveReceived('fire', fn ($u, $key) => $key === 'rental_work_order.completion_accepted');
    }

    public function test_a_tenant_can_still_answer_after_the_old_window_on_the_link_and_the_portal(): void
    {
        $round = $this->openRound(null, ['window_ends_at' => now()->subHour()]);
        $raw = basename(parse_url($this->mailer->sentOf(\App\Mail\Rentals\RentalTenantCompletionCheckMail::class)[0][1]->url, PHP_URL_PATH));

        $this->get('/secure/completion/' . $raw)->assertOk()->assertDontSee('The response period has ended');

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $this->postJson("/api/v1/client/rentals/work-orders/{$round->rental_work_order_id}/completion-response", ['fixed' => false, 'note' => 'Late but still wrong'])
            ->assertOk();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->fresh()->outcome, 'stored as a record');
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $round->workOrder->fresh()->status, 'and it reopens nothing by itself');
    }

    public function test_a_cancelled_work_order_still_refuses_an_answer(): void
    {
        $cancelled = $this->openRound();
        $cancelled->workOrder->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $this->assertSame('This check is no longer open.', app(RentalCompletionService::class)->cannotRespondReason($cancelled->fresh()));
    }
}
