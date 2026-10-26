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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AI designs never show words on top of each other (Johan 2026-10-20). A live AI template's Vacant Land version put the erf
 * size and the price per m² in the same spot under the price, so neither could be read. The validator now refuses any two
 * words-or-picture elements whose boxes share more than AdLayoutValidator::OVERLAP_TOLERANCE pixels both ways; the AI's retry
 * is told which two; the prompt asks the AI to check for it before answering.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.11.
 */
final class AiTemplateOverlapGuardTest extends TestCase
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

    // ── The validator ────────────────────────────────────────────────────

    public function test_the_live_vacant_land_clash_is_refused_naming_both_facts(): void
    {
        // The two elements exactly as the live template's Vacant Land version had them (same x and y, under the price).
        $option = $this->option([
            ['field' => 'erf_size', 'x' => 730, 'y' => 354, 'w' => 260, 'h' => 40, 'zIndex' => 10, 'fontSize' => 17, 'color' => '#ffffff'],
            ['field' => 'price_per_m2', 'x' => 730, 'y' => 354, 'w' => 420, 'h' => 40, 'zIndex' => 10, 'fontSize' => 17, 'color' => '#ffffff'],
        ]);

        foreach ([(new AdLayoutValidator())->validate($option, 'facebook'), (new AdLayoutValidator())->validateAndRepair($option, 'facebook')] as $r) {
            $this->assertFalse($r['ok'], 'a clash is never shown, repaired or not');
            $this->assertNull($r['layout']);
            $errors = implode(' | ', $r['errors']);
            $this->assertStringContainsString('(erf_size at x 730, y 354) and Element', $errors);
            $this->assertStringContainsString('(price_per_m2 at x 730, y 354) sit on top of each other (they share 260 x 40 pixels)', $errors);
        }
    }

    public function test_the_same_two_facts_side_by_side_or_one_under_the_other_pass(): void
    {
        $v = new AdLayoutValidator();
        $side = $this->option([
            ['field' => 'erf_size', 'x' => 730, 'y' => 354, 'w' => 200, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
            ['field' => 'price_per_m2', 'x' => 940, 'y' => 354, 'w' => 220, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
        ]);
        $under = $this->option([
            ['field' => 'erf_size', 'x' => 730, 'y' => 354, 'w' => 420, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
            ['field' => 'price_per_m2', 'x' => 730, 'y' => 398, 'w' => 420, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
        ]);

        $this->assertTrue($v->validate($side, 'facebook')['ok'], implode(' | ', $v->validate($side, 'facebook')['errors']));
        $this->assertTrue($v->validate($under, 'facebook')['ok'], implode(' | ', $v->validate($under, 'facebook')['errors']));
    }

    public function test_a_shared_edge_up_to_the_tolerance_passes_and_one_pixel_more_is_refused(): void
    {
        $v   = new AdLayoutValidator();
        $tol = AdLayoutValidator::OVERLAP_TOLERANCE;
        $at  = fn (int $shared) => $this->option([
            ['field' => 'agency_name', 'x' => 760, 'y' => 380, 'w' => 400, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'],
            ['field' => 'agent_phone', 'x' => 760, 'y' => 410 - $shared, 'w' => 260, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'],
        ]);

        $this->assertTrue($v->validate($at($tol), 'facebook')['ok'], 'stacked lines that touch by a hair are normal');
        $this->assertFalse($v->validate($at($tol + 1), 'facebook')['ok']);
    }

    public function test_words_on_photos_backgrounds_dividers_and_the_watermark_are_not_a_clash(): void
    {
        $option = $this->option([
            ['field' => 'line', 'x' => 700, 'y' => 360, 'w' => 460, 'h' => 4, 'color' => '#ffffff', 'borderWidth' => 2],
            ['field' => 'shape', 'x' => 700, 'y' => 340, 'w' => 200, 'h' => 60, 'bg' => '#0b2a4a', 'shapeType' => 'rounded', 'zIndex' => 2],
            ['field' => 'watermark', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'opacity' => 0.08, 'fontSize' => 200, 'color' => '#ffffff'],
            ['field' => 'erf_size', 'x' => 730, 'y' => 354, 'w' => 420, 'h' => 40, 'zIndex' => 10, 'fontSize' => 17, 'color' => '#ffffff'],
        ]);

        // The base option already puts the title, price and agent over the photo and the colour block.
        $r = (new AdLayoutValidator())->validate($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }

    public function test_a_picture_over_words_counts_as_a_clash(): void
    {
        $v = new AdLayoutValidator();
        $avatar = $this->option([
            ['field' => 'agent_avatar', 'x' => 1000, 'y' => 440, 'w' => 100, 'h' => 100, 'shapeType' => 'circle'],
        ]);

        $errors = implode(' | ', $v->validate($avatar, 'facebook')['errors']);
        $this->assertStringContainsString('(agent_name at x 720, y 470) and Element', $errors, 'the agent photo over the agent name is a clash');
        $this->assertStringContainsString('(agent_avatar at x 1000, y 440) sit on top of each other', $errors);
    }

    public function test_one_messy_design_reports_at_most_three_overlaps_and_a_summary_so_it_cannot_crowd_out_the_others(): void
    {
        // One wide text box across five stacked lines: five clashes.
        $extra = [['field' => 'custom_text', 'text' => 'Wide words', 'x' => 700, 'y' => 380, 'w' => 480, 'h' => 200, 'fontSize' => 18, 'color' => '#ffffff']];
        foreach (['suburb', 'property_type', 'reference', 'address', 'website'] as $i => $field) {
            $extra[] = ['field' => $field, 'x' => 720, 'y' => 384 + 38 * $i, 'w' => 420, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'];
        }
        $r = (new AdLayoutValidator())->validate($this->option($extra), 'facebook');

        $overlaps = array_values(array_filter($r['errors'], fn ($e) => str_contains($e, 'on top of each other')));
        $this->assertCount(AdLayoutValidator::MAX_OVERLAP_ERRORS + 1, $overlaps);
        $this->assertMatchesRegularExpression('/^And \d+ more pairs of elements sit on top of each other in this design/', end($overlaps));
    }

    public function test_two_elements_of_the_same_type_are_told_apart_by_their_position(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'custom_text', 'text' => 'Sea views', 'x' => 720, 'y' => 380, 'w' => 200, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'],
            ['field' => 'custom_text', 'text' => 'Walk to beach', 'x' => 940, 'y' => 380, 'w' => 200, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'],
            ['field' => 'custom_text', 'text' => 'Pet friendly', 'x' => 940, 'y' => 390, 'w' => 200, 'h' => 30, 'fontSize' => 16, 'color' => '#ffffff'],
        ]), 'facebook');

        $this->assertStringContainsString('(custom_text at x 940, y 380) and Element 8 (custom_text at x 940, y 390)', implode(' | ', $r['errors']));
        $this->assertStringNotContainsString('x 720, y 380', implode(' | ', $r['errors']), 'the first line clashes with nothing');
    }

    public function test_the_prompt_asks_the_ai_to_check_for_overlaps_before_answering_and_names_no_unticked_extra(): void
    {
        $v      = new AdLayoutValidator();
        $limits = $v->promptLimits(1200, 628);
        $this->assertStringContainsString('1c. No overlaps', $limits);
        $this->assertStringContainsString('may touch by at most ' . AdLayoutValidator::OVERLAP_TOLERANCE . 'px', $limits);
        $this->assertStringContainsString('SIDE BY SIDE', $limits);
        $this->assertStringContainsString('ONE UNDER THE OTHER', $limits);
        $this->assertStringContainsString('Before you answer, compare every such pair', $limits);
        $this->assertStringContainsString('ribbon', $limits);

        // Decision 20: an unticked extra is not mentioned at all.
        $without = $v->promptLimits(1200, 628, ['ribbon', 'collage']);
        $rule    = substr($without, strpos($without, '1c. No overlaps'), 400);
        $this->assertStringNotContainsString('ribbon', $rule);
        $this->assertStringNotContainsString('collage', $rule);

        // A company ad's rule names its own picture, not the listing photos.
        $company = $v->promptLimits(1080, 1080, [], AdLayoutValidator::MODE_COMPANY);
        $rule    = substr($company, strpos($company, '1c. No overlaps'), 400);
        $this->assertStringContainsString('custom_image', $rule);
        $this->assertStringNotContainsString('image_1', $rule);
    }

    // ── End to end: the user never sees a clash ──────────────────────────

    public function test_a_design_with_a_clash_is_retried_with_both_elements_named_and_the_user_gets_three_clean_options(): void
    {
        [, $user] = $this->agencyWithUser();
        $clash = $this->option([
            ['field' => 'erf_size', 'x' => 730, 'y' => 354, 'w' => 260, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
            ['field' => 'price_per_m2', 'x' => 730, 'y' => 354, 'w' => 420, 'h' => 40, 'fontSize' => 17, 'color' => '#ffffff'],
        ], 'Clash');
        $fake = $this->fakeAi([[$this->option([], 'One'), $clash, $this->option([], 'Two')], [$this->option([], 'Three')]]);
        $this->actingAs($user);

        $gen = app(AiTemplateBuilderService::class)->generate($user, 'Bold ad for a plot', 'facebook', null);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(['One', 'Two', 'Three'], array_column($gen->options_json, 'name'));
        $this->assertSame(2, $fake->calls());
        $retry = json_encode($fake->requestBody(1)['messages']);
        $this->assertStringContainsString('Option 2:', $retry);
        $this->assertStringContainsString('(price_per_m2 at x 730, y 354) sit on top of each other', $retry);

        $rejected = collect($gen->validation_log_json)->firstWhere('outcome', 'rejected');
        $this->assertSame('Clash', $rejected['name']);
        $this->assertStringContainsString('sit on top of each other', implode(' ', $rejected['errors']));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A clean 1200x628 design (photo left, colour panel right, title/price/agent on the panel) plus $extra elements.
     *
     * @param  array<int, array<string, mixed>> $extra
     * @return array<string, mixed>
     */
    private function option(array $extra, string $name = 'Panel'): array
    {
        return [
            'name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a',
            'elements' => array_merge([
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 720, 'h' => 628, 'zIndex' => 1],
                ['field' => 'color_block', 'x' => 680, 'y' => 0, 'w' => 520, 'h' => 628, 'zIndex' => 1, 'bg' => '#0b2a4a'],
                ['field' => 'title', 'x' => 720, 'y' => 140, 'w' => 470, 'h' => 120, 'zIndex' => 10, 'fontSize' => 30, 'color' => '#ffffff'],
                ['field' => 'price', 'x' => 720, 'y' => 278, 'w' => 440, 'h' => 72, 'zIndex' => 10, 'fontSize' => 56, 'color' => '#ffffff'],
                ['field' => 'agent_name', 'x' => 720, 'y' => 470, 'w' => 300, 'h' => 30, 'zIndex' => 10, 'fontSize' => 16, 'color' => '#ffffff'],
            ], array_map(fn (array $e) => $e + ['zIndex' => 10], $extra)),
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
