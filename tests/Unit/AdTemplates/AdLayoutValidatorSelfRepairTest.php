<?php

declare(strict_types=1);

namespace Tests\Unit\AdTemplates;

use App\Services\AdTemplates\AdLayoutValidator;
use PHPUnit\Framework\TestCase;

/**
 * An AI design with boxes on top of each other, words over a shape's edge, or a ribbon too small for the user's words is
 * repaired instead of refused (Johan 2026-10-30: company ad "agent, property wanted" failed twice on overlaps; nine property
 * ads in a row failed on overlaps and the cut-off corner ribbon). No database: the validator is pure.
 */
final class AdLayoutValidatorSelfRepairTest extends TestCase
{
    private function property(array $extra): array
    {
        return ['name' => 'Panel', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a', 'elements' => array_merge([
            ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 628, 'zIndex' => 1],
            ['field' => 'price', 'x' => 640, 'y' => 40, 'w' => 500, 'h' => 72, 'zIndex' => 10, 'fontSize' => 56, 'color' => '#ffffff'],
        ], array_map(fn (array $e) => $e + ['zIndex' => 10, 'color' => '#ffffff'], $extra))];
    }

    public function test_phone_and_email_on_the_same_spot_are_pulled_apart_and_the_design_passes(): void
    {
        $o = $this->property([
            ['field' => 'agent_phone', 'x' => 640, 'y' => 400, 'w' => 300, 'h' => 30, 'fontSize' => 18],
            ['field' => 'agent_email', 'x' => 640, 'y' => 406, 'w' => 520, 'h' => 30, 'fontSize' => 18],
        ]);
        $this->assertFalse((new AdLayoutValidator())->validate($o, 'facebook')['ok'], 'plain validate still refuses a clash');

        $r = (new AdLayoutValidator())->validateAndRepair($o, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $this->assertNotEmpty(array_filter($r['repairs'], fn ($x) => str_contains($x, 'sat on top of')));
        $els = collect($r['layout']['elements']);
        $a = $els->firstWhere('field', 'agent_phone');
        $b = $els->firstWhere('field', 'agent_email');
        $this->assertTrue($a['y'] + $a['h'] <= $b['y'] || $b['y'] + $b['h'] <= $a['y'] || $a['x'] + $a['w'] <= $b['x'] || $b['x'] + $b['w'] <= $a['x']);
    }

    public function test_a_clash_with_no_room_to_move_is_still_refused(): void
    {
        $o = $this->property([
            ['field' => 'agent_phone', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'fontSize' => 18],
            ['field' => 'agent_email', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'fontSize' => 18],
        ]);
        $this->assertFalse((new AdLayoutValidator())->validateAndRepair($o, 'facebook')['ok']);
    }

    public function test_words_over_a_shape_edge_are_moved_onto_the_shape(): void
    {
        $o = $this->property([
            ['field' => 'color_block', 'x' => 700, 'y' => 340, 'w' => 500, 'h' => 288, 'zIndex' => 2, 'bg' => '#000000'],
            ['field' => 'agent_designation', 'x' => 680, 'y' => 330, 'w' => 300, 'h' => 30, 'fontSize' => 18],
        ]);
        $this->assertFalse((new AdLayoutValidator())->validate($o, 'facebook')['ok']);
        $r = (new AdLayoutValidator())->validateAndRepair($o, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $t = collect($r['layout']['elements'])->firstWhere('field', 'agent_designation');
        $this->assertGreaterThanOrEqual(700, $t['x']);
        $this->assertGreaterThanOrEqual(340, $t['y']);
    }

    public function test_a_small_ribbon_grows_to_hold_the_users_words_instead_of_cutting_them(): void
    {
        $o = $this->property([
            ['field' => 'ribbon', 'x' => 0, 'y' => 0, 'w' => 120, 'h' => 120, 'ribbonCorner' => 'tl', 'text' => 'JUST LISTED NOW', 'bgColor' => '#c0392b', 'bgOpacity' => 0.95, 'fontSize' => 18],
        ]);
        $r = (new AdLayoutValidator())->validateAndRepair($o, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $rib = collect($r['layout']['elements'])->firstWhere('field', 'ribbon');
        $this->assertSame('JUST LISTED NOW', $rib['text'], 'the words are never cut when the band can grow');
        $this->assertGreaterThan(120, $rib['w']);
    }

    public function test_an_agent_ad_with_the_agent_photo_and_no_property_photo_passes_in_property_mode(): void
    {
        $o = ['name' => 'Agent', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b2a4a', 'elements' => [
            ['field' => 'agent_avatar', 'x' => 40, 'y' => 40, 'w' => 300, 'h' => 300, 'zIndex' => 5],
            ['field' => 'agent_name', 'x' => 380, 'y' => 60, 'w' => 600, 'h' => 60, 'zIndex' => 10, 'fontSize' => 40, 'color' => '#ffffff'],
            ['field' => 'agent_phone', 'x' => 380, 'y' => 140, 'w' => 400, 'h' => 36, 'zIndex' => 10, 'fontSize' => 24, 'color' => '#ffffff'],
        ]];
        $r = (new AdLayoutValidator())->validateAndRepair($o, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }
}
