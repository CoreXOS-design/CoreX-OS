<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AiTemplateGeneration;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 Part B audit item 9 — three options is the reliable outcome. A design that breaks a
 * repairable rule is repaired (not dropped); one that breaks a safety rule is still rejected, with
 * the exact rule logged and stored on the generation so a short result can be diagnosed.
 *
 * The "recorded" AI answer is tests/Fixtures/AiTemplates/response-with-repairable-faults.json.
 */
final class AiTemplateRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── The recorded response, end to end ────────────────────────────────

    public function test_a_recorded_answer_with_repairable_faults_still_yields_three_options_via_one_targeted_retry(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $recorded = $this->recorded();
        $fake = $this->fakeAi([$recorded, [$this->validOption('Fresh Option')]]);
        $this->actingAs($user);

        $gen = app(AiTemplateBuilderService::class)->generate($user, 'Clean modern ad for a family home', 'facebook', 'House');

        // Two repaired options from call 1 (the bad-colour one rejected), the third from the targeted retry.
        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(3, $gen->options_json);
        $this->assertSame(['Hero Pill', 'Split Dark', 'Fresh Option'], array_column($gen->options_json, 'name'));
        $this->assertSame(2, $fake->calls());

        // The retry asked for ONE option and was told exactly which rule failed.
        $retry = $fake->requestBody(1)['messages'][0]['content'];
        $this->assertStringContainsString('Design 1 different', $retry);
        $this->assertStringContainsString('Option 3:', $retry);
        $this->assertStringContainsString('not a valid colour', $retry);

        // Every verdict is stored on the row: which option, which rule.
        $log = $gen->validation_log_json;
        $this->assertSame(['repaired', 'repaired', 'rejected', 'accepted'], array_column($log, 'outcome'));
        $this->assertSame([1, 1, 1, 2], array_column($log, 'attempt'));
        $rejected = $log[2];
        $this->assertSame(1, $rejected['attempt']);
        $this->assertSame(3, $rejected['option']);
        $this->assertStringContainsString('"color" is not a valid colour', implode(' | ', $rejected['errors']));

        // The repairs are named, so a human can see what changed.
        $hero = implode(' | ', $log[0]['repairs']);
        $this->assertStringContainsString('outside the 1200×628 canvas', $hero);
        $this->assertStringContainsString('fontSize 14 was raised to the minimum of 16', $hero);
        $this->assertStringContainsString('colour "white" was written as #ffffff', $hero);
        $this->assertStringContainsString('"fontFamily" had a value that is not allowed', $hero);
        $split = implode(' | ', $log[1]['repairs']);
        foreach (['made opaque', 'text colour #1f2937', 'text shadow', 'shortened', 'no text was left out', 'does not exist', 'watermark was too solid'] as $needle) {
            $this->assertStringContainsString($needle, $split);
        }

        // And the stored layouts really are inside the rules.
        $layout = $gen->options_json[0]['layout'];
        foreach ($layout['elements'] as $el) {
            $this->assertGreaterThanOrEqual(0, $el['x']);
            $this->assertLessThanOrEqual(1200, $el['x'] + $el['w']);
            $this->assertLessThanOrEqual(628, $el['y'] + $el['h']);
            if (! in_array($el['field'], ['image_1', 'gradient'], true)) {
                $this->assertGreaterThanOrEqual(16, $el['fontSize']);
            }
        }
        $this->assertSame('#ffffff', collect($layout['elements'])->firstWhere('field', 'title')['color']);
        $this->assertSame('700', collect($layout['elements'])->firstWhere('field', 'title')['fontWeight']);
        $this->assertSame('Figtree', collect($layout['elements'])->firstWhere('field', 'title')['fontFamily']);

        $splitLayout = $gen->options_json[1]['layout'];
        $this->assertSame('#0b1220', $splitLayout['canvasBg']);
        $this->assertSame('#ffffff', collect($splitLayout['elements'])->firstWhere('field', 'title')['color']);
        $this->assertTrue(collect($splitLayout['elements'])->firstWhere('field', 'suburb')['shadowOn']);
        $this->assertSame(80, mb_strlen(collect($splitLayout['elements'])->firstWhere('field', 'custom_text')['text']));
        $this->assertNull(collect($splitLayout['elements'])->firstWhere('field', 'badge'));
        $this->assertNull(collect($splitLayout['elements'])->firstWhere('field', 'rocket'));
        $this->assertLessThanOrEqual(0.15, collect($splitLayout['elements'])->firstWhere('field', 'watermark')['opacity']);
    }

    public function test_a_safety_rule_break_that_keeps_coming_back_is_dropped_with_the_reason_logged_and_stored(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $recorded = $this->recorded();
        // The retry repeats the colour injection (option "Broken Colour" again).
        $this->fakeAi([$recorded, [$recorded[2]]]);
        $this->actingAs($user);
        Log::spy();

        $gen = app(AiTemplateBuilderService::class)->generate($user, 'Clean modern ad', 'facebook', null);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(2, $gen->options_json, 'Two good options are still shown; the unsafe one never is.');
        $this->assertStringNotContainsString('script', json_encode($gen->options_json));

        $rejections = array_values(array_filter($gen->validation_log_json, fn ($e) => $e['outcome'] === 'rejected'));
        $this->assertCount(2, $rejections);
        $this->assertSame([1, 2], array_column($rejections, 'attempt'));
        foreach ($rejections as $r) {
            $this->assertStringContainsString('is not a valid colour', implode(' ', $r['errors']));
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, 'rejected by the validator')
                && ($context['generation'] ?? null) === $gen->id
                && ($context['option'] ?? null) === 3
                && str_contains(implode(' ', $context['errors'] ?? []), 'is not a valid colour')
        )->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => str_contains((string) $message, 'finished short of 3 options')
        )->once();
    }

    public function test_the_prompt_states_the_validators_own_limits(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);

        app(AiTemplateBuilderService::class)->generate($user, 'Anything', 'facebook', null);

        $body = json_encode($fake->requestBody(0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([
            'fontSize is at least ' . AdLayoutValidator::MIN_FONT_SIZE,
            'reach ' . AdLayoutValidator::MIN_CONTRAST . ':1',
            'between ' . AdLayoutValidator::MIN_ELEMENTS . ' and ' . AdLayoutValidator::MAX_ELEMENTS . ' elements',
            'at most ' . AdLayoutValidator::MAX_TEXT_LEN . ' characters',
            'x + w <= 1200, y + h <= 628',
            'opacity at most ' . AdLayoutValidator::WATERMARK_MAX_OPACITY,
            'Figtree / Inter / Poppins',
            'rotation -180 to 180',
        ] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }
    }

    // ── Each repair on its own (and what must NOT be repaired) ───────────

    /** @return array<string, array{0: callable, 1: string}> */
    public static function repairable(): array
    {
        $on = fn (string $field, string $k, mixed $v) => function (array $o) use ($field, $k, $v) {
            foreach ($o['elements'] as &$e) {
                if ($e['field'] === $field) {
                    $e[$k] = $v;
                }
            }

            return $o;
        };

        return [
            'just outside the canvas'  => [$on('price', 'x', 1150), 'moved back inside'],
            'far outside the canvas'   => [$on('price', 'y', 5000), 'moved back inside'],
            'wider than the canvas'    => [$on('price', 'w', 4000), 'moved back inside'],
            'negative position'        => [$on('price', 'y', -40), 'moved back inside'],
            'font below the minimum'   => [$on('price', 'fontSize', 9), 'raised to the minimum'],
            'opacity out of range'     => [$on('price', 'elOpacity', 7), 'clamped'],
            'colour name'              => [$on('price', 'color', 'White'), 'written as #ffffff'],
            'translucent hex'          => [$on('price', 'bgColor', '#000000cc'), 'written as rgba(0,0,0,0.8)'],
            'numeric weight'           => [$on('price', 'fontWeight', 700), null],
            'wrong case enum'          => [$on('price', 'textAlign', 'Center'), 'corrected to center'],
            'unknown font'             => [$on('price', 'fontFamily', 'Comic Sans'), 'default was used'],
            'numbers as strings'       => [$on('price', 'w', '500'), null],
            'faint text on a block'    => [$on('price', 'color', '#0d1424'), 'changed to #ffffff'],
        ];
    }

    #[DataProvider('repairable')]
    public function test_repairable_faults_are_fixed_not_dropped(callable $mutate, ?string $expectedRepair): void
    {
        $v = new AdLayoutValidator();
        $option = $mutate($this->validOption('R'));

        // Without repair these are rejected or (for harmless spellings) left to the strict path …
        $r = $v->validateAndRepair($option, 'facebook');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        if ($expectedRepair !== null) {
            $this->assertStringContainsString($expectedRepair, implode(' | ', $r['repairs']));
        }
        foreach ($r['layout']['elements'] as $el) {
            $this->assertGreaterThanOrEqual(0, $el['x']);
            $this->assertLessThanOrEqual(1200, $el['x'] + $el['w']);
            $this->assertLessThanOrEqual(628, $el['y'] + $el['h']);
        }
    }

    public function test_plain_validate_is_unchanged_and_still_strict(): void
    {
        $v = new AdLayoutValidator();
        $o = $this->validOption('S');
        $o['elements'][3]['fontSize'] = 9;
        $this->assertFalse($v->validate($o, 'facebook')['ok']);

        $o = $this->validOption('S');
        $o['elements'][3]['x'] = 1150;
        $this->assertFalse($v->validate($o, 'facebook')['ok']);
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function neverRepaired(): array
    {
        $on = fn (string $field, string $k, mixed $v) => function (array $o) use ($field, $k, $v) {
            foreach ($o['elements'] as &$e) {
                if ($e['field'] === $field) {
                    $e[$k] = $v;
                }
            }

            return $o;
        };

        return [
            'css injection colour'  => [$on('price', 'color', 'red;"><script>alert(1)</script>'), 'not a valid colour'],
            'url colour'            => [$on('price', 'color', 'url(http://evil.test/x.png)'), 'not a valid colour'],
            'hsl colour'            => [$on('price', 'bgColor', 'hsl(10,10%,10%)'), 'not a valid colour'],
            'named colour with junk' => [$on('price', 'color', 'white; background:url(x)'), 'not a valid colour'],
            'no property photo'     => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = array_values(array_filter($o['elements'], fn ($e) => $e['field'] !== 'image_1'))), 'at least one property photo'],
            'too few elements'      => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = array_slice($o['elements'], 0, 2)), 'at least'],
            'elements not a list'   => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = 'lots'), '"elements" must be a list'],
            'contrast neither white nor black can meet' => [fn ($o) => tap($o, function (&$o) {
                foreach ($o['elements'] as &$e) {
                    if ($e['field'] === 'gradient') {
                        $e = ['field' => 'color_block', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'bg' => 'rgba(128,128,128,0.6)', 'opacity' => 1];
                    }
                }
            }), 'contrast'],
        ];
    }

    #[DataProvider('neverRepaired')]
    public function test_safety_rules_are_never_repaired_away(callable $mutate, string $expectedError): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($mutate($this->validOption('N')), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertNull($r['layout']);
        $this->assertStringContainsString($expectedError, implode(' | ', $r['errors']));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function recorded(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/AiTemplates/response-with-repairable-faults.json')), true)['options'];
    }

    /** @return array<string, mixed> */
    private function validOption(string $name): array
    {
        return [
            'name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
                ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
                ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 1100, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
                ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
                ['field' => 'agent_name', 'x' => 760, 'y' => 440, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ],
        ];
    }

    /**
     * Fake Anthropic: each call returns the next batch of options.
     *
     * @param array<int, array<int, array<string, mixed>>> $batches
     */
    private function fakeAi(array $batches): object
    {
        $i = 0;
        Http::fake(function ($request) use (&$i, $batches) {
            $options = $batches[min($i, count($batches) - 1)];
            $i++;

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['options' => $options])]],
                'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
            ], 200);
        });

        return new class {
            public function calls(): int
            {
                return count(Http::recorded());
            }

            public function requestBody(int $n): array
            {
                return json_decode(Http::recorded()[$n][0]->body(), true);
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
