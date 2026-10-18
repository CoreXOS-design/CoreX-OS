<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\AiTemplateGeneration;
use App\Models\User;
use App\Services\AdTemplates\AdTemplateDemoPhotos;
use App\Services\AdTemplates\AdTemplateMustIncludes;
use App\Services\AdTemplates\AdTemplatePropertyTypes;
use App\Services\AdTemplates\AdTemplateStyles;
use App\Services\AdTemplates\AiTemplateBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 §B.10, decision 20 - "Extras only when ticked": a QR code, photo collage, corner ribbon, show day date and
 * key-feature pills appear in a design ONLY when the user ticked them. Price, agent photo/contact and the agency logo are
 * normal ad content and are never touched. Also: the demo photos the previews use, and the full-size viewer's markup.
 *
 * Every Anthropic call is Http::fake()d; nothing here spends a cent.
 */
final class AiTemplateExtrasOnlyWhenTickedTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_EXTRAS = ['qr', 'collage', 'ribbon', 'show_day', 'feature_pills'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Storage::fake('local');
    }

    // ── 1. the one definition ──────────────────────────────────────────

    public function test_the_five_extras_are_defined_once_and_price_agent_and_logo_are_not_extras(): void
    {
        $this->assertEqualsCanonicalizing(
            ['qr' => 'qr', 'collage' => 'collage', 'ribbon' => 'ribbon', 'show_day' => 'show_day', 'feature_pills' => 'feature_pills'],
            AdTemplateMustIncludes::extras()
        );
        foreach (['big_price', 'agent_contact', 'logo'] as $normal) {
            $this->assertArrayNotHasKey($normal, AdTemplateMustIncludes::extras());
        }
        $this->assertEqualsCanonicalizing(self::ALL_EXTRAS, AdTemplateMustIncludes::forbiddenFields([]));
        $this->assertEqualsCanonicalizing(['collage', 'ribbon', 'show_day', 'feature_pills'], AdTemplateMustIncludes::forbiddenFields(['qr', 'big_price']));
        $this->assertSame([], AdTemplateMustIncludes::forbiddenFields(['qr', 'collage', 'ribbon', 'show_day', 'feature_pills']));
    }

    // ── 2. enforcement ─────────────────────────────────────────────────

    public function test_an_unticked_qr_is_removed_from_the_default_design_and_logged_and_the_design_is_kept(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => [$this->design('A', ['qr']), $this->design('B', ['qr']), $this->design('C', [])]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['big_price']);

        $this->assertSame(1, $fake->calls(), 'nothing was dropped, so no retry');
        $this->assertCount(3, $gen->options_json, 'the design is never dropped for a stray extra');
        foreach ($gen->options_json as $opt) {
            $this->assertNotContains('qr', array_column($opt['layout']['elements'], 'field'));
        }
        $entry = collect($gen->validation_log_json)->firstWhere('name', 'A');
        $this->assertSame('repaired', $entry['outcome']);
        $this->assertContains('removed QR code to the listing - not ticked', $entry['repairs']);
    }

    public function test_with_no_ticks_none_of_the_five_extras_survive_in_any_design(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->fakeAi(fn () => array_map(fn ($n) => $this->design('D' . $n, self::ALL_EXTRAS), [1, 2, 3]));
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], []);

        $this->assertCount(3, $gen->options_json);
        foreach ($gen->options_json as $opt) {
            $this->assertSame([], array_values(array_intersect(self::ALL_EXTRAS, array_column($opt['layout']['elements'], 'field'))));
        }
        $log = collect($gen->validation_log_json)->firstWhere('name', 'D1');
        foreach (['QR code to the listing', 'Photo collage', 'Corner ribbon', 'Show day date', 'Key features as pills'] as $label) {
            $this->assertContains("removed {$label} - not ticked", $log['repairs']);
        }
    }

    public function test_a_ticked_extra_is_kept_and_required_while_the_other_extras_are_removed(): void
    {
        [, $user] = $this->agencyWithUser();
        // Calls 1 and 2 (stage 1 + its retry): design A has qr and a stray ribbon; B and C have no qr at all.
        $fake = $this->fakeAi(fn (array $call) => $call['n'] === 1
            ? [$this->design('A', ['qr', 'ribbon']), $this->design('B', ['ribbon']), $this->design('C', [])]
            : [$this->design('D', ['ribbon']), $this->design('E', [])]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);

        $this->assertSame(2, $fake->calls(), 'the ticked QR is still enforced: one targeted retry');
        $this->assertCount(1, $gen->options_json, 'decision 19 still applies: designs without the ticked QR are dropped');
        $fields = array_column($gen->options_json[0]['layout']['elements'], 'field');
        $this->assertContains('qr', $fields);
        $this->assertNotContains('ribbon', $fields, 'the unticked ribbon is removed from the design that was kept');
    }

    public function test_price_agent_photo_and_contact_and_logo_are_untouched_when_unticked(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->fakeAi(fn () => [$this->design('A', ['qr', 'agent_contact', 'logo']), $this->design('B', ['agent_contact', 'logo']), $this->design('C', [])]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], []);

        $fields = array_column($gen->options_json[0]['layout']['elements'], 'field');
        foreach (['price', 'agent_avatar', 'agent_phone', 'agent_name', 'logo'] as $normal) {
            $this->assertContains($normal, $fields, "{$normal} must stay");
        }
        $this->assertNotContains('qr', $fields);
        // The price keeps its size - nothing was "repaired" except the QR.
        $price = collect($gen->options_json[0]['layout']['elements'])->firstWhere('field', 'price');
        $this->assertGreaterThanOrEqual(56, (float) $price['fontSize']);
    }

    public function test_a_collage_that_was_the_only_photo_becomes_the_main_photo_in_its_box(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->fakeAi(fn () => array_map(fn ($n) => $this->design('D' . $n, ['collage'], withPhoto: false), [1, 2, 3]));
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], []);

        $this->assertCount(3, $gen->options_json, 'the design survives - the validator still finds a property photo');
        $els = $gen->options_json[0]['layout']['elements'];
        $fields = array_column($els, 'field');
        $this->assertNotContains('collage', $fields);
        $photo = collect($els)->firstWhere('field', 'image_1');
        $this->assertNotNull($photo);
        $this->assertEquals(600, $photo['x']);
        $this->assertEquals(20, $photo['y']);
        $this->assertEquals(400, $photo['w']);
        $this->assertEquals(260, $photo['h']);
        $log = collect($gen->validation_log_json)->firstWhere('name', 'D1');
        $this->assertTrue((bool) collect($log['repairs'])->first(fn ($r) => str_contains($r, 'removed Photo collage - not ticked') && str_contains($r, 'main photo')));
    }

    public function test_a_collage_beside_a_real_photo_is_simply_removed(): void
    {
        [$d, $notes] = AdTemplateMustIncludes::stripUnticked($this->design('x', ['collage']), []);
        $this->assertNotContains('collage', array_column($d['elements'], 'field'));
        $this->assertSame(1, count(array_filter(array_column($d['elements'], 'field'), fn ($f) => $f === 'image_1')));
        $this->assertSame(['removed Photo collage - not ticked'], $notes);
    }

    public function test_a_ticked_extra_is_never_stripped(): void
    {
        $design = $this->design('x', self::ALL_EXTRAS);
        [$out, $notes] = AdTemplateMustIncludes::stripUnticked($design, self::ALL_EXTRAS);
        $this->assertSame([], $notes);
        $this->assertSame($design, $out);
    }

    public function test_extras_are_also_removed_from_every_property_type_version(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        foreach (['House', 'Vacant Land / Plot'] as $i => $name) {
            DB::table('property_setting_items')->insert(['agency_id' => $agency, 'group' => 'property_type', 'name' => $name, 'sort_order' => $i, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Every answer - Defaults and each type's version - is full of extras.
        $fake = $this->fakeAi(function (array $call) {
            if (str_starts_with($call['user'], 'Adapt ')) {
                preg_match_all('/<design for_option="(\d+)"/', $call['user'], $m);

                return array_map(fn ($n) => ['for_option' => (int) $n] + $this->design('V' . $n, self::ALL_EXTRAS), $m[1]);
            }

            return array_map(fn ($n) => $this->design('D' . $n, self::ALL_EXTRAS), [1, 2, 3]);
        });
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['House', 'Vacant Land / Plot'], ['show_day']);

        $this->assertSame(1 + 2, $fake->calls());
        foreach ($gen->options_json as $opt) {
            $this->assertNotEmpty($opt['variants']);
            foreach ([$opt['layout'], ...array_column($opt['variants'], 'layout')] as $layout) {
                $fields = array_column($layout['elements'], 'field');
                $this->assertContains('show_day', $fields, 'the ticked show day stays in every version');
                foreach (['qr', 'collage', 'ribbon', 'feature_pills'] as $extra) {
                    $this->assertNotContains($extra, $fields, "unticked {$extra} must not be in any version");
                }
            }
        }
        $typeLog = collect($gen->validation_log_json)->first(fn ($e) => ($e['stage'] ?? '') === 'variant');
        $this->assertContains('removed QR code to the listing - not ticked', $typeLog['repairs']);
        // The type call's own prompt forbids them too.
        $typeCall = collect($fake->requests)->first(fn ($r) => str_starts_with($r['user'], 'Adapt '));
        $this->assertStringContainsString('DO NOT USE', $typeCall['user']);
        $this->assertStringNotContainsString('Show day date ("show_day")', $typeCall['user'], 'a ticked extra is not in the forbidden list');
    }

    // ── 3. the prompt ──────────────────────────────────────────────────

    public function test_the_prompt_forbids_every_unticked_extra_and_leaves_out_their_instructions(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => [$this->design('A'), $this->design('B'), $this->design('C')]);
        $this->actingAs($user);

        $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['big_price']);

        $user0 = $fake->requests[0]['user'];
        $sys   = $fake->requests[0]['system'];
        $this->assertStringContainsString('DO NOT USE', $user0);
        foreach (['QR code to the listing ("qr")', 'Photo collage ("collage")', 'Corner ribbon ("ribbon")', 'Show day date ("show_day")', 'Key features as pills ("feature_pills")'] as $forbidden) {
            $this->assertStringContainsString($forbidden, $user0);
        }
        // The element types are not even offered, and their how-to lines are gone.
        foreach (['- qr:', '- collage:', '- ribbon:', '- feature_pills:', '- show_day:'] as $line) {
            $this->assertStringNotContainsString($line, $sys, "{$line} must not be in the catalogue");
        }
        $this->assertStringNotContainsString('ribbon: "text" is required', $sys);
        $this->assertStringNotContainsString('qr: w and h at least', $sys);
        $this->assertStringNotContainsString('feature_pills: the box must be tall', $sys);
        $this->assertStringNotContainsString('or a collage', $sys);
        $this->assertMatchesRegularExpression('/LISTING FACTS \(([^)]*)\)/', $sys);
        preg_match('/LISTING FACTS \(([^)]*)\)/', $sys, $m);
        $this->assertStringNotContainsString('show_day', $m[1]);
        $this->assertStringContainsString('erf_size', $m[1]);
        // The normal content stays on offer.
        foreach (['- price:', '- agent_avatar:', '- logo:'] as $line) {
            $this->assertStringContainsString($line, $sys);
        }
    }

    public function test_a_ticked_extra_is_offered_and_asked_for_and_not_forbidden(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => [$this->design('A', ['qr']), $this->design('B', ['qr']), $this->design('C', ['qr'])]);
        $this->actingAs($user);

        $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);

        $this->assertStringContainsString('- qr:', $fake->requests[0]['system']);
        $this->assertStringContainsString('qr: w and h at least', $fake->requests[0]['system']);
        $this->assertStringContainsString('MUST INCLUDE', $fake->requests[0]['user']);
        $this->assertStringNotContainsString('QR code to the listing ("qr")', $fake->requests[0]['user']);
        $this->assertStringContainsString('Photo collage ("collage")', $fake->requests[0]['user']);
    }

    public function test_styles_never_suggest_an_unticked_extra_but_do_when_it_is_ticked(): void
    {
        $forbidden = AdTemplateMustIncludes::forbiddenFields([]);
        $catalogue = AdTemplateStyles::catalogueBlock('facebook', $forbidden);
        $lines = array_filter(explode("\n", $catalogue), fn ($l) => str_contains($l, 'Suits:'));
        $this->assertCount(count(AdTemplateStyles::keys()), $lines);
        foreach ($lines as $line) {
            $suits = preg_split('/,\s*/', rtrim(trim(substr($line, strpos($line, 'Suits:') + 6)), '.)'));
            foreach ($forbidden as $f) {
                $this->assertNotContains($f, array_map(fn ($x) => trim(explode(' ', trim($x))[0], '. '), $suits), "style suits must not list {$f}: {$line}");
            }
        }
        foreach (AdTemplateStyles::keys() as $key) {
            $block = AdTemplateStyles::chosenBlock($key, 'facebook', $forbidden);
            preg_match('/Blocks that suit it: (.*)\./', $block, $m);
            foreach (preg_split('/,\s*/', $m[1]) as $b) {
                $this->assertNotContains($b, $forbidden, "{$key} suggests {$b}");
            }
            // The rest of the style's wording does not point at an extra either.
            foreach (['sash', 'a collage', 'QR code or', 'as pills'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $block, "{$key}: {$phrase}");
            }
        }
        // Ticked: the style may suggest it again.
        $this->assertStringContainsString('qr', AdTemplateStyles::chosenBlock('modern_minimal', 'facebook', []));
        $this->assertSame([], AdTemplateStyles::problems(), 'the style library is still valid');
    }

    public function test_the_property_type_hint_leaves_pills_out_when_they_are_not_ticked(): void
    {
        $this->assertStringContainsString('feature_pills', AdTemplatePropertyTypes::hint('House'));
        $this->assertStringNotContainsString('feature_pills', AdTemplatePropertyTypes::hint('House', false));
        $this->assertStringContainsString('feature_pills', AdTemplatePropertyTypes::hint('Farm'));
        $this->assertStringNotContainsString('feature_pills', AdTemplatePropertyTypes::hint('Farm', false));
    }

    // ── 4. demo photos ─────────────────────────────────────────────────

    public function test_the_demo_photo_files_exist_are_real_jpegs_and_come_from_the_licensed_demo_pool(): void
    {
        foreach (AdTemplateDemoPhotos::FILES as $name) {
            $path = public_path(AdTemplateDemoPhotos::DIR . '/' . $name . '.jpg');
            $this->assertFileExists($path);
            $info = getimagesize($path);
            $this->assertSame('image/jpeg', $info['mime'], $name);
            $this->assertSame(1200, $info[0], "{$name} is the 1200 px copy");
            $this->assertLessThan(260 * 1024, filesize($path));
        }
        $this->assertDirectoryExists(database_path('seeders/data/demo-properties'), 'the licensed pool they were made from is still in the repo');
    }

    public function test_the_sample_listing_uses_demo_photos_and_no_agency_data(): void
    {
        [, $user] = $this->agencyWithUser();
        $html = $this->renderIndex($user);

        $set = AdTemplateDemoPhotos::set();
        $this->assertSame(['image_1', 'image_2', 'image_3', 'image_4', 'image_5'], array_keys($set));
        $this->assertCount(5, array_unique($set), 'five different photos');
        foreach ($set as $url) {
            $this->assertSame(1, preg_match('#' . 'ad-demo\\\\*/' . preg_quote(basename($url), '#') . '#', $html), 'the pop-up is given ' . basename($url));
            $this->assertStringContainsString('/' . AdTemplateDemoPhotos::DIR . '/', $url);
            $this->assertStringNotContainsString('storage/properties', $url);
            $this->assertStringNotContainsString('data:image/svg', $url);
        }
        $this->assertStringNotContainsString('data:image\/svg+xml', $html, 'the grey placeholder house is gone');
        // Nothing in the demo set names an agency.
        foreach (AdTemplateDemoPhotos::FILES as $name) {
            $this->assertDoesNotMatchRegularExpression('/hfc|coastal|agency/i', $name);
        }
    }

    public function test_each_property_type_version_previews_with_a_fitting_first_photo(): void
    {
        $default = AdTemplateDemoPhotos::set();
        $land    = AdTemplateDemoPhotos::set('land');
        $flat    = AdTemplateDemoPhotos::set('sectional');
        $this->assertStringEndsWith('/exterior.jpg', $default['image_1']);
        $this->assertStringEndsWith('/lawn.jpg', $land['image_1'], 'land opens on a lawn / garden');
        $this->assertStringEndsWith('/apartments.jpg', $flat['image_1'], 'a flat opens on an apartment block');

        $byType = AdTemplateDemoPhotos::forTypes(['Vacant Land / Plot', 'Apartment / Flat', 'House', 'Cottage']);
        $this->assertSame($land, $byType['Vacant Land / Plot']);
        $this->assertSame($flat, $byType['Apartment / Flat']);
        $this->assertArrayNotHasKey('House', $byType, 'a type with no special set uses the default photos');
    }

    // ── 5. the full-size viewer's markup and wiring ────────────────────

    public function test_clicking_a_preview_or_its_button_opens_a_full_screen_viewer_with_the_right_controls(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $html    = $this->renderIndex($user);
        // The pop-up plus the dialog shell it shares with the company-ad pop-up (styles, viewer and dialog behaviour live there).
        $partial = (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-template-modal.blade.php'))
            . (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-dialog-shell.blade.php'));

        // Both the preview itself and an explicit button open it.
        $this->assertStringContainsString('@click="openViewer(opt)"', $html);
        $this->assertStringContainsString('data-aitp="view-full"', $html);
        $this->assertStringContainsString('View full size', $html);
        // The viewer: teleported above the pop-up, dialog semantics, dark backdrop, close X, backdrop click, version switch, prev/next.
        foreach (['data-aitv="overlay"', 'data-aitv="backdrop"', 'data-aitv="close"', 'data-aitv="stage"', 'data-aitv="frame"', 'data-aitv="prev"', 'data-aitv="next"', 'data-aitv="version"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('aria-label="Full-size preview"', $html);
        $this->assertStringContainsString('@click="closeViewer()"', $html);
        $this->assertStringContainsString('@keydown.arrow-left.window="viewerStep(-1)"', $html);
        $this->assertStringContainsString('@keydown.arrow-right.window="viewerStep(1)"', $html);
        // Esc closes the viewer first, then (only) the pop-up; the pop-up is never closable or restartable under it while generating.
        $this->assertStringContainsString('if (this.viewer.open) { this.closeViewer(); return; }', $partial);
        $this->assertStringContainsString('if (this.loading) return;', $partial);
        $this->assertStringContainsString('this.closeViewer(false);', $partial);
        // Focus returns to the card; the same render kernel draws it, scaled to the largest size that fits with the aspect kept.
        $this->assertStringContainsString("document.querySelector('[data-aitp-view=\"'", $partial);
        $this->assertStringContainsString('window.CoreXAd.renderLayout(l, this.sampleFor(opt)', $partial);
        $this->assertStringContainsString('Math.min(aw / cw, ah / ch)', $partial);
        $this->assertStringContainsString('ResizeObserver', $partial);
        // Phone and themes: overlay is fixed full-screen, never scrolls the page behind, dark stage regardless of theme.
        $css = $this->css($partial);
        $this->assertMatchesRegularExpression('/\.aitv-overlay\s*\{[^}]*position:\s*fixed;\s*inset:\s*0;[^}]*z-index:\s*10000/', $css);
        $this->assertMatchesRegularExpression('/\.aitv-overlay\s*\{[^}]*overscroll-behavior:\s*contain;\s*touch-action:\s*none/', $css);
        $this->assertMatchesRegularExpression('/\.aitv-backdrop\s*\{[^}]*background:\s*rgba\(8, 10, 14/', $css);
        $this->assertMatchesRegularExpression('/\.aitv-stage\s*\{[^}]*min-height:\s*0;[^}]*overflow:\s*hidden/', $css);
        // It sits above the pop-up (9999).
        $this->assertStringContainsString('z-index: 9999', $css);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function css(string $partial): string
    {
        preg_match('/<style>(.*?)<\/style>/s', $partial, $m);

        return (string) ($m[1] ?? '');
    }

    /**
     * A valid facebook-size design (photo, scrim, title, price 56px, agent name, beds, baths) plus optional blocks.
     *
     * @param  array<int, string> $extras qr | collage | ribbon | show_day | feature_pills | agent_contact | logo
     * @return array<string, mixed>
     */
    private function design(string $name, array $extras = [], bool $withPhoto = true): array
    {
        $els = [];
        if ($withPhoto) {
            $els[] = ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1];
        }
        array_push(
            $els,
            ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
            ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 700, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
            ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
            ['field' => 'agent_name', 'x' => 760, 'y' => 540, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ['field' => 'beds', 'x' => 40, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ['field' => 'baths', 'x' => 260, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
        );
        $blocks = [
            'qr'            => [['field' => 'qr', 'x' => 1040, 'y' => 20, 'w' => 140, 'h' => 140, 'zIndex' => 4, 'qrDark' => '#000000', 'qrLight' => '#ffffff']],
            'show_day'      => [['field' => 'show_day', 'x' => 480, 'y' => 590, 'w' => 260, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff']],
            'feature_pills' => [['field' => 'feature_pills', 'x' => 40, 'y' => 380, 'w' => 500, 'h' => 44, 'zIndex' => 4, 'pillCount' => 3, 'pillDirection' => 'row', 'bgColor' => '#ffffff', 'bgOpacity' => 0.9, 'color' => '#111111', 'fontSize' => 16, 'padding' => 6]],
            'collage'       => [['field' => 'collage', 'x' => 600, 'y' => 20, 'w' => 400, 'h' => 260, 'zIndex' => 4, 'collagePreset' => '3_hero_left']],
            'logo'          => [['field' => 'logo', 'x' => 40, 'y' => 20, 'w' => 200, 'h' => 60, 'zIndex' => 4, 'fontSize' => 22, 'color' => '#ffffff', 'shadowOn' => true]],
            'agent_contact' => [
                ['field' => 'agent_avatar', 'x' => 1060, 'y' => 440, 'w' => 100, 'h' => 100, 'zIndex' => 4, 'shapeType' => 'circle'],
                ['field' => 'agent_phone', 'x' => 760, 'y' => 590, 'w' => 400, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ],
            'ribbon'        => [['field' => 'ribbon', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 200, 'zIndex' => 5, 'text' => 'JUST LISTED', 'fontSize' => 18, 'bgColor' => '#e63946', 'bgOpacity' => 1, 'color' => '#ffffff', 'ribbonCorner' => 'tl']],
        ];
        foreach ($extras as $key) {
            foreach ($blocks[$key] as $el) {
                $els[] = $el;
            }
        }

        return ['name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220', 'elements' => $els];
    }

    /** Every call is answered by $answer(['n' => call number, 'user' => prompt]). */
    private function fakeAi(callable $answer): object
    {
        $state = new class {
            public array $requests = [];
            public function calls(): int { return count($this->requests); }
        };
        Http::fake(function ($request) use ($state, $answer) {
            $body = json_decode($request->body(), true);
            $c    = $body['messages'][0]['content'];
            $user = is_array($c) ? (string) collect($c)->firstWhere('type', 'text')['text'] : (string) $c;
            $state->requests[] = ['user' => $user, 'system' => (string) $body['system']];

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['options' => $answer(['n' => count($state->requests), 'user' => $user])])]],
                'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
            ], 200);
        });

        return $state;
    }

    private function builder(): AiTemplateBuilderService
    {
        return app(AiTemplateBuilderService::class);
    }

    /** @return array{0:int, 1:User} */
    private function agencyWithUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent'])];
    }

    private function renderIndex(User $user): string
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates', 'GET', []);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->index($request)->render();
    }
}
