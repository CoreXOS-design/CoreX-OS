<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Exceptions\AI\AiPausedException;
use App\Http\Controllers\Api\MobileEllieVoiceController;
use App\Mail\AiSpendAlertMail;
use App\Models\AI\AiSpendGuardEvent;
use App\Models\AI\AiUsageEvent;
use App\Models\AI\AINarrativeCache;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\AI\IntentExtractionService;
use App\Services\AI\SpeechToTextService;
use App\Services\Docuperfect\ImporterAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AT-456 audit findings 1, 3, 4 and 6 (Part A spend safety + related AI plumbing).
 * Findings 2 and 5 live in AiCostCalculatorTest / OnMarketSqlMatchesPhpTest.
 */
final class AiSpendAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private AiSpendGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        config()->set('services.anthropic.max_retries', 3);
        $this->guard = app(AiSpendGuard::class);
    }

    // ── 1. PDF import: generous timeout, no retry of a timed-out document call ──

    public function test_the_pdf_import_asks_for_a_long_timeout(): void
    {
        $seen = [];
        Http::fake(function ($request, $options) use (&$seen) {
            $seen[] = $options['timeout'] ?? null;

            return Http::response([
                'content' => [['type' => 'text', 'text' => '{"fields":{"1":{"label":"Name"}},"insertable_blocks":[]}']],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 10],
            ], 200);
        });

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4 ' . Str::random(20));

        try {
            $result = app(ImporterAiService::class)->detectFromPdf($path);
        } finally {
            @unlink($path);
        }

        $this->assertNotEmpty($result['fields']);
        $this->assertSame([ImporterAiService::PDF_TIMEOUT_SECONDS], $seen);
        $this->assertGreaterThanOrEqual(120, ImporterAiService::PDF_TIMEOUT_SECONDS);
    }

    public function test_a_timed_out_document_request_is_sent_once_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds');
        });

        $request = new NarrativeRequest(
            narrativeType: 'doc_timeout', cacheKey: 'doc-timeout:' . uniqid(), modelAlias: 'quality',
            systemPrompt: 's', userPrompt: 'u', forceRefresh: true,
            fallbackData: ['text' => 'templated'],
            documents: [['type' => 'pdf', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF')]],
        );

        $response = app(AnthropicGateway::class)->generate($request);

        $this->assertTrue($response->fromFallback);
        $this->assertSame(1, $calls, 'A timed-out long document call must not be re-sent (it multiplies the cost).');
    }

    public function test_a_timed_out_request_without_documents_is_not_retried_either(): void
    {
        // AT-456 audit D-M1: a timeout means the provider may still bill the call, and re-sends leave no ledger
        // row - so no request is ever re-sent after a timeout, documents or not.
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $request = new NarrativeRequest(
            narrativeType: 'plain_timeout', cacheKey: 'plain-timeout:' . uniqid(), modelAlias: 'quality',
            systemPrompt: 's', userPrompt: 'u', forceRefresh: true, fallbackData: ['text' => 'templated'],
        );

        app(AnthropicGateway::class)->generate($request);

        $this->assertSame(1, $calls, 'A timed-out request must be sent once, never re-sent.');
    }

    public function test_a_connection_that_could_not_be_opened_is_still_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 7: Failed to connect to api.anthropic.com port 443');
        });

        $request = new NarrativeRequest(
            narrativeType: 'plain_connect', cacheKey: 'plain-connect:' . uniqid(), modelAlias: 'quality',
            systemPrompt: 's', userPrompt: 'u', forceRefresh: true, fallbackData: ['text' => 'templated'],
        );

        app(AnthropicGateway::class)->generate($request);

        $this->assertSame(3, $calls, 'Nothing was sent, so a connect failure is safe to retry.');
    }

    public function test_a_structured_answer_with_no_json_in_it_comes_back_unreadable_not_as_a_crash(): void
    {
        Http::fake(fn () => Http::response([
            'content' => [['type' => 'text', 'text' => 'sorry, I cannot do that']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ], 200));
        $request = new NarrativeRequest(
            narrativeType: 'plain_text_answer', cacheKey: 'plain-text:' . uniqid(), modelAlias: 'fast',
            systemPrompt: 's', userPrompt: 'u', forceRefresh: true, fallbackData: ['text' => 'templated'],
        );

        $response = app(AnthropicGateway::class)->generateStructured($request, ['keep' => [1]]);

        $this->assertNull($response->outputJson);
        $this->assertSame('sorry, I cannot do that', $response->outputText);
    }

    public function test_a_failed_call_with_no_fallback_leaves_a_zero_cost_ledger_row(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        $agency = $this->agency('Failing Co');

        $request = new NarrativeRequest(
            narrativeType: 'no_fallback', cacheKey: 'no-fallback:' . uniqid(), modelAlias: 'quality',
            systemPrompt: 's', userPrompt: 'u', forceRefresh: true, agencyId: $agency->id,
        );

        try {
            app(AnthropicGateway::class)->generate($request);
            $this->fail('A failed call with no fallback must throw.');
        } catch (\App\Exceptions\AI\NarrativeGenerationException) {
        }

        $rows = AiUsageEvent::where('agency_id', $agency->id)->get();
        $this->assertCount(1, $rows, 'the failed call is on the usage page, not a silent gap');
        $this->assertEquals(0.0, (float) $rows[0]->cost_zar, 'no cost figure is invented for a call whose billing is unknown');
        $this->assertTrue((bool) $rows[0]->fallback);
    }

    // ── M3. A batch of concurrent calls is held to the cut-out up front ──

    public function test_a_batch_that_could_cross_the_cutout_is_refused_before_any_call(): void
    {
        Mail::fake();
        $agency = $this->agency('Batch Co');
        $this->spend($agency->id, 45.00); // under the R50 cut-out, over the R30 alert
        Http::fake();

        // 3 calls x 10 000 output tokens on the quality model is about R7.4 worst case: 45 + 7.4 > 50.
        $requests = [];
        foreach (['a', 'b', 'c'] as $k) {
            $requests[$k] = $this->batchRequest($agency->id, $k, 10000);
        }

        $out = app(AnthropicGateway::class)->generateMany($requests);

        foreach (['a', 'b', 'c'] as $k) {
            $this->assertInstanceOf(AiPausedException::class, $out[$k]);
        }
        Http::assertNothingSent();
    }

    public function test_a_batch_that_fits_under_the_cutout_goes_ahead_and_releases_its_reservation(): void
    {
        Mail::fake();
        $agency = $this->agency('Roomy Co');
        $this->spend($agency->id, 10.00);
        Http::fake(fn () => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ], 200));

        $requests = ['a' => $this->batchRequest($agency->id, 'a', 4000), 'b' => $this->batchRequest($agency->id, 'b', 4000)];
        $out      = app(AnthropicGateway::class)->generateMany($requests);

        $this->assertSame('ok', $out['a']->outputText);
        $this->assertSame('ok', $out['b']->outputText);
        Http::assertSentCount(2);
        $this->assertNull(Cache::get('ai-spend-guard:reserved:' . $agency->id), 'the reservation is released once the calls are recorded');
    }

    public function test_concurrent_batches_reserve_against_each_other(): void
    {
        $agency = $this->agency('Two Batches Co');
        $this->spend($agency->id, 10.00);

        // Cut-out R50, spent R10: a first batch of R25 fits; a second R25 would reach R60 together - refused.
        $first = $this->guard->reserveBatch($agency->id, 25.0);
        $this->assertNotSame('', $first);

        try {
            $this->guard->reserveBatch($agency->id, 25.0);
            $this->fail('The second batch must be refused while the first is still in flight.');
        } catch (AiPausedException) {
        }

        // Once the first batch is done (its spend is in the ledger) the room is counted from the ledger instead.
        $this->guard->releaseBatch($agency->id, $first);
        $this->assertNotSame('', $this->guard->reserveBatch($agency->id, 25.0));
    }

    public function test_a_single_call_through_generate_many_is_not_held_back_by_the_estimate(): void
    {
        Mail::fake();
        $agency = $this->agency('Single Co');
        $this->spend($agency->id, 49.00);
        Http::fake(fn () => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ], 200));

        $out = app(AnthropicGateway::class)->generateMany(['only' => $this->batchRequest($agency->id, 'only', 10000)]);

        $this->assertSame('ok', $out['only']->outputText, 'a single call behaves exactly as before');
    }

    // ── L3. The spend email goes out after the lock is released, the alert is recorded before it ──

    public function test_the_alert_email_is_sent_after_the_lock_is_released_and_the_alert_is_already_recorded(): void
    {
        $agency = $this->agency('Slow Mail Co');
        $this->spend($agency->id, 35.00);

        $lockFreeWhileMailing = null;
        $recordedWhileMailing = null;
        $delivered = Mockery::mock();
        $delivered->shouldReceive('send')->once();
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($agency, &$lockFreeWhileMailing, &$recordedWhileMailing, $delivered) {
            $probe = Cache::lock('ai-spend-guard:' . $agency->id, 10);
            $lockFreeWhileMailing = $probe->get();
            if ($lockFreeWhileMailing) {
                $probe->release();
            }
            $recordedWhileMailing = \Illuminate\Support\Facades\DB::table('agencies')->where('id', $agency->id)->value('ai_alert_last_sent_at') !== null;

            return $delivered;
        });

        $this->guard->evaluate($agency->id);

        $this->assertTrue($lockFreeWhileMailing, 'the guard lock must not be held while the email is being sent');
        $this->assertTrue($recordedWhileMailing, 'the alert is marked sent before the email, so a second evaluation cannot send it twice');
    }

    public function test_the_cutout_email_also_goes_out_after_the_lock_is_released(): void
    {
        $agency = $this->agency('Slow Cut Co');
        $this->spend($agency->id, 60.00);

        $lockFreeWhileMailing = null;
        $delivered = Mockery::mock();
        $delivered->shouldReceive('send')->once();
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($agency, &$lockFreeWhileMailing, $delivered) {
            $probe = Cache::lock('ai-spend-guard:' . $agency->id, 10);
            $lockFreeWhileMailing = $probe->get();
            if ($lockFreeWhileMailing) {
                $probe->release();
            }

            return $delivered;
        });

        $this->guard->evaluate($agency->id);

        $this->assertTrue($lockFreeWhileMailing);
        $this->assertTrue($this->guard->isPaused($agency->id));
    }

    // ── 3. Paused fallback must not linger or clobber a good narrative ──

    public function test_paused_with_force_refresh_keeps_the_good_cached_narrative_and_writes_no_fallback_row(): void
    {
        Http::fake();
        $agency = $this->pausedAgency();
        $key    = 'narrative:' . $agency->id;
        $good   = $this->cacheRow($key, $agency->id, 'GOOD narrative', 'claude-haiku-4-5');

        $response = app(AnthropicGateway::class)->generate($this->request($key, $agency->id, forceRefresh: true));

        $this->assertTrue($response->fromFallback);
        $this->assertSame(AiPausedException::MESSAGE, $response->errorMessage);
        $row = AINarrativeCache::where('cache_key', $key)->first();
        $this->assertSame($good->id, $row->id);
        $this->assertSame('GOOD narrative', $row->output_text);
        $this->assertStringNotContainsString('(fallback)', (string) $row->model);
        // The zero-cost ledger row is still written.
        $this->assertSame(1, AiUsageEvent::where('agency_id', $agency->id)->where('fallback', true)->count());
        Http::assertNothingSent();
    }

    public function test_paused_with_no_cache_leaves_nothing_behind(): void
    {
        Http::fake();
        $agency = $this->pausedAgency();
        $key    = 'fresh:' . $agency->id;

        app(AnthropicGateway::class)->generate($this->request($key, $agency->id));

        $this->assertSame(0, AINarrativeCache::withTrashed()->where('cache_key', $key)->count());
    }

    public function test_resume_clears_that_buckets_fallback_rows_so_the_next_call_reaches_the_api(): void
    {
        $agency = $this->pausedAgency();
        $other  = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $key    = 'stale:' . $agency->id;

        // A fallback row written by older code (or an API failure) just before/while paused...
        $this->cacheRow($key, $agency->id, 'templated', 'claude-haiku-4-5 (fallback)');
        $otherFallback = $this->cacheRow('stale:other', $other->id, 'templated', 'claude-haiku-4-5 (fallback)');
        $goodSame      = $this->cacheRow('good:' . $agency->id, $agency->id, 'GOOD', 'claude-haiku-4-5');

        $this->guard->resume($agency->id, $this->owner());

        $this->assertNull(AINarrativeCache::where('cache_key', $key)->first(), 'fallback row soft-deleted');
        $this->assertNotNull(AINarrativeCache::withTrashed()->where('cache_key', $key)->first());
        $this->assertNotNull(AINarrativeCache::find($otherFallback->id), 'another agency is untouched');
        $this->assertNotNull(AINarrativeCache::find($goodSame->id), 'a good narrative is untouched');

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'fresh from the API']],
            'usage'   => ['input_tokens' => 5, 'output_tokens' => 5],
        ], 200)]);

        $response = app(AnthropicGateway::class)->generate($this->request($key, $agency->id));

        $this->assertFalse($response->fromFallback);
        $this->assertSame('fresh from the API', $response->outputText);
        Http::assertSentCount(1);
    }

    public function test_resuming_the_system_bucket_clears_only_agency_null_fallback_rows(): void
    {
        $agency = Agency::create(['name' => 'Some Agency', 'slug' => 'some-' . uniqid()]);
        $sys    = $this->cacheRow('sys:fb', null, 'templated', 'claude-haiku-4-5 (fallback)');
        $ag     = $this->cacheRow('ag:fb', $agency->id, 'templated', 'claude-haiku-4-5 (fallback)');

        $this->guard->resume(null, $this->owner());

        $this->assertNull(AINarrativeCache::find($sys->id));
        $this->assertNotNull(AINarrativeCache::find($ag->id));
    }

    // ── 4. A failed alert email must not use up the once-per-window alert ──

    public function test_a_failed_alert_email_does_not_consume_the_alert_and_retries_after_the_backoff(): void
    {
        $agency = $this->agency('Mail Co');
        $this->spend($agency->id, 35.00);

        $delivered = Mockery::mock();
        $delivered->shouldReceive('send')->once();
        $attempt = 0;
        // Attempt 1: SMTP is down. Attempt 2 (after the back-off): healthy. A third call must never happen.
        Mail::shouldReceive('to')->twice()->andReturnUsing(function () use (&$attempt, $delivered) {
            if (++$attempt === 1) {
                throw new \RuntimeException('smtp down');
            }

            return $delivered;
        });

        $this->guard->evaluate($agency->id);

        $this->assertNull(\Illuminate\Support\Facades\DB::table("agencies")->where("id", $agency->id)->value("ai_alert_last_sent_at"), 'a failed send is not "sent"');
        $this->assertSame(0, AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'alert')->count());
        $this->assertFalse($this->guard->isPaused($agency->id));

        // Inside the 15-minute back-off a dead SMTP costs nothing: no second attempt.
        $this->guard->evaluate($agency->id);
        $this->assertSame(1, $attempt);

        // Once the back-off has passed the alert is tried again, and only now marked sent.
        Cache::forget('ai-spend-guard:alert-mail-retry:' . $agency->id);
        $this->guard->evaluate($agency->id);
        $this->assertSame(2, $attempt);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table("agencies")->where("id", $agency->id)->value("ai_alert_last_sent_at"));
        $this->assertSame(1, AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'alert')->count());

        // ...and from then on it is once per window again.
        $this->guard->evaluate($agency->id);
        $this->assertSame(2, $attempt);
    }

    public function test_the_alert_is_marked_sent_only_after_a_successful_send(): void
    {
        Mail::fake();
        $agency = $this->agency('Healthy Co');
        $this->spend($agency->id, 35.00);

        $this->guard->evaluate($agency->id);
        $this->guard->evaluate($agency->id);

        Mail::assertSent(AiSpendAlertMail::class, 1);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table("agencies")->where("id", $agency->id)->value("ai_alert_last_sent_at"));
        $this->assertSame(1, AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'alert')->count());
    }

    public function test_the_cutout_still_pauses_when_mail_is_down(): void
    {
        $agency = $this->agency('Cut Co');
        $this->spend($agency->id, 60.00);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->guard->evaluate($agency->id);

        $this->assertTrue($this->guard->isPaused($agency->id), 'the pause never depends on mail');
        $this->assertSame(1, AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', 'pause')->count());
    }

    // ── 6a. System-bucket cut-out email says what IS affected ──

    public function test_the_system_cutout_email_names_what_is_affected_and_agency_emails_keep_their_sentence(): void
    {
        $system = (new AiSpendAlertMail('cutout', 'Platform (no agency)', 55.0, 50.0, 24, [], 'https://x.test/u', isSystem: true))->render();
        $this->assertStringContainsString('Article Library', $system);
        $this->assertStringNotContainsString('Other agencies are not affected', $system);

        $agency = (new AiSpendAlertMail('cutout', 'Busy Co', 55.0, 50.0, 24, [], 'https://x.test/u'))->render();
        $this->assertStringContainsString('Other agencies are not affected', $agency);
        $this->assertStringNotContainsString('Article Library', $agency);
    }

    // ── 6b. Mobile voice: paused is a 422, same shape as a transcription failure ──

    public function test_mobile_voice_returns_422_with_the_error_shape_when_ai_is_paused(): void
    {
        $stt = Mockery::mock(SpeechToTextService::class);
        $stt->shouldReceive('transcribe')->andReturn(['text' => 'book a viewing tomorrow', 'language' => 'en', 'duration_seconds' => 2.0, 'elapsed_ms' => 10]);
        $intent = Mockery::mock(IntentExtractionService::class);
        $intent->shouldReceive('extract')->andThrow(new AiPausedException(1));
        $this->app->instance(SpeechToTextService::class, $stt);
        $this->app->instance(IntentExtractionService::class, $intent);

        $user = new class extends User {
            public function hasPermission(string $key): bool
            {
                return true;
            }
        };

        $request = Request::create('/api/v1/mobile/ellie/voice', 'POST', [], [], [
            'audio' => UploadedFile::fake()->create('v.m4a', 10, 'audio/mp4'),
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(MobileEllieVoiceController::class)->process($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['error' => AiPausedException::MESSAGE], $response->getData(true));
    }

    // ── helpers ──

    private function batchRequest(int $agencyId, string $key, int $maxTokens): NarrativeRequest
    {
        return new NarrativeRequest(
            narrativeType: 'batch', cacheKey: 'batch:' . $key . ':' . uniqid(), modelAlias: 'quality', systemPrompt: 's', userPrompt: 'u',
            maxTokens: $maxTokens, agencyId: $agencyId, forceRefresh: true,
        );
    }

    private function request(string $key, int $agencyId, bool $forceRefresh = false): NarrativeRequest
    {
        return new NarrativeRequest(
            narrativeType: 'test', cacheKey: $key, modelAlias: 'fast', systemPrompt: 's', userPrompt: 'u',
            agencyId: $agencyId, fallbackData: ['text' => 'templated'], forceRefresh: $forceRefresh,
        );
    }

    private function cacheRow(string $key, ?int $agencyId, string $text, string $model): AINarrativeCache
    {
        return AINarrativeCache::create([
            'agency_id' => $agencyId, 'narrative_type' => 'test', 'cache_key' => $key,
            'input_hash' => $this->request($key, $agencyId ?? 0)->inputHash(), 'prompt_version' => 'v1', 'model' => $model,
            'input_tokens' => 0, 'output_tokens' => 0, 'cost_zar' => 0, 'output_text' => $text,
            'generated_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    private function pausedAgency(): Agency
    {
        Mail::fake();
        $agency = $this->agency('Paused Co');
        $this->spend($agency->id, 50.00);
        $this->guard->evaluate($agency->id);
        $this->assertTrue($this->guard->isPaused($agency->id));

        return $agency;
    }

    private function spend(?int $agencyId, float $zar): void
    {
        AiUsageEvent::create([
            'agency_id' => $agencyId, 'source' => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model' => 'claude-haiku-4-5', 'cost_zar' => $zar, 'occurred_at' => now()->subMinute(),
        ]);
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);
    }
}
