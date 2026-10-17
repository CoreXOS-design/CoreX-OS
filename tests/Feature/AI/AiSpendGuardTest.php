<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AiCopyUnavailableException;
use App\Mail\AiSpendAlertMail;
use App\Models\AI\AiSpendGuardEvent;
use App\Models\AI\AiUsageEvent;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\AiUsageRecorder;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\AI\Ellie\EllieAgentService;
use App\Services\MarketingCopyService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-456 Part A — AI Spend Safety. Proves every acceptance line in
 * .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.6:
 * alert once per window, cut-out per agency only, refusal on every surface,
 * resume without instant re-trip (audited), thresholds live from Dev Settings.
 */
class AiSpendGuardTest extends TestCase
{
    use RefreshDatabase;

    private AiSpendGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        $this->guard = app(AiSpendGuard::class);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── Alert ────────────────────────────────────────────────────────────

    public function test_below_the_alert_level_nothing_happens(): void
    {
        $agency = $this->agency('Quiet Co');
        $this->spend($agency->id, 29.99);

        $this->guard->evaluate($agency->id);

        Mail::assertNothingSent();
        $this->assertSame(AiSpendGuard::STATUS_ACTIVE, $this->guard->status($agency->id));
    }

    public function test_reaching_the_alert_level_sends_exactly_one_email_per_window(): void
    {
        $agency = $this->agency('Busy Co');
        $this->spend($agency->id, 30.00);

        $this->guard->evaluate($agency->id);
        $this->spend($agency->id, 5.00);
        $this->guard->evaluate($agency->id);

        Mail::assertSent(AiSpendAlertMail::class, 1);
        Mail::assertSent(AiSpendAlertMail::class, fn (AiSpendAlertMail $m) => $m->kind === AiSpendAlertMail::KIND_ALERT
            && $m->hasTo('andre@corexos.co.za')
            && $m->agencyName === 'Busy Co');
        $this->assertSame(AiSpendGuard::STATUS_ALERTED, $this->guard->status($agency->id));
        $this->assertFalse($this->guard->isPaused($agency->id), 'An alert never switches anything off.');
        $this->assertSame(1, AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'alert')->count());
    }

    public function test_spend_older_than_the_window_does_not_count(): void
    {
        $agency = $this->agency('Yesterday Co');
        $this->spend($agency->id, 60.00, now()->subHours(25));

        $this->guard->evaluate($agency->id);

        Mail::assertNothingSent();
        $this->assertFalse($this->guard->isPaused($agency->id));
    }

    public function test_the_recorder_trips_the_guard_after_a_costed_call(): void
    {
        config()->set('services.anthropic.usd_to_zar', 20.0);
        config()->set('services.anthropic.pricing', ['claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00]]);
        $agency = $this->agency('Recorder Co');

        // 3M input tokens × $1/M × R20 = R60 → straight past the cut-out.
        app(AiUsageRecorder::class)->record(
            source: AiUsageEvent::SOURCE_MARKETING_COPY,
            model: 'claude-haiku-4-5',
            inputTokens: 3_000_000,
            outputTokens: 0,
            agencyId: $agency->id,
        );

        $this->assertTrue($this->guard->isPaused($agency->id));
    }

    // ── Cut-out ──────────────────────────────────────────────────────────

    public function test_reaching_the_cutout_pauses_only_that_agency(): void
    {
        $hot  = $this->agency('Hot Co');
        $calm = $this->agency('Calm Co');
        $this->spend($hot->id, 50.00);
        $this->spend($calm->id, 10.00);

        $this->guard->evaluate($hot->id);
        $this->guard->evaluate($calm->id);

        $this->assertTrue($this->guard->isPaused($hot->id));
        $this->assertFalse($this->guard->isPaused($calm->id), 'Another agency is never affected.');

        // The cut-out notice supersedes the alert — one email, the cut-out one.
        Mail::assertSent(AiSpendAlertMail::class, 1);
        Mail::assertSent(AiSpendAlertMail::class, fn (AiSpendAlertMail $m) => $m->kind === AiSpendAlertMail::KIND_CUTOUT);

        $hot->refresh();
        $this->assertNotNull($hot->ai_paused_at);
        $this->assertSame('50.0000', (string) $hot->ai_paused_spend_zar);

        $this->guard->assertAllowed($calm->id); // no exception

        $this->expectException(AiPausedException::class);
        $this->expectExceptionMessage('AI is paused for your agency — please contact CoreX support.');
        $this->guard->assertAllowed($hot->id);
    }

    public function test_a_paused_agency_is_refused_by_the_gateway_without_calling_anthropic(): void
    {
        Http::fake();
        $agency = $this->pausedAgency();

        $request = new NarrativeRequest(
            narrativeType: 'test',
            cacheKey: 'guard-test:' . $agency->id,
            modelAlias: 'fast',
            systemPrompt: 's',
            userPrompt: 'u',
            agencyId: $agency->id,
        );

        try {
            app(AnthropicGateway::class)->generate($request);
            $this->fail('Expected AiPausedException');
        } catch (AiPausedException $e) {
            $this->assertSame(AiPausedException::MESSAGE, $e->getMessage());
        }

        // With a fallback the surface degrades instead, and says why.
        $withFallback = new NarrativeRequest(
            narrativeType: 'test',
            cacheKey: 'guard-test-fb:' . $agency->id,
            modelAlias: 'fast',
            systemPrompt: 's',
            userPrompt: 'u',
            agencyId: $agency->id,
            fallbackData: ['text' => 'templated'],
        );
        $response = app(AnthropicGateway::class)->generate($withFallback);
        $this->assertTrue($response->fromFallback);
        $this->assertSame('templated', $response->outputText);
        $this->assertSame(AiPausedException::MESSAGE, $response->errorMessage);

        Http::assertNothingSent();
    }

    public function test_a_paused_agency_gets_the_plain_message_from_ad_copy(): void
    {
        Http::fake();
        $agency   = $this->pausedAgency();
        $property = new Property();
        $property->forceFill(['agency_id' => $agency->id]);

        try {
            (new MarketingCopyService())->generateAdCopy($property, 'facebook');
            $this->fail('Expected AiCopyUnavailableException');
        } catch (AiCopyUnavailableException $e) {
            $this->assertSame(AiPausedException::MESSAGE, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_paused_agency_stops_ellie_with_the_plain_message(): void
    {
        Http::fake();
        $agency = $this->pausedAgency();
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        $this->actingAs($user);

        $result = app(EllieAgentService::class)->answer('How many listings do I have?', $user);

        $this->assertFalse($result['ok']);
        $this->assertSame(AiPausedException::MESSAGE, $result['reply']);
        Http::assertNothingSent();
    }

    // ── Resume ───────────────────────────────────────────────────────────

    public function test_resume_turns_ai_back_on_without_an_instant_re_trip_and_is_audited(): void
    {
        $agency = $this->pausedAgency();
        $owner  = $this->owner();

        $this->actingAs($owner)
            ->post(route('admin.ai-usage.resume', ['agency' => $agency->id]))
            ->assertRedirect();

        $this->assertFalse($this->guard->isPaused($agency->id));

        // The R50 that paused it is still inside the last 24h — but it was spent
        // before the resume, so it no longer counts.
        $this->guard->evaluate($agency->id);
        $this->assertFalse($this->guard->isPaused($agency->id), 'Old spend must not re-pause a resumed agency.');
        $this->assertSame(0.0, $this->guard->windowSpend($agency->id));

        $audit = AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'resume')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('50.0000', (string) $audit->spend_zar);

        // New spend after the resume counts again.
        $this->spend($agency->id, 50.00, now()->addSecond());
        $this->guard->evaluate($agency->id);
        $this->assertTrue($this->guard->isPaused($agency->id));
    }

    public function test_only_the_platform_owner_can_turn_ai_back_on(): void
    {
        $agency = $this->pausedAgency();
        $admin  = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.ai-usage.resume', ['agency' => $agency->id]))
            ->assertForbidden();

        $this->assertTrue($this->guard->isPaused($agency->id));
    }

    // ── System bucket ────────────────────────────────────────────────────

    public function test_agency_less_spend_pauses_the_system_bucket_only(): void
    {
        $agency = $this->agency('Bystander Co');
        $this->spend(null, 50.00);

        $this->guard->evaluate(null);

        $this->assertTrue($this->guard->isPaused(null));
        $this->assertFalse($this->guard->isPaused($agency->id));

        $this->actingAs($this->owner())
            ->post(route('admin.ai-usage.system.resume'))
            ->assertRedirect();

        $this->assertFalse($this->guard->isPaused(null));
    }

    // ── Settings (Dev Settings → AI Spend Safety) ────────────────────────

    public function test_changing_a_threshold_in_dev_settings_changes_behaviour_without_a_deploy(): void
    {
        $agency = $this->agency('Threshold Co');
        $this->spend($agency->id, 12.00);
        $owner = $this->owner();

        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), $this->settingsPayload(5, 10, 24, ['ops@example.com']))
            ->assertRedirect();

        $this->guard->evaluate($agency->id);

        $this->assertTrue($this->guard->isPaused($agency->id), 'R12 is past the new R10 switch-off level.');
        Mail::assertSent(AiSpendAlertMail::class, fn (AiSpendAlertMail $m) => $m->hasTo('ops@example.com'));

        $audit = AiSpendGuardEvent::where('type', 'settings')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(10.0, (float) $audit->details['new']['cutout_zar']);
        $this->assertSame(50.0, (float) $audit->details['old']['cutout_zar']);
    }

    public function test_the_dev_settings_section_renders_with_the_current_values(): void
    {
        $this->actingAs($this->owner())
            ->get(route('admin.dev-settings.index', ['s' => 'ai_spend']))
            ->assertOk()
            ->assertSee('AI Spend Safety')
            ->assertSee('Switch-off level (R)')
            ->assertSee('name="ai_cutout_threshold_zar"', false)
            ->assertSee('andre@corexos.co.za');
    }

    public function test_a_switch_off_level_not_above_the_alert_level_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->put(route('admin.dev-settings.update'), $this->settingsPayload(40, 40, 24, ['ops@example.com']))
            ->assertRedirect(route('admin.dev-settings.index', ['s' => 'ai_spend']))
            ->assertSessionHasErrors('ai_spend');

        $this->assertSame(50.0, $this->guard->settings()['cutout_zar']);
        $this->assertSame(0, AiSpendGuardEvent::where('type', 'settings')->count());
    }

    public function test_an_empty_email_list_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->put(route('admin.dev-settings.update'), $this->settingsPayload(30, 50, 24, []))
            ->assertSessionHasErrors('ai_spend');

        $this->assertSame(['andre@corexos.co.za'], $this->guard->settings()['recipients']);
    }

    // ── AI usage page ────────────────────────────────────────────────────

    public function test_the_ai_usage_page_lists_status_and_filters_by_it(): void
    {
        $paused = $this->pausedAgency('Stopped Co');
        $this->agency('Running Co');

        $this->actingAs($this->owner())
            ->get(route('admin.ai-usage.index'))
            ->assertOk()
            ->assertSee('AI Spend Safety')
            ->assertSee('Stopped Co')
            ->assertSee('Running Co')
            ->assertSee('Turn AI back on')
            ->assertDontSee('Per-agency budgets');

        $this->actingAs($this->owner())
            ->get(route('admin.ai-usage.index', ['status' => 'paused']))
            ->assertOk()
            // Assert on the table's own rows — agency names also appear in the page chrome (agency switcher).
            ->assertViewHas('agencies', fn ($rows) => collect($rows->items())->pluck('name')->all() === ['Stopped Co']);

        $this->actingAs($this->owner())
            ->get(route('admin.ai-usage.index', ['search' => 'runn']))
            ->assertOk()
            ->assertViewHas('agencies', fn ($rows) => collect($rows->items())->pluck('name')->all() === ['Running Co']);

        $this->actingAs($this->owner())
            ->get(route('admin.ai-usage.agency', ['agency' => $paused->id]))
            ->assertOk()
            ->assertSee('AI paused')
            ->assertSee('Turn AI back on');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    private function pausedAgency(string $name = 'Paused Co'): Agency
    {
        $agency = $this->agency($name);
        $this->spend($agency->id, 50.00);
        $this->guard->evaluate($agency->id);
        $this->assertTrue($this->guard->isPaused($agency->id));

        return $agency;
    }

    private function spend(?int $agencyId, float $zar, $at = null): void
    {
        AiUsageEvent::create([
            'agency_id'   => $agencyId,
            'source'      => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model'       => 'claude-haiku-4-5',
            'cost_zar'    => $zar,
            'occurred_at' => $at ?? now()->subMinute(),
        ]);
    }

    private function owner(): User
    {
        // Same owner setup as AiUsageDashboardTest — is_owner is not mass-assignable.
        $role = \App\Models\Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        \App\Models\Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);
    }

    private function settingsPayload(float $alert, float $cutout, int $hours, array $emails): array
    {
        return [
            'ai_spend_present'        => '1',
            'ai_alert_threshold_zar'  => $alert,
            'ai_cutout_threshold_zar' => $cutout,
            'ai_guard_window_hours'   => $hours,
            'ai_alert_recipients'     => $emails,
        ];
    }
}
