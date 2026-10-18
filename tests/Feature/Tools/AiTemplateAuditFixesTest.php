<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Exceptions\AI\AiTemplateLimitReachedException;
use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\AiTemplateGeneration;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 Part B audit findings (2026-10-17): mislabelled reference image, the non-atomic monthly
 * allowance, contrast that ignored transparency, the throttle's "Too Many Attempts." message.
 */
final class AiTemplateAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Cache::flush();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── 3. Reference image: what the bytes are, not what the file is called ──

    public function test_a_jpeg_named_png_is_stored_and_sent_as_a_jpeg(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi();
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('photo.png', $this->imageBytes('jpeg', 400, 300));
        $gen  = app(AiTemplateBuilderService::class)->generate($user, 'Like this', 'facebook', null, $file);

        $this->assertStringEndsWith('.jpg', $gen->reference_image_path);
        $this->assertSame('image/jpeg', $fake->firstBody()['messages'][0]['content'][0]['source']['media_type']);
    }

    public function test_a_png_named_jpg_is_stored_and_sent_as_a_png(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi();
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('photo.jpg', $this->imageBytes('png', 400, 300));
        $gen  = app(AiTemplateBuilderService::class)->generate($user, 'Like this', 'facebook', null, $file);

        $this->assertStringEndsWith('.png', $gen->reference_image_path);
        $this->assertSame('image/png', $fake->firstBody()['messages'][0]['content'][0]['source']['media_type']);
    }

    public function test_a_reference_already_stored_with_the_wrong_extension_is_still_sent_correctly(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        Storage::disk('local')->put('ai-template-refs/' . $agency . '/old.png', $this->imageBytes('jpeg', 100, 100));
        $gen = new AiTemplateGeneration();
        $gen->reference_image_path = 'ai-template-refs/' . $agency . '/old.png';

        $m = new \ReflectionMethod(AiTemplateBuilderService::class, 'loadReference');
        $block = $m->invoke(app(AiTemplateBuilderService::class), $gen);

        $this->assertSame('image/jpeg', $block['media_type']);
    }

    public function test_an_image_over_the_ai_services_size_limit_is_refused_in_plain_words_before_anything_is_spent(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        Http::fake();
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('wide.png', $this->imageBytes('png', 8100, 10));
        try {
            app(AiTemplateBuilderService::class)->generate($user, 'Too wide', 'facebook', null, $file);
            $this->fail('Expected a refusal.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('too large in pixels', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_large_but_acceptable_image_is_scaled_down_before_it_is_sent(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi();
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('big.png', $this->imageBytes('png', 3000, 200));
        $gen  = app(AiTemplateBuilderService::class)->generate($user, 'Big picture', 'facebook', null, $file);

        $sent = $fake->firstBody()['messages'][0]['content'][0]['source'];
        $this->assertSame('image/png', $sent['media_type']);
        $size = getimagesizefromstring(base64_decode($sent['data']));
        $this->assertSame(AiTemplateBuilderService::REFERENCE_SEND_MAX_PX, $size[0]);
        $this->assertLessThan(200, $size[1]);
        $this->assertStringEndsWith('.png', $gen->reference_image_path);
    }

    // ── 4. Allowance check + reservation are atomic per agency ───────────

    public function test_generating_while_another_request_holds_the_agency_lock_waits_then_refuses_cleanly(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        Http::fake();
        $this->actingAs($user);

        $other = Cache::lock('ai-template-allowance:' . $agency, 30);
        $this->assertTrue($other->get());

        try {
            app(AiTemplateBuilderService::class)->generate($user, 'Race', 'facebook', null);
            $this->fail('Expected the busy refusal.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('try again', $e->getMessage());
        } finally {
            $other->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::withoutGlobalScopes()->count());
    }

    public function test_with_one_left_the_first_request_takes_it_and_the_second_is_refused_and_the_lock_is_always_released(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        for ($i = 0; $i < 19; $i++) {
            DB::table('ai_template_generations')->insert([
                'agency_id' => $agency, 'user_id' => $user->id, 'idea' => 's', 'ad_size' => 'facebook',
                'status' => 'success', 'cost_zar' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $fake = $this->fakeAi();
        $this->actingAs($user);
        $svc = app(AiTemplateBuilderService::class);
        $this->assertSame(1, $svc->usage($agency)['remaining']);

        // Reserve the last slot the way a request does (pending row, lock released), then a second request arrives
        // before the first has finished: it must see the slot as taken.
        $reserve = new \ReflectionMethod(AiTemplateBuilderService::class, 'reserveAllowance');
        $first = $reserve->invoke($svc, $agency, fn () => AiTemplateGeneration::create([
            'agency_id' => $agency, 'user_id' => $user->id, 'idea' => 'one', 'ad_size' => 'facebook', 'status' => AiTemplateGeneration::STATUS_PENDING,
        ]));
        $this->assertSame(AiTemplateGeneration::STATUS_PENDING, $first->status);

        try {
            $svc->generate($user, 'two', 'facebook', null);
            $this->fail('Expected the limit to refuse the second request.');
        } catch (AiTemplateLimitReachedException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $fake->calls());
        $lock = Cache::lock('ai-template-allowance:' . $agency, 5);
        $this->assertTrue($lock->get(), 'The allowance lock must be released after a refusal.');
        $lock->release();
    }

    // ── 5. Contrast: transparency is not ignored ─────────────────────────

    public function test_white_text_on_a_nearly_clear_block_is_rejected_but_on_a_dense_one_accepted(): void
    {
        $v = new AdLayoutValidator();

        $thin = $this->blockOption('rgba(0,0,0,0.1)');
        $r = $v->validate($thin, 'facebook');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('see-through, over white', implode(' | ', $r['errors']));

        $this->assertTrue($v->validate($this->blockOption('rgba(0,0,0,0.75)'), 'facebook')['ok']);
        $this->assertTrue($v->validate($this->blockOption('#0b1220'), 'facebook')['ok']);
    }

    public function test_a_translucent_pill_is_judged_by_its_real_strength(): void
    {
        $v = new AdLayoutValidator();
        $weak = $this->validOption('Pill');
        $weak['elements'] = array_values(array_filter($weak['elements'], fn ($e) => $e['field'] !== 'gradient'));
        foreach ($weak['elements'] as &$e) {
            if (in_array($e['field'], ['price', 'title', 'agent_name'], true)) {
                $e['bgColor'] = 'rgba(0,0,0,0.1)';
                $e['bgOpacity'] = 0.8;
            }
        }
        unset($e);
        $this->assertFalse($v->validate($weak, 'facebook')['ok']);

        foreach ($weak['elements'] as &$e) {
            if (isset($e['bgColor'])) {
                $e['bgColor'] = '#0b1220';
            }
        }
        unset($e);
        $this->assertTrue($v->validate($weak, 'facebook')['ok']);
    }

    public function test_translucent_gradient_stops_are_tested_over_white_and_black(): void
    {
        $v = new AdLayoutValidator();

        $weak = $this->validOption('Grad');
        foreach ($weak['elements'] as &$e) {
            if ($e['field'] === 'gradient') {
                $e['gradFrom'] = 'rgba(255,255,255,0.9)';
                $e['gradTo']   = 'rgba(255,255,255,0.9)';
            }
        }
        unset($e);
        $r = $v->validate($weak, 'facebook');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('see-through', implode(' | ', $r['errors']));

        $good = $this->validOption('Grad');
        foreach ($good['elements'] as &$e) {
            if ($e['field'] === 'gradient') {
                $e['gradFrom'] = 'rgba(0,0,0,0.85)';
                $e['gradTo']   = 'rgba(0,0,0,0.85)';
            }
        }
        unset($e);
        $this->assertTrue($v->validate($good, 'facebook')['ok']);
    }

    // ── 6. The throttle's 429 gets the friendly message ──────────────────

    public function test_the_panel_swaps_the_throttles_too_many_attempts_for_friendly_words_but_keeps_the_monthly_limit_message(): void
    {
        // The panel's script lives in the pop-up partial since AT-456 §B.10.
        $blade = (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-template-modal.blade.php'));
        $this->assertStringContainsString("e.status === 429 && !(data && data.usage)", $blade);
        $this->assertStringContainsString('You are going a little fast', $blade);

        // The monthly-limit 429 carries `usage` (that is what the panel keys on to keep its own message).
        [$agency, $user] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agency)->update(['ai_template_monthly_limit' => 0]);
        Http::fake();
        $this->actingAs($user);
        $request = Request::create('/api/v1/ad-manager/templates/ai/generate', 'POST', ['idea' => 'x', 'ad_size' => 'facebook']);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $res = app(AdTemplateManagerController::class)->generateWithAi($request, app(AiTemplateBuilderService::class));

        $this->assertSame(429, $res->status());
        $this->assertArrayHasKey('usage', $res->getData(true));
    }

    // ── D-M2. Paid failed attempts are limited per day (platform setting, default 5) ──

    public function test_five_paid_failures_today_refuse_the_sixth_attempt_before_any_ai_call(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->generationRows($agency, $user, 'failed', 3.5, 5);
        $fake = $this->fakeAi();
        $this->actingAs($user);

        try {
            app(AiTemplateBuilderService::class)->generate($user, 'again', 'facebook', null);
            $this->fail('The sixth attempt of the day must be refused.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('Too many attempts that did not produce a usable design today', $e->getMessage());
        }

        $this->assertSame(0, $fake->calls());
        $this->assertSame(0, AiTemplateGeneration::where('status', 'pending')->count(), 'no row was reserved');
        $lock = Cache::lock('ai-template-allowance:' . $agency, 5);
        $this->assertTrue($lock->get(), 'the allowance lock is released after the refusal');
        $lock->release();
    }

    public function test_four_paid_failures_still_allow_an_attempt_and_failures_do_not_use_the_allowance(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->generationRows($agency, $user, 'failed', 3.5, 4);
        $this->fakeAi();
        $this->actingAs($user);
        $svc = app(AiTemplateBuilderService::class);

        $this->assertSame(0, $svc->usage($agency)['used'], 'failed attempts still do not use the monthly allowance');
        $gen = $svc->generate($user, 'fine', 'facebook', null);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
    }

    public function test_successes_and_free_failures_do_not_count_toward_the_daily_limit(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->generationRows($agency, $user, 'success', 3.5, 4);
        $this->generationRows($agency, $user, 'failed', 0, 4);   // failed before any paid call
        $this->generationRows($agency, $user, 'failed', 3.5, 1);
        $this->fakeAi();
        $this->actingAs($user);

        $this->assertSame(1, app(AiTemplateBuilderService::class)->paidFailuresToday($agency));
        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, app(AiTemplateBuilderService::class)->generate($user, 'fine', 'facebook', null)->status);
    }

    public function test_the_daily_count_starts_afresh_the_next_day_and_is_per_agency(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        [$other] = $this->agencyWithUser();
        $this->generationRows($other, $user, 'failed', 3.5, 5); // another agency's failures never count here
        $this->generationRows($agency, $user, 'failed', 3.5, 5);
        $svc = app(AiTemplateBuilderService::class);
        $this->assertSame(5, $svc->paidFailuresToday($agency));

        \Carbon\Carbon::setTestNow(now()->addDay()->startOfDay()->addMinutes(5));
        try {
            $this->assertSame(0, $svc->paidFailuresToday($agency), 'a new day in the agency timezone resets the count');
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_the_limit_is_a_platform_setting(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        \App\Models\DevSetting::set(\App\Services\AI\AiSpendGuard::KEY_MAX_FAILED_PER_DAY, '2');
        $this->generationRows($agency, $user, 'failed', 3.5, 2);
        $this->fakeAi();
        $this->actingAs($user);

        $this->assertSame(2, app(\App\Services\AI\AiSpendGuard::class)->settings()['max_failed_per_day']);
        $this->expectException(AiTemplateGenerationFailedException::class);
        app(AiTemplateBuilderService::class)->generate($user, 'again', 'facebook', null);
    }

    private function generationRows(int $agency, User $user, string $status, float $cost, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('ai_template_generations')->insert([
                'agency_id' => $agency, 'user_id' => $user->id, 'idea' => 's', 'ad_size' => 'facebook',
                'status' => $status, 'cost_zar' => $cost, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** A real, in-memory image of the given type. */
    private function imageBytes(string $type, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im);

        return (string) ob_get_clean();
    }

    /** Text sitting on a colour block (instead of the usual gradient scrim). */
    private function blockOption(string $blockColour): array
    {
        $o = $this->validOption('Block');
        foreach ($o['elements'] as &$e) {
            if ($e['field'] === 'gradient') {
                $e = ['field' => 'color_block', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'bg' => $blockColour, 'opacity' => 1];
            }
        }
        unset($e);

        return $o;
    }

    /** @return array<string, mixed> */
    private function validOption(string $name): array
    {
        return [
            'name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
                ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
                ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 700, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
                ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
                ['field' => 'agent_name', 'x' => 760, 'y' => 540, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ],
        ];
    }

    private function fakeAi(): object
    {
        $options = [$this->validOption('A'), $this->validOption('B'), $this->validOption('C')];
        Http::fake(fn () => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['options' => $options])]],
            'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
        ], 200));

        return new class {
            public function calls(): int
            {
                return count(Http::recorded());
            }

            public function firstBody(): array
            {
                return json_decode(Http::recorded()[0][0]->body(), true);
            }
        };
    }

    /** @return array{0:int, 1:User} */
    private function agencyWithUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId(['agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return [$agencyId, User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent'])];
    }
}
