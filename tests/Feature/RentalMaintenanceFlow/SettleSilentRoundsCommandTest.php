<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Events\Rentals\RentalCompletionSettledBySilence;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalCompletionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.10.8 — silence = accepted. `rentals:settle-completion-rounds` (daily) accepts a round
 * only AFTER its window, never touches a round the tenant answered (or disputed), never a round nobody was asked, settles every
 * agency on its own stored window, logs it, tells the agent, is idempotent — and afterwards the link and the portal refuse a
 * late answer with the plain "period has ended" sentence.
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

    public function test_a_round_is_accepted_only_after_its_window_has_passed(): void
    {
        $waiting = $this->openRound();                                        // window 5 days away
        $overdue = $this->openRound(null, ['window_ends_at' => now()->subMinute(), 'opened_at' => now()->subDays(5)]);

        $this->artisan('rentals:settle-completion-rounds')->expectsOutputToContain('Settled 1 completion round')->assertSuccessful();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $waiting->fresh()->outcome);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, $overdue->fresh()->outcome);
    }

    public function test_it_never_touches_an_answered_disputed_or_unasked_round(): void
    {
        // answered while the window was still open — then the window runs out
        $confirmed = $this->openRound();
        app(RentalCompletionService::class)->respond($confirmed, true, null, [], ['contact' => $this->tenant, 'via' => 'portal']);
        $confirmed->forceFill(['window_ends_at' => now()->subDay()])->save();

        $disputed = $this->openRound();
        app(RentalCompletionService::class)->respond($disputed, false, 'It is still broken', [], ['contact' => $this->tenant, 'via' => 'portal']);
        $disputed->forceFill(['window_ends_at' => now()->subDay()])->save();

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_completion_check_enabled' => false]);
        $unasked = $this->openRound();

        $this->artisan('rentals:settle-completion-rounds')->expectsOutputToContain('Settled 0')->assertSuccessful();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $confirmed->fresh()->outcome);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $disputed->fresh()->outcome);
        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $disputed->workOrder->fresh()->status, 'a dispute is never settled away');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_NO_TENANT, $unasked->fresh()->outcome);
    }

    public function test_it_logs_who_was_not_heard_from_tells_the_agent_and_announces_it(): void
    {
        Event::fake([RentalCompletionSettledBySilence::class]);
        $round = $this->openRound(null, ['window_ends_at' => now()->subHour(), 'opened_at' => now()->subDays(5)]);

        app(RentalCompletionService::class)->settleSilent();

        $log = $round->workOrder->updates()->where('update_type', 'completion_accepted')->sole();
        $this->assertSame("Round 1: no response in 5 days — accepted", $log->note);
        $this->notifier->shouldHaveReceived('fire')->withArgs(fn ($u, $key) => $key === 'rental_work_order.completion_accepted' && $u->id === $this->admin->id)->once();
        Event::assertDispatched(RentalCompletionSettledBySilence::class, fn ($e) => $e->round->id === $round->id);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $round->workOrder->fresh()->status, 'accepted by silence changes no stage');
    }

    public function test_running_it_twice_settles_nothing_the_second_time(): void
    {
        $this->openRound(null, ['window_ends_at' => now()->subHour()]);

        $this->assertSame(1, app(RentalCompletionService::class)->settleSilent());
        $this->assertSame(0, app(RentalCompletionService::class)->settleSilent());
    }

    public function test_every_agency_settles_on_its_own_stored_window(): void
    {
        [$capeAgency, $capeAgent, $capeProperty, $capeLease, $capeTenant] = $this->otherAgencyWorld();
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $capeAgency->id], ['completion_response_window_days' => 1]);
        $capeOrder = $this->makeWorkOrder($capeAgency, $capeProperty, $capeLease, ['created_by_user_id' => $capeAgent->id, 'status' => 'in_progress']);
        $capeRound = app(RentalCompletionService::class)->openRound($capeOrder, ['reported_by_label' => 'Cape crew', 'reported_via' => 'crew_link']);

        $this->assertTrue($capeRound->window_ends_at->between(now()->addHours(23), now()->addHours(25)), 'the Cape Town agency set ONE day, not the default five');
        $homeRound = $this->openRound();

        $capeRound->forceFill(['window_ends_at' => now()->subMinute()])->save();
        app(RentalCompletionService::class)->settleSilent();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, $capeRound->fresh()->outcome);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $homeRound->fresh()->outcome, 'the other agency is untouched');
        $this->notifier->shouldHaveReceived('fire')->withArgs(fn ($u, $key) => $key === 'rental_work_order.completion_accepted' && $u->id === $capeAgent->id)->once();
    }

    public function test_a_cancelled_or_archived_work_order_is_settled_quietly_with_no_notification(): void
    {
        $cancelled = $this->openRound(null, ['window_ends_at' => now()->subHour()]);
        $cancelled->workOrder->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
        $archived = $this->openRound(null, ['window_ends_at' => now()->subHour()]);
        $archived->workOrder->delete();

        $this->assertSame(2, app(RentalCompletionService::class)->settleSilent());

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, $cancelled->fresh()->outcome);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, $archived->fresh()->outcome);
        $this->notifier->shouldNotHaveReceived('fire', fn ($u, $key) => $key === 'rental_work_order.completion_accepted');
    }

    public function test_after_the_nightly_settle_a_late_answer_is_refused_on_the_link_and_the_portal(): void
    {
        $round = $this->openRound(null, ['window_ends_at' => now()->subHour()]);
        $raw = basename(parse_url($this->mailer->sentOf(\App\Mail\Rentals\RentalTenantCompletionCheckMail::class)[0][1]->url, PHP_URL_PATH));
        app(RentalCompletionService::class)->settleSilent();

        $this->get('/secure/completion/' . $raw)->assertOk()->assertSee('The response period has ended — please report a new fault.');
        $this->post('/secure/completion/' . $raw, ['answer' => 'not_fixed', 'note' => 'Too late but still wrong'])->assertRedirect('/secure/completion/' . $raw);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $this->postJson("/api/v1/client/rentals/work-orders/{$round->rental_work_order_id}/completion-response", ['fixed' => false, 'note' => 'Too late but still wrong'])
            ->assertStatus(422)->assertJsonPath('message', 'The response period has ended — please report a new fault.');

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, $round->fresh()->outcome);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $round->workOrder->fresh()->status);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'rentals:settle-completion-rounds'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression, 'daily');
    }
}
