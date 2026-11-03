<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AdTemplateDemoPhotos;
use App\Services\AdTemplates\AdTemplateMustIncludes;
use App\Services\AdTemplates\AdTemplateStyles;
use Tests\TestCase;

/**
 * Ad Manager spec §22 - the "premium listing poster" building blocks: 6/7/8-photo mosaics, the feature icon strip,
 * brush-stroke / slanted-panel shapes with a gradient fill, the tagline and per-ad note facts, the Poster size and the
 * "Premium poster" style. The render side is covered by tests/js/ad-building-blocks.mjs (run by AdBuildingBlocksKernelTest).
 * No database needed.
 */
final class AdPremiumPosterBlocksTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $elements */
    private function option(array $elements): array
    {
        return ['name' => 'Poster', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b0b0b', 'elements' => $elements];
    }

    private function el(string $field, int $x, int $y, int $w, int $h, array $o = []): array
    {
        return array_merge(['field' => $field, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h], $o);
    }

    public function test_a_poster_made_of_the_new_blocks_validates_on_the_poster_size(): void
    {
        $gold = ['shapeGradient' => true, 'gradFrom' => '#f6e27a', 'gradTo' => '#b8860b', 'gradAngle' => 90];
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            $this->el('image_1', 0, 150, 1000, 420, ['zIndex' => 1]),
            $this->el('shape', 0, 0, 520, 150, ['shapeType' => 'cut_panel_left', 'bg' => '#0b0b0b', 'zIndex' => 2]),
            $this->el('shape', 560, 20, 420, 190, ['shapeType' => 'brush_stroke_1', 'bg' => '#d4af37', 'zIndex' => 2] + $gold),
            $this->el('custom_text', 600, 40, 340, 80, ['text' => 'For Sale', 'fontSize' => 56, 'fontFamily' => 'Great Vibes', 'color' => '#0b0b0b', 'zIndex' => 3]),
            $this->el('collage', 0, 600, 1000, 520, ['collagePreset' => '7_mosaic', 'collageGap' => 4, 'collageFirst' => 2]),
            $this->el('feature_strip', 0, 1130, 1000, 130, ['stripCount' => 8, 'fontSize' => 16, 'color' => '#ffffff']),
            $this->el('tagline', 640, 1300, 340, 50, ['fontSize' => 28, 'fontFamily' => 'Great Vibes', 'color' => '#d4af37', 'textAlign' => 'center']),
            $this->el('listing_note', 20, 520, 560, 60, ['fontSize' => 22, 'color' => '#ffffff', 'bgColor' => '#0b0b0b', 'bgOpacity' => 0.9, 'zIndex' => 3]),
        ]), 'pinterest');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $by = collect($r['layout']['elements']);
        $this->assertSame(1000, $r['layout']['canvasW']);
        $this->assertSame(1500, $r['layout']['canvasH']);
        $this->assertSame('7_mosaic', $by->firstWhere('field', 'collage')['collagePreset']);
        $this->assertSame(8, $by->firstWhere('field', 'feature_strip')['stripCount']);
        $this->assertTrue($by->firstWhere('shapeType', 'brush_stroke_1')['shapeGradient']);
    }

    public function test_the_icon_strip_is_repaired_when_too_short_and_refused_when_too_narrow(): void
    {
        $v = new AdLayoutValidator();
        $base = [$this->el('image_1', 0, 0, 1000, 600), $this->el('title', 40, 640, 900, 80, ['fontSize' => 40, 'color' => '#ffffff']), $this->el('price', 40, 740, 600, 90, ['fontSize' => 48, 'color' => '#ffffff'])];

        $short = $v->validateAndRepair($this->option(array_merge($base, [$this->el('feature_strip', 0, 900, 960, 40)])), 'pinterest');
        $this->assertTrue($short['ok'], implode(' | ', $short['errors']));
        $this->assertGreaterThanOrEqual(90, collect($short['layout']['elements'])->firstWhere('field', 'feature_strip')['h']);

        $narrow = $v->validateAndRepair($this->option(array_merge($base, [$this->el('feature_strip', 0, 900, 400, 120, ['stripCount' => 8])])), 'pinterest');
        $this->assertFalse($narrow['ok']);
        $this->assertStringContainsString('icon cell', implode(' ', $narrow['errors']));
    }

    public function test_the_new_enums_and_ranges_are_published_to_the_ai(): void
    {
        $this->assertContains('8_mosaic', AdLayoutValidator::enumValues('collagePreset'));
        $this->assertContains('brush_stroke_2', AdLayoutValidator::enumValues('shapeType'));
        $this->assertContains('cut_panel_right', AdLayoutValidator::enumValues('shapeType'));
        $this->assertSame([3, 8], AdLayoutValidator::numberRange('stripCount'));
        $this->assertContains('tagline', AdLayoutValidator::FACT_FIELDS);
        $this->assertContains('listing_note', AdLayoutValidator::FACT_FIELDS);
        $this->assertArrayHasKey('feature_strip', AdLayoutValidator::FIELDS);
        $this->assertArrayHasKey('pinterest', AdLayoutValidator::SIZES);
    }

    public function test_a_collage_answer_naming_a_big_mosaic_snaps_to_a_real_preset(): void
    {
        $r = (new AdLayoutValidator())->validateAndRepair($this->option([
            $this->el('collage', 0, 0, 800, 500, ['collagePreset' => 'mosaic of seven photos']),
            $this->el('title', 40, 540, 700, 80, ['fontSize' => 40, 'color' => '#ffffff', 'bgColor' => '#000000', 'bgOpacity' => 1]),
            $this->el('price', 40, 640, 600, 90, ['fontSize' => 48, 'color' => '#ffffff', 'bgColor' => '#000000', 'bgOpacity' => 1]),
        ]), 'facebook');
        $this->assertSame('7_mosaic', collect($r['layout']['elements'] ?? [])->firstWhere('field', 'collage')['collagePreset'] ?? null, implode(' | ', $r['errors']));
    }

    public function test_the_premium_poster_style_is_valid_and_never_offered_to_company_ads(): void
    {
        $this->assertSame([], AdTemplateStyles::problems());
        $this->assertNotNull(AdTemplateStyles::get('premium_poster'));
        $this->assertContains('premium_poster', AdTemplateStyles::keys());
        $this->assertNotContains('premium_poster', AdTemplateStyles::keys(true));
        $this->assertStringContainsString('premium_poster', AdTemplateStyles::catalogueBlock('pinterest'));
        $this->assertStringNotContainsString('premium_poster', AdTemplateStyles::catalogueBlock('pinterest', [], true));
        $this->assertCount(8, AdTemplateStyles::forPanel(true));
        $this->assertCount(9, AdTemplateStyles::forPanel());
    }

    public function test_the_demo_listing_has_eight_photos_for_the_mosaics(): void
    {
        foreach (['default', 'land', 'farm', 'sectional', 'commercial'] as $category) {
            $set = AdTemplateDemoPhotos::set($category);
            $this->assertCount(8, $set, $category);
            $this->assertCount(8, array_unique($set), "{$category}: no photo may repeat inside one demo listing.");
        }
    }

    public function test_a_reference_picture_allows_every_extra(): void
    {
        $this->assertSame([], AdTemplateMustIncludes::forbiddenFields([AdTemplateMustIncludes::ALL_EXTRAS]));
        $this->assertSame('', AdTemplateMustIncludes::notTickedBlock([AdTemplateMustIncludes::ALL_EXTRAS]));
        $this->assertNotSame([], AdTemplateMustIncludes::forbiddenFields([]), 'without a picture the unticked extras are still forbidden');
    }
}
