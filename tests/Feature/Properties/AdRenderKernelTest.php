<?php

namespace Tests\Feature\Properties;

use Tests\TestCase;

/**
 * AT-252 — the Ad render kernel is the ONLY renderer.
 *
 * Custom ad templates are drawn on three surfaces: the Ad Builder, the single-property
 * generator, and the bulk Ad Manager. Each used to carry its own copy of the geometry,
 * style and value logic — and they drifted. By the time it was caught, the bulk manager's
 * copy did not know about shapeType/clip-paths, custom image + video elements, the
 * features chooser, or the agent-2 empty-slot rule, so all four rendered WRONG on ads
 * that went out to clients (a single-agent listing printed the literal words
 * "Agent 2 · Name" onto the artwork).
 *
 * The fix was to extract public/js/corex-ad-render.js. These tests are what stop the
 * drift coming back: a new surface that hand-rolls its own element loop, or a font the
 * builder offers but an ad page never loads, fails here instead of on a client's ad.
 *
 * The render logic itself is exercised by tests/js/ad-render-kernel.mjs.
 */
class AdRenderKernelTest extends TestCase
{
    /** Every Blade view that renders a custom ad template. */
    private const AD_SURFACES = [
        'resources/views/corex/properties/ad-builder.blade.php',
        'resources/views/corex/properties/ad.blade.php',
        'resources/views/tools/ad-manager.blade.php',
    ];

    private const KERNEL = 'public/js/corex-ad-render.js';
    private const FONTS  = 'resources/views/corex/properties/_ad-fonts.blade.php';

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, "Expected {$relative} to exist.");

        return file_get_contents($path);
    }

    public function test_the_kernel_exposes_the_contract_every_ad_surface_relies_on(): void
    {
        $kernel = $this->read(self::KERNEL);

        foreach (['frameStyle', 'contentHtml', 'renderLayout', 'textStyle', 'shapeCss',
                  'textValue', 'imageSrc', 'canvasBackground', 'canvasBgSolid', 'makeElement'] as $fn) {
            $this->assertStringContainsString(
                "{$fn}: {$fn}",
                $kernel,
                "The kernel must export {$fn}() — an ad surface depends on it."
            );
        }
    }

    public function test_every_ad_surface_loads_the_shared_kernel(): void
    {
        foreach (self::AD_SURFACES as $view) {
            $this->assertStringContainsString(
                'js/corex-ad-render.js',
                $this->read($view),
                "{$view} renders custom ad templates, so it MUST load the shared kernel."
            );
        }
    }

    /**
     * The drift guard. An ad view that re-declares the geometry or hand-rolls its own
     * element loop has started a second renderer — which is exactly how the four bugs
     * above shipped. Use the kernel; do not grow a private copy.
     */
    public function test_no_ad_surface_redeclares_the_kernels_geometry(): void
    {
        $banned = [
            'SHAPE_CLIPS'        => 'shape geometry — use CoreXAd.shapeCss() / CoreXAd.SHAPE_CLIPS',
            'IMAGE_FIELDS'       => 'the image-field list — use CoreXAd.isImageField()',
            'NON_TEXT_FIELDS'    => 'the text-field list — use CoreXAd.isTextField()',
            'FIELD_DEFAULTS'     => 'the per-field defaults — use CoreXAd.FIELD_DEFAULTS',
            'hexToRgba(hex, a)'  => 'colour conversion — use CoreXAd.hexToRgba()',
        ];

        foreach (self::AD_SURFACES as $view) {
            $src = $this->read($view);

            foreach ($banned as $needle => $why) {
                // A CoreXAd.<needle> reference is the correct usage; a bare declaration is not.
                $bare = preg_replace('/CoreXAd\.' . preg_quote($needle, '/') . '/', '', $src);

                $this->assertStringNotContainsString(
                    $needle,
                    $bare,
                    "{$view} declares its own {$needle}. That is a second renderer, and second "
                    . "renderers drift — it is how custom shapes, custom media, the features "
                    . "chooser and the agent-2 rule all broke on the bulk Ad Manager. Use the "
                    . "shared kernel instead ({$why})."
                );
            }
        }
    }

    /**
     * A font the builder offers but an ad page never loads would silently fall back to
     * Figtree — the designer picks Bebas Neue, approves the preview, and the downloaded
     * PNG comes out in the wrong face. The two lists must not drift apart.
     */
    public function test_every_font_the_builder_offers_is_actually_loaded_on_the_ad_surfaces(): void
    {
        $kernel = $this->read(self::KERNEL);
        $fonts  = $this->read(self::FONTS);

        preg_match_all("/\{ name: '([^']+)',\s*stack:/", $kernel, $m);
        $families = $m[1];

        $this->assertNotEmpty($families, 'Could not read the FONTS catalogue out of the kernel.');

        foreach ($families as $family) {
            // "Playfair Display" is requested from the font CDN as "playfair-display".
            $slug = str_replace(' ', '-', strtolower($family));

            $this->assertStringContainsString(
                $slug,
                $fonts,
                "The Ad Builder offers '{$family}' but _ad-fonts.blade.php never loads it, so it "
                . "would silently render as Figtree on the finished ad. Add '{$slug}' to the "
                . "stylesheet, or drop the family from FONTS in the kernel."
            );
        }
    }

    /** Every ad surface must load the font sheet, or picked fonts fall back mid-pipeline. */
    public function test_every_ad_surface_loads_the_shared_font_sheet(): void
    {
        foreach (self::AD_SURFACES as $view) {
            $this->assertStringContainsString(
                '_ad-fonts',
                $this->read($view),
                "{$view} must include the shared ad font sheet."
            );
        }
    }
}
