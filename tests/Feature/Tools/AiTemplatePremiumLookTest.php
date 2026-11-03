<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use Tests\TestCase;

/**
 * A reference-picture poster rendered flat: the AI wrote a script "FOR SALE" across a gold brush stroke, but the status badge's default
 * red pill was painted over the stroke and the checker swapped the script font for a plain one. Spec: .ai/specs/ad-manager.md §22.12.
 */
final class AiTemplatePremiumLookTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $extra */
    private function option(array $extra): array
    {
        return [
            'name' => 'Look', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b0b0b',
            'elements' => array_merge([
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1000, 'h' => 600, 'zIndex' => 1],
                ['field' => 'price', 'x' => 40, 'y' => 700, 'w' => 600, 'h' => 80, 'zIndex' => 8, 'fontSize' => 44, 'color' => '#ffffff'],
                ['field' => 'agent_name', 'x' => 40, 'y' => 800, 'w' => 600, 'h' => 50, 'zIndex' => 8, 'fontSize' => 28, 'color' => '#ffffff'],
            ], $extra),
        ];
    }

    private function badge(array $layout): array
    {
        return collect($layout['elements'])->firstWhere('field', 'status_badge');
    }

    public function test_a_badge_left_on_its_default_pill_over_a_brush_stroke_has_the_pill_removed(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'shape', 'shapeType' => 'brush_stroke_1', 'bg' => '#c9a84c', 'x' => 500, 'y' => 20, 'w' => 480, 'h' => 120, 'zIndex' => 3],
            ['field' => 'status_badge', 'x' => 520, 'y' => 40, 'w' => 440, 'h' => 70, 'zIndex' => 6, 'fontFamily' => 'Dancing Script', 'fontSize' => 40, 'color' => '#0b0b0b'],
        ]), 'pinterest');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $badge = $this->badge($r['layout']);
        $this->assertEquals(0, $badge['bgOpacity'], 'the default red pill no longer hides the stroke');
        $this->assertSame('Dancing Script', $badge['fontFamily'], 'a script face is allowed on the status badge');
        $this->assertStringContainsString('default coloured pill was removed', implode(' | ', $r['repairs']));
    }

    public function test_a_badge_whose_colours_the_model_chose_is_left_alone(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'shape', 'shapeType' => 'rounded', 'bg' => '#c9a84c', 'x' => 500, 'y' => 20, 'w' => 480, 'h' => 120, 'zIndex' => 3],
            ['field' => 'status_badge', 'x' => 520, 'y' => 40, 'w' => 440, 'h' => 70, 'zIndex' => 6, 'fontSize' => 30, 'bgColor' => '#e63946', 'bgOpacity' => 1],
        ]), 'pinterest');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $this->assertEquals(1, $this->badge($r['layout'])['bgOpacity']);
    }

    public function test_a_badge_not_on_any_shape_keeps_its_default_pill(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'status_badge', 'x' => 520, 'y' => 40, 'w' => 300, 'h' => 60, 'zIndex' => 6, 'fontSize' => 30],
        ]), 'pinterest');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $this->assertEquals(1, $this->badge($r['layout'])['bgOpacity']);
    }

    public function test_a_page_sized_background_block_is_not_mistaken_for_a_shape_of_its_own(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'color_block', 'bg' => '#111111', 'x' => 0, 'y' => 0, 'w' => 1000, 'h' => 1500, 'zIndex' => 2],
            ['field' => 'status_badge', 'x' => 520, 'y' => 40, 'w' => 300, 'h' => 60, 'zIndex' => 6, 'fontSize' => 30],
        ]), 'pinterest');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $this->assertEquals(1, $this->badge($r['layout'])['bgOpacity']);
    }

    public function test_the_prompt_tells_the_model_about_the_default_pill(): void
    {
        $this->assertStringContainsString('bgOpacity" 0', (string) (AdLayoutValidator::FIELDS['status_badge']['help'] ?? ''));
    }
}
