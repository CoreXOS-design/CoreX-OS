<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use Tests\TestCase;

/**
 * AT-456 §B.9-1 — template builder building blocks: the kernel half.
 *
 * Runs the repo's node checks against the real shipped kernel (public/js/corex-ad-render.js):
 *   - tests/js/ad-building-blocks.mjs     every new block (icons, fonts, shapes, masks, effects, collage, ribbon, QR, pills, facts)
 *   - tests/js/ad-legacy-snapshot.mjs     "existing saved templates render exactly as before": 564 frozen renderings of
 *                                         old-shape elements, recorded from the kernel as it was BEFORE B.9-1
 *   - tests/js/ad-render-kernel.mjs       the pre-existing kernel contract
 *   - tests/js/ad-export-shapes.mjs       AT-456 §B.9-2: the six polygon shapes export like they look (html2canvas ignores clip-path)
 * and keeps the AI validator's allowlists in step with the kernel's lists (drift guards).
 */
final class AdBuildingBlocksKernelTest extends TestCase
{
    /** @return array<int, string> */
    public static function nodeChecks(): array
    {
        return [
            'new blocks'         => ['tests/js/ad-building-blocks.mjs'],
            'legacy snapshot'    => ['tests/js/ad-legacy-snapshot.mjs'],
            'kernel contract'    => ['tests/js/ad-render-kernel.mjs'],
            'export-safe shapes' => ['tests/js/ad-export-shapes.mjs'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nodeChecks')]
    public function test_node_checks_pass(string $script): void
    {
        $node = trim((string) @shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available on this machine.');
        }
        $out = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg(base_path($script)) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, $script . " failed:\n" . implode("\n", array_filter($out, fn ($l) => str_contains($l, 'FAIL') || str_contains($l, 'was:') || str_contains($l, 'now:') || str_contains($l, 'got:') || str_contains($l, 'FAILED'))));
    }

    // ── drift guards: the AI validator's allowlists must mirror the kernel ─────────────

    public function test_validator_icons_match_the_kernel_icon_picker(): void
    {
        $this->assertSame($this->kernelList('ICON_LIST', "/\\{\\s*key:\\s*'([a-z_]+)',\\s*label:/"), $this->validatorConst('ICONS'));
    }

    public function test_validator_shapes_match_the_kernel_shapes_and_decorative_shapes(): void
    {
        $kernel = $this->kernel();
        $plain  = $this->kernelList('SHAPES', "/\\{\\s*type:\\s*'([a-z_]+)',\\s*label:/");
        preg_match('/var DECOR_SHAPES = \{(.*?)\};/s', $kernel, $m);
        preg_match_all("/^\\s*([a-z_0-9]+):\\s+'M/m", $m[1] ?? '', $d);

        $this->assertCount(7, $d[1], 'Expected the 7 decorative shapes in DECOR_SHAPES.');
        $this->assertSame(array_merge($plain, $d[1]), $this->validatorConst('SHAPES'));
    }

    public function test_validator_new_enum_mirrors_match_the_kernel(): void
    {
        $this->assertSame($this->kernelList('PHOTO_MASKS', "/\\{\\s*key:\\s*'([a-z_]+)',\\s*label:/"), $this->validatorConst('PHOTO_MASKS'));
        $this->assertSame($this->kernelList('PHOTO_EFFECTS', "/\\{\\s*key:\\s*'([a-z_]+)',\\s*label:/"), $this->validatorConst('PHOTO_EFFECTS'));
        preg_match("/var LINE_STYLES = \\[(.*?)\\];/s", $this->kernel(), $m);
        preg_match_all("/'([a-z]+)'/", $m[1], $l);
        $this->assertSame($l[1], $this->validatorConst('LINE_STYLES'));
    }

    public function test_every_lazy_font_is_declared_in_the_font_sheet_with_a_matching_stylesheet_slug(): void
    {
        $kernel = $this->kernel();
        preg_match_all("/\\{ name: '([^']+)',\\s*stack: [^}]*lazy: true/", $kernel, $m);
        $lazy = $m[1];
        $this->assertCount(12, $lazy);

        $sheet = (string) file_get_contents(base_path('resources/views/corex/properties/_ad-fonts.blade.php'));
        foreach ($lazy as $family) {
            $slug = str_replace(' ', '-', strtolower($family));
            $this->assertMatchesRegularExpression("/'" . preg_quote($family, '/') . "':\\s*'" . preg_quote($slug, '/') . ":[0-9,]+'/", $sheet, "{$family} is not declared in the lazy font map of _ad-fonts.blade.php.");
        }
        preg_match_all("/^\\s*'([^']+)':\\s*'[a-z-]+:[0-9,]+'/m", $sheet, $declared);
        $this->assertEqualsCanonicalizing($lazy, $declared[1], 'The lazy font map and the kernel FONTS (lazy: true) must list the same families.');
    }

    public function test_the_validator_accepts_new_fonts_icons_shapes_masks_effects_and_line_styles(): void
    {
        $option = [
            'name' => 'New blocks', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 628, 'photoMask' => 'arch', 'photoEffect' => 'duotone', 'duoColor1' => '#0b2a4a', 'duoColor2' => '#f4d58d', 'frameBorderWidth' => 6],
                ['field' => 'image_2', 'x' => 620, 'y' => 20, 'w' => 200, 'h' => 200, 'photoMask' => 'blob', 'photoEffect' => 'bw'],
                ['field' => 'shape', 'x' => 600, 'y' => 400, 'w' => 600, 'h' => 228, 'shapeType' => 'wave', 'bg' => '#2a9d8f'],
                ['field' => 'shape', 'x' => 900, 'y' => 20, 'w' => 200, 'h' => 200, 'shapeType' => 'blob_3', 'bg' => '#e9c46a'],
                ['field' => 'line', 'x' => 640, 'y' => 300, 'w' => 500, 'h' => 12, 'lineStyle' => 'dashed', 'borderWidth' => 4, 'color' => '#ffffff'],
                ['field' => 'title', 'x' => 640, 'y' => 330, 'w' => 540, 'h' => 60, 'fontSize' => 36, 'fontFamily' => 'Cormorant Garamond', 'color' => '#ffffff', 'shadowOn' => true],
                ['field' => 'beds', 'x' => 640, 'y' => 420, 'w' => 200, 'h' => 40, 'fontSize' => 20, 'fontFamily' => 'Space Grotesk', 'icon' => 'sea_view', 'numberFormat' => 'label', 'color' => '#ffffff', 'bgColor' => '#000000', 'bgOpacity' => 0.8],
            ],
        ];

        $r = (new AdLayoutValidator())->validate($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));

        $byField = collect($r['layout']['elements']);
        $image = $byField->firstWhere('field', 'image_1');
        $this->assertSame('arch', $image['photoMask']);
        $this->assertSame('duotone', $image['photoEffect']);
        $this->assertSame('#f4d58d', $image['duoColor2']);
        $this->assertSame('dashed', $byField->firstWhere('field', 'line')['lineStyle']);
        $this->assertSame('Cormorant Garamond', $byField->firstWhere('field', 'title')['fontFamily']);
        // The complete element carries the new keys at their "not set" values, like the builder's makeElement().
        $this->assertSame('blob', $byField->firstWhere('field', 'image_2')['photoMask']);
        $this->assertSame('none', $byField->firstWhere('field', 'title')['photoMask']);
        $this->assertSame('solid', $byField->firstWhere('field', 'title')['lineStyle']);
        $this->assertSame('none', $byField->firstWhere('field', 'title')['photoEffect']);
    }

    public function test_the_validator_still_refuses_a_hostile_duotone_colour_and_an_unknown_mask(): void
    {
        $base = fn (array $patch) => [
            'name' => 'Bad', 'canvasBg' => '#0b1220',
            'elements' => [
                array_merge(['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 628], $patch),
                ['field' => 'title', 'x' => 640, 'y' => 330, 'w' => 540, 'h' => 60, 'fontSize' => 36, 'color' => '#ffffff', 'shadowOn' => true],
                ['field' => 'price', 'x' => 640, 'y' => 400, 'w' => 540, 'h' => 60, 'fontSize' => 36, 'color' => '#ffffff', 'shadowOn' => true],
            ],
        ];
        $v = new AdLayoutValidator();

        $r = $v->validate($base(['photoEffect' => 'duotone', 'duoColor1' => 'red;"><script>alert(1)</script>']), 'facebook');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not a valid colour', implode(' | ', $r['errors']));

        $r = $v->validate($base(['photoMask' => 'hexagon']), 'facebook');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('photoMask', implode(' | ', $r['errors']));
    }

    /** AT-456 §B.9-2: the AI is now taught every block (this replaces the B.9-1 test that pinned the OLD prompt). */
    public function test_the_ai_prompt_now_teaches_every_new_block(): void
    {
        $limits = (new AdLayoutValidator())->promptLimits(1200, 628);

        $this->assertStringContainsString('Great Vibes', $limits);
        $this->assertStringContainsString('sea_view', $limits);
        $this->assertStringContainsString('blob_1', $limits);
        foreach (['photoMask', 'photoEffect', 'lineStyle', 'collagePreset', 'ribbonCorner', 'pillDirection', 'ribbon', 'qr', 'feature_pills'] as $taught) {
            $this->assertStringContainsString($taught, $limits);
        }
        foreach (['collage', 'qr', 'ribbon', 'feature_pills', 'levy', 'show_day', 'auction_venue'] as $type) {
            $this->assertArrayHasKey($type, AdLayoutValidator::FIELDS);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function kernel(): string
    {
        return (string) file_get_contents(base_path('public/js/corex-ad-render.js'));
    }

    /** @return array<int, string> */
    private function kernelList(string $var, string $itemRegex): array
    {
        preg_match('/var ' . $var . ' = \[(.*?)\n    \];/s', $this->kernel(), $m);
        $this->assertNotEmpty($m, "Could not find {$var} in the kernel.");
        preg_match_all($itemRegex, $m[1], $items);

        return $items[1];
    }

    /** @return array<int, string> */
    private function validatorConst(string $name): array
    {
        return (new \ReflectionClassConstant(AdLayoutValidator::class, $name))->getValue();
    }
}
