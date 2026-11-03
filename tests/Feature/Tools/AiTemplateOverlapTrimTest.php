<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use Tests\TestCase;

/**
 * Boxes that run into a neighbour are TRIMMED back when their words still fit, and a box that has to be moved is moved once -
 * not again on every pass (the stale-copy bug that threw a suburb line 400px down a poster). Spec: .ai/specs/ad-manager.md §22.11.
 */
final class AiTemplateOverlapTrimTest extends TestCase
{
    private function option(array $extra): array
    {
        return [
            'name' => 'Trim', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a',
            'elements' => array_merge([
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
                ['field' => 'color_block', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'bg' => '#0b2a4a'],
            ], array_map(fn (array $e) => $e + ['zIndex' => 10, 'color' => '#ffffff'], $extra)),
        ];
    }

    public function test_a_box_far_wider_than_its_words_is_trimmed_instead_of_the_design_being_refused(): void
    {
        $option = $this->option([
            // The phone's box runs on under the website's column although "031 555 0100" needs a fraction of it.
            ['field' => 'agent_phone', 'x' => 60, 'y' => 520, 'w' => 700, 'h' => 40, 'fontSize' => 24],
            ['field' => 'agency_phone', 'x' => 500, 'y' => 520, 'w' => 300, 'h' => 40, 'fontSize' => 24],
        ]);

        $this->assertFalse((new AdLayoutValidator())->validate($option, 'facebook')['ok'], 'the two boxes overlap as drawn');

        $r = (new AdLayoutValidator())->validateAndRepair($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $els = collect($r['layout']['elements']);
        $phone = $els->firstWhere('field', 'agent_phone');
        $this->assertSame(60, $phone['x'], 'a left-aligned line keeps its left edge: its empty right end is what is trimmed');
        $this->assertLessThanOrEqual(500 + AdLayoutValidator::OVERLAP_TOLERANCE, $phone['x'] + $phone['w']);
        $this->assertStringContainsString('was trimmed', implode(' | ', $r['repairs']));
    }

    public function test_a_trim_never_makes_the_words_not_fit(): void
    {
        // The phone box is hemmed in on both sides by boxes it cannot be trimmed clear of without cutting its words.
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'agent_email', 'x' => 60, 'y' => 520, 'w' => 420, 'h' => 40, 'fontSize' => 24],
            ['field' => 'agent_phone', 'x' => 300, 'y' => 520, 'w' => 200, 'h' => 40, 'fontSize' => 24],
        ]), 'facebook');

        // Either repaired so that nothing overlaps and every word fits - proved by the repaired layout passing the plain, no-repair
        // check as it stands - or refused. Never accepted with clipped words.
        if ($r['ok']) {
            $again = (new AdLayoutValidator())->validate([
                'name' => 'x', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a', 'elements' => $r['layout']['elements'],
            ], 'facebook');
            $this->assertTrue($again['ok'], implode(' | ', $again['errors']));
        } else {
            $this->assertNotEmpty($r['errors']);
        }
    }

    public function test_trimming_never_takes_a_font_below_half_its_size_or_the_minimum(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'agent_name', 'x' => 60, 'y' => 520, 'w' => 520, 'h' => 50, 'fontSize' => 34],
            ['field' => 'agency_phone', 'x' => 500, 'y' => 520, 'w' => 300, 'h' => 50, 'fontSize' => 24],
        ]), 'facebook');

        if ($r['ok']) {
            $name = collect($r['layout']['elements'])->firstWhere('field', 'agent_name');
            $this->assertGreaterThanOrEqual(17, $name['fontSize'], 'at least half of 34');
            $this->assertGreaterThanOrEqual(AdLayoutValidator::MIN_FONT_SIZE, $name['fontSize']);
        } else {
            $this->assertNotEmpty($r['errors']);
        }
    }

    public function test_a_box_that_has_to_move_is_moved_once_not_on_every_pass(): void
    {
        // A QR code cannot be trimmed, so the clash with the phone line is settled by moving a box. It must move only as far
        // as it takes to clear the other one (the old code kept moving it by the same step on all 12 passes: 400px off).
        $option = $this->option([
            ['field' => 'qr', 'x' => 900, 'y' => 330, 'w' => 120, 'h' => 120],
            ['field' => 'agency_phone', 'x' => 880, 'y' => 440, 'w' => 240, 'h' => 40, 'fontSize' => 20],
        ]);
        $r = (new AdLayoutValidator())->validateAndRepair($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));

        $els = collect($r['layout']['elements']);
        $qr  = $els->firstWhere('field', 'qr');
        $web = $els->firstWhere('field', 'agency_phone');
        $this->assertLessThanOrEqual(60, abs($qr['y'] - 330) + abs($web['y'] - 440), 'moved by about the size of the clash, not hundreds of pixels');
        $this->assertTrue($qr['y'] + $qr['h'] <= $web['y'] + AdLayoutValidator::OVERLAP_TOLERANCE || $web['y'] + $web['h'] <= $qr['y'] + AdLayoutValidator::OVERLAP_TOLERANCE
            || $qr['x'] + $qr['w'] <= $web['x'] + AdLayoutValidator::OVERLAP_TOLERANCE || $web['x'] + $web['w'] <= $qr['x'] + AdLayoutValidator::OVERLAP_TOLERANCE);
    }
}
