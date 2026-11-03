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

    /** Reference-picture posters: small tight header/footer lines whose boxes shrinking alone cannot rescue (Johan 2026-11-03). */
    public function test_repair_grows_a_box_into_free_space_when_shrinking_alone_cannot_fit_the_longest_name(): void
    {
        $option = $this->option([
            ['field' => 'agency_name', 'x' => 60, 'y' => 330, 'w' => 224, 'h' => 48, 'fontSize' => 22, 'fontFamily' => 'Montserrat'],
            ['field' => 'website', 'x' => 60, 'y' => 390, 'w' => 224, 'h' => 28, 'fontSize' => 16, 'fontFamily' => 'Montserrat'],
        ]);

        $this->assertFalse((new AdLayoutValidator())->validate($option, 'facebook')['ok']);

        $r = (new AdLayoutValidator())->validateAndRepair($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $els = collect($r['layout']['elements']);
        $website = $els->firstWhere('field', 'website');
        $this->assertGreaterThan(224, $website['w'], 'the one-line website box was widened');
        $this->assertSame(60, $website['x'], 'a left-aligned line grows to the right, its left edge stays');
        $this->assertLessThanOrEqual(1200, $website['x'] + $website['w']);
        $this->assertStringContainsString('the box was made', implode(' | ', $r['repairs']));
    }

    public function test_a_right_aligned_box_grows_leftwards_and_keeps_its_right_edge(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'website', 'x' => 900, 'y' => 330, 'w' => 240, 'h' => 30, 'fontSize' => 18, 'fontFamily' => 'Montserrat', 'textAlign' => 'right'],
        ]), 'facebook');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $website = collect($r['layout']['elements'])->firstWhere('field', 'website');
        $this->assertSame(1140, $website['x'] + $website['w'], 'right edge unchanged');
        $this->assertLessThan(900, $website['x']);
    }

    public function test_growing_never_runs_into_a_neighbour_so_a_boxed_in_line_is_still_refused(): void
    {
        // The website is wedged between two other text boxes: there is no free room, so the design is refused as before.
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'agent_phone', 'x' => 20, 'y' => 330, 'w' => 250, 'h' => 40, 'fontSize' => 20],
            ['field' => 'website', 'x' => 270, 'y' => 330, 'w' => 200, 'h' => 40, 'fontSize' => 18, 'fontFamily' => 'Montserrat'],
            ['field' => 'agency_phone', 'x' => 470, 'y' => 330, 'w' => 250, 'h' => 40, 'fontSize' => 20],
        ]), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ONE line', implode(' | ', $r['errors']));
    }

    public function test_growing_never_pushes_text_off_the_panel_it_sits_on(): void
    {
        // The website sits wholly on a narrow band. Widening it past the band would leave it half on / half off, so it is refused.
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            ['field' => 'shape', 'x' => 60, 'y' => 440, 'w' => 240, 'h' => 50, 'zIndex' => 5, 'shapeType' => 'rectangle', 'bg' => '#f4d58d'],
            ['field' => 'website', 'x' => 60, 'y' => 440, 'w' => 240, 'h' => 50, 'fontSize' => 20, 'fontFamily' => 'Montserrat', 'color' => '#0b2a4a'],
        ]), 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ONE line', implode(' | ', $r['errors']));
    }

    /** Agency-wide fields are judged against the agency's real wording, not one particular agency's long sample (multi-agency rule). */
    public function test_agency_wide_fields_are_judged_against_the_agencys_own_details(): void
    {
        $option = $this->option([
            ['field' => 'agency_name', 'x' => 700, 'y' => 330, 'w' => 202, 'h' => 36, 'fontSize' => 16],
            ['field' => 'website', 'x' => 700, 'y' => 380, 'w' => 202, 'h' => 28, 'fontSize' => 16],
            ['field' => 'agency_phone', 'x' => 700, 'y' => 420, 'w' => 202, 'h' => 28, 'fontSize' => 16],
        ]);
        $plain = new AdLayoutValidator();

        $this->assertFalse($plain->validate($option, 'facebook')['ok'], 'the generic worst-case sample is too long for these boxes');

        $short = $plain->withAgencyCopy(['agency_name' => 'Demo Agency', 'website' => 'demo.co.za', 'agency_phone' => '031 555 0100']);
        $r = $short->validate($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));

        // The agency's own long wording is still held to the box.
        $long = $plain->withAgencyCopy(['agency_name' => 'The Very Long Named Coastal Property Group of KwaZulu-Natal', 'website' => 'demo.co.za', 'agency_phone' => '031 555 0100']);
        $this->assertFalse($long->validate($option, 'facebook')['ok']);
    }

    public function test_an_agency_with_no_website_has_no_website_fit_check_and_listing_fields_keep_the_worst_case(): void
    {
        $validator = (new AdLayoutValidator())->withAgencyCopy(['agency_name' => 'Demo', 'website' => '', 'agency_phone' => '']);

        $ok = $validator->validateAndRepair($this->option([
            ['field' => 'website', 'x' => 700, 'y' => 380, 'w' => 100, 'h' => 28, 'fontSize' => 16],
        ]), 'facebook');
        $this->assertTrue($ok['ok'], implode(' | ', $ok['errors']));

        $price = $validator->validate($this->option([
            ['field' => 'price', 'x' => 60, 'y' => 450, 'w' => 220, 'h' => 140, 'fontSize' => 56],
        ]), 'facebook');
        $this->assertFalse($price['ok']);
        $this->assertStringContainsString('ONE line', implode(' | ', $price['errors']));
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
