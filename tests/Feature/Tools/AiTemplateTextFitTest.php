<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use Tests\TestCase;

/**
 * AI designs must survive real listings (Johan 2026-10-26): long titles lost their last words (the box clips what does not fit),
 * prices wrapped out of sight, and prices ran past the edge of the shape behind them. The validator now judges text against the
 * longest realistic value of each field and refuses text that is half on / half off a shape.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.12.
 */
final class AiTemplateTextFitTest extends TestCase
{
    private function option(array $extra): array
    {
        return [
            'name' => 'Fit', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a',
            'elements' => array_merge([
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
                ['field' => 'color_block', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'bg' => '#0b2a4a'],
                ['field' => 'agent_name', 'x' => 60, 'y' => 560, 'w' => 400, 'h' => 40, 'zIndex' => 10, 'fontSize' => 22, 'color' => '#ffffff'],
            ], array_map(fn (array $e) => $e + ['zIndex' => 10, 'color' => '#ffffff'], $extra)),
        ];
    }

    public function test_a_title_box_too_small_for_a_long_title_is_refused_with_the_fix(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'title', 'x' => 60, 'y' => 330, 'w' => 440, 'h' => 80, 'fontSize' => 40],
        ]), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('does not fit a 440 x 80 box', implode(' | ', $r['errors']));
        $this->assertStringContainsString('words that do not fit are cut off', implode(' | ', $r['errors']));
    }

    public function test_a_roomy_title_box_passes(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'title', 'x' => 60, 'y' => 330, 'w' => 1080, 'h' => 100, 'fontSize' => 36],
            ['field' => 'price', 'x' => 60, 'y' => 450, 'w' => 420, 'h' => 80, 'fontSize' => 48],
        ]), 'facebook');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }

    public function test_a_price_that_would_wrap_is_refused_because_it_must_stay_on_one_line(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'price', 'x' => 60, 'y' => 450, 'w' => 220, 'h' => 140, 'fontSize' => 56],
        ]), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ONE line', implode(' | ', $r['errors']));
    }

    public function test_repair_shrinks_the_font_when_a_modest_reduction_fits(): void
    {
        $option = $this->option([
            ['field' => 'price', 'x' => 60, 'y' => 450, 'w' => 290, 'h' => 80, 'fontSize' => 48],
        ]);

        $this->assertFalse((new AdLayoutValidator())->validate($option, 'facebook')['ok']);

        $r = (new AdLayoutValidator())->validateAndRepair($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $price = collect($r['layout']['elements'])->firstWhere('field', 'price');
        $this->assertLessThan(48, $price['fontSize']);
        $this->assertGreaterThanOrEqual(34, $price['fontSize'], 'never shrunk by more than 30%');
        $this->assertStringContainsString('fontSize 48 was reduced', implode(' | ', $r['repairs'] ?? []));
    }

    public function test_a_price_half_on_and_half_off_a_circle_is_refused(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'shape', 'x' => 700, 'y' => 320, 'w' => 220, 'h' => 220, 'zIndex' => 5, 'shapeType' => 'circle', 'bg' => '#f4d58d'],
            ['field' => 'price', 'x' => 640, 'y' => 410, 'w' => 420, 'h' => 60, 'fontSize' => 36, 'color' => '#0b2a4a', 'bgOpacity' => 0],
        ]), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('partly on and partly off', implode(' | ', $r['errors']));
    }

    public function test_a_price_wholly_inside_its_band_or_wholly_off_it_is_fine(): void
    {
        $r = (new AdLayoutValidator())->validate($this->option([
            ['field' => 'shape', 'x' => 60, 'y' => 440, 'w' => 460, 'h' => 100, 'zIndex' => 5, 'shapeType' => 'pill', 'bg' => '#f4d58d'],
            ['field' => 'price', 'x' => 110, 'y' => 455, 'w' => 380, 'h' => 70, 'fontSize' => 44, 'color' => '#0b2a4a'],
        ]), 'facebook');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }

    public function test_the_prompt_teaches_both_rules(): void
    {
        $p = (new AdLayoutValidator())->promptLimits(1200, 628);

        $this->assertStringContainsString('1d. Words must FIT', $p);
        $this->assertStringContainsString('1e. Text on a shape', $p);
    }
}
