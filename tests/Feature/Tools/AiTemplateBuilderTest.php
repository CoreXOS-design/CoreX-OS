<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Exceptions\AI\AiTemplateLimitReachedException;
use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\AI\AiUsageEvent;
use App\Models\AiTemplateGeneration;
use App\Models\PropertyAdTemplate;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AI\AiSpendGuard;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 Part B — the AI Template Builder in Template Manager.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.
 *
 * Calls the controller / service directly (like AdTemplateManagerTest) because the
 * `tools.ad-manager*` routes sit behind a `feature:ad-manager` flag a fresh test agency
 * does not have — unrelated to what is asserted here. Every Anthropic call is Http::fake()d.
 */
final class AiTemplateBuilderTest extends TestCase
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

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_one_idea_yields_three_valid_options_and_counts_once(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);

        $this->actingAs($user);
        $gen = $this->builder()->generate($user, 'Bold modern look', 'facebook', 'House');

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(3, $gen->options_json);
        $this->assertSame(['A', 'B', 'C'], array_column($gen->options_json, 'name'));
        $this->assertSame($agency, (int) $gen->agency_id);
        $this->assertSame(1200, $gen->options_json[0]['layout']['canvasW']);
        $this->assertSame('facebook', $gen->options_json[0]['layout']['canvasPreset']);
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
        $this->assertSame(19, $this->builder()->usage($agency)['remaining']);

        // Billed under its own ledger source, to the requesting agency.
        $event = AiUsageEvent::where('agency_id', $agency)->where('source', AiUsageEvent::SOURCE_AD_TEMPLATE)->sole();
        $this->assertStringStartsWith('ad_template:gen:' . $gen->id, (string) $event->surface_ref);
        $this->assertGreaterThan(0, (float) $gen->cost_zar);
        Http::assertSentCount(1);
        $this->assertSame(config('services.anthropic.models.quality'), $fake->firstRequestBody()['model']);
    }

    public function test_the_controller_returns_the_options_and_usage_for_the_panel(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);

        $res  = $this->postGenerate($user, ['idea' => 'Clean and bright', 'ad_size' => 'facebook']);
        $data = $res->getData(true);

        $this->assertSame(200, $res->status());
        $this->assertTrue($data['ok']);
        $this->assertCount(3, $data['options']);
        $this->assertSame(19, $data['usage']['remaining']);
        $this->assertSame(628, $data['options'][0]['layout']['canvasH']);
    }

    // ── Validation, retry, and "failed is not counted" ───────────────────

    public function test_invalid_output_is_retried_once_then_fails_friendly_and_is_not_counted(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $bad = $this->validOption('Bad');
        $bad['elements'][3]['fontSize'] = 6; // below the minimum
        $fake = $this->fakeAi([[$bad, $bad, $bad], [$bad, $bad, $bad]]);
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'Anything', 'facebook', null);
            $this->fail('Expected the generation to fail.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('not counted', $e->getMessage());
        }

        Http::assertSentCount(2); // the original + exactly one automatic retry
        $this->assertSame(0, $this->builder()->usage($agency)['used'], 'A failed generation must not use up the allowance.');
        $row = AiTemplateGeneration::sole();
        $this->assertSame(AiTemplateGeneration::STATUS_FAILED, $row->status);
        // The retry told the model exactly what was wrong.
        $this->assertStringContainsString('below the minimum', json_encode($fake->requestBody(1)));
    }

    public function test_a_bad_first_answer_followed_by_a_good_retry_succeeds_and_counts_once(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $bad = ['name' => 'Nope', 'elements' => [['field' => 'rocket_launcher', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]];
        $this->fakeAi([[$bad, $bad, $bad], [$this->validOption('Good 1'), $this->validOption('Good 2'), $this->validOption('Good 3')]]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null);

        $this->assertCount(3, $gen->options_json);
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
        Http::assertSentCount(2);
    }

    public function test_an_unreachable_ai_service_is_a_friendly_failure_that_is_not_counted(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        Http::fake(['*' => Http::response(['error' => 'down'], 400)]);
        $this->actingAs($user);

        $this->expectException(AiTemplateGenerationFailedException::class);
        try {
            $this->builder()->generate($user, 'x', 'facebook', null);
        } finally {
            $this->assertSame(0, $this->builder()->usage($agency)['used']);
        }
    }

    // ── Layout validation (the gate between the AI and the user) ─────────

    public function test_validator_accepts_a_good_option_and_returns_a_complete_builder_shaped_layout(): void
    {
        $r = (new AdLayoutValidator())->validate($this->validOption('Fine'), 'facebook');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $price = collect($r['layout']['elements'])->firstWhere('field', 'price');
        // Same keys the Ad Builder's own makeElement() creates, so it opens and edits like any template.
        foreach (['id', 'field', 'label', 'x', 'y', 'w', 'h', 'zIndex', 'rotation', 'hidden', 'locked', 'elOpacity', 'fontFamily',
                  'fontSize', 'color', 'verticalAlign', 'bgColor', 'bgOpacity', 'shadowOn', 'removeBackground', 'groupId'] as $key) {
            $this->assertArrayHasKey($key, $price, "missing {$key}");
        }
    }

    #[DataProvider('badLayouts')]
    public function test_validator_rejects_bad_layouts(callable $mutate, string $expectedError): void
    {
        $option = $this->validOption('Bad');
        $option = $mutate($option);

        $r = (new AdLayoutValidator())->validate($option, 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertNull($r['layout']);
        $this->assertStringContainsString($expectedError, implode(' | ', $r['errors']));
    }

    public static function badLayouts(): array
    {
        $price = fn (array $o, string $k, $v) => tap($o, function (&$o) use ($k, $v) {
            $i = array_search('price', array_column($o['elements'], 'field'), true);
            $o['elements'][$i][$k] = $v;
        });

        return [
            'unknown element type'   => [fn ($o) => tap($o, fn (&$o) => $o['elements'][] = ['field' => 'rocket', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]), 'unknown element type'],
            'outside the canvas'     => [fn ($o) => $price($o, 'x', 1150), 'outside the 1200×628 canvas'],
            'negative position'      => [fn ($o) => $price($o, 'y', -40), 'outside the 1200×628 canvas'],
            'font too small'         => [fn ($o) => $price($o, 'fontSize', 9), 'below the minimum'],
            'absurd font size'       => [fn ($o) => $price($o, 'fontSize', 9000), 'outside the allowed range'],
            'opacity out of range'   => [fn ($o) => $price($o, 'elOpacity', 7), 'outside the allowed range'],
            'not a number'           => [fn ($o) => $price($o, 'w', 'wide'), 'is required and must be a number'],
            'css injection colour'   => [fn ($o) => $price($o, 'color', 'red;"><script>alert(1)</script>'), 'not a valid colour'],
            'url colour'             => [fn ($o) => $price($o, 'color', 'url(http://evil.test/x.png)'), 'not a valid colour'],
            'unknown font'           => [fn ($o) => $price($o, 'fontFamily', 'Comic Sans'), 'must be one of'],
            'poor contrast'          => [fn ($o) => $price($o, 'color', '#0d1424'), 'contrast'],
            'no property photo'      => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = array_values(array_filter($o['elements'], fn ($e) => $e['field'] !== 'image_1'))), 'at least one property photo'],
            'too few elements'       => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = array_slice($o['elements'], 0, 2)), 'at least'],
            'custom text empty'      => [fn ($o) => tap($o, fn (&$o) => $o['elements'][] = ['field' => 'custom_text', 'x' => 10, 'y' => 10, 'w' => 200, 'h' => 40, 'fontSize' => 20, 'color' => '#ffffff', 'text' => '']), '"text" is required'],
            'elements not a list'    => [fn ($o) => tap($o, fn (&$o) => $o['elements'] = 'lots'), '"elements" must be a list'],
        ];
    }

    public function test_text_straight_on_a_photo_needs_a_scrim_a_pill_or_a_shadow(): void
    {
        $option = $this->validOption('Photo text');
        // Drop the scrim: the price now sits straight on the photo.
        $option['elements'] = array_values(array_filter($option['elements'], fn ($e) => $e['field'] !== 'gradient'));

        $v = new AdLayoutValidator();
        $r = $v->validate($option, 'facebook');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('straight on a photo', implode(' | ', $r['errors']));

        // A pill behind the text fixes it …
        $pill = $option;
        foreach ($pill['elements'] as &$e) {
            if (in_array($e['field'], ['price', 'title', 'agent_name'], true)) {
                $e['bgColor'] = '#0b1220';
                $e['bgOpacity'] = 0.8;
            }
        }
        unset($e);
        $this->assertTrue($v->validate($pill, 'facebook')['ok']);

        // … and so does a text shadow.
        $shadow = $option;
        foreach ($shadow['elements'] as &$e) {
            if (in_array($e['field'], ['price', 'title', 'agent_name'], true)) {
                $e['shadowOn'] = true;
            }
        }
        unset($e);
        $this->assertTrue($v->validate($shadow, 'facebook')['ok']);
    }

    public function test_literal_text_is_stripped_of_markup_and_length_limited(): void
    {
        $option = $this->validOption('Text');
        $option['elements'][] = ['field' => 'badge', 'x' => 20, 'y' => 20, 'w' => 220, 'h' => 50, 'fontSize' => 20, 'color' => '#ffffff', 'bgColor' => '#b91c1c', 'bgOpacity' => 1, 'text' => '<b>JUST</b> LISTED'];

        $r = (new AdLayoutValidator())->validate($option, 'facebook');
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $badge = collect($r['layout']['elements'])->firstWhere('field', 'badge');
        $this->assertSame('JUST LISTED', $badge['text']);

        $option['elements'][count($option['elements']) - 1]['text'] = str_repeat('x', 200);
        $this->assertFalse((new AdLayoutValidator())->validate($option, 'facebook')['ok']);
    }

    public function test_sizes_and_fonts_match_the_render_kernel(): void
    {
        $js = (string) file_get_contents(base_path('public/js/corex-ad-render.js'));

        foreach (AdLayoutValidator::SIZES as $key => $size) {
            $this->assertMatchesRegularExpression(
                '/' . $key . ':\s*\{\s*w:\s*' . $size['w'] . ',\s*h:\s*' . $size['h'] . '\b/',
                $js,
                "Ad size '{$key}' no longer matches CANVAS_PRESETS in corex-ad-render.js"
            );
        }

        preg_match_all("/\\{\\s*name:\\s*'([^']+)',\\s*stack:/", $js, $m);
        $ref  = new \ReflectionClassConstant(AdLayoutValidator::class, 'FONTS');
        $this->assertSame($m[1], $ref->getValue(), 'AdLayoutValidator::FONTS drifted from FONTS in the render kernel');
    }

    // ── Monthly allowance ────────────────────────────────────────────────

    public function test_the_21st_generation_in_a_month_is_refused_with_the_reset_date_and_no_ai_call(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedSuccessful($agency, $user, 20);
        Http::fake();
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'One more please', 'facebook', null);
            $this->fail('Expected the limit to refuse.');
        } catch (AiTemplateLimitReachedException $e) {
            $this->assertSame(20, $e->limit);
            $this->assertStringContainsString(now()->startOfMonth()->addMonth()->format('j F Y'), $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->builder()->usage($agency)['remaining']);
    }

    public function test_last_months_generations_do_not_count_and_failed_or_stale_ones_never_do(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedSuccessful($agency, $user, 20, now()->subMonth()->startOfMonth()->addDays(3));
        $this->seedRow($agency, $user, ['status' => 'failed']);
        $this->seedRow($agency, $user, ['status' => 'pending', 'created_at' => now()->subHour()]); // a crashed request
        $this->assertSame(0, $this->builder()->usage($agency)['used']);

        $this->seedRow($agency, $user, ['status' => 'pending']); // genuinely in flight
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
    }

    public function test_raising_the_agency_limit_takes_effect_immediately(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedSuccessful($agency, $user, 20);
        $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);

        DB::table('agencies')->where('id', $agency)->update(['ai_template_monthly_limit' => 25]);

        $gen = $this->builder()->generate($user, 'Now allowed', 'facebook', null);
        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(21, $this->builder()->usage($agency)['used']);
        $this->assertSame(4, $this->builder()->usage($agency)['remaining']);
    }

    public function test_a_limit_of_zero_switches_the_builder_off_for_that_agency(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agency)->update(['ai_template_monthly_limit' => 0]);
        Http::fake();
        $this->actingAs($user);

        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook']);

        $this->assertSame(429, $res->status());
        $this->assertStringContainsString('switched off', $res->getData(true)['message']);
        Http::assertNothingSent();
    }

    // ── AI Spend Safety ──────────────────────────────────────────────────

    public function test_a_paused_agency_is_refused_without_any_http_call_or_row(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agency)->update(['ai_paused_at' => now(), 'ai_paused_reason' => 'test']);
        Http::fake();
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'x', 'facebook', null);
            $this->fail('Expected AiPausedException.');
        } catch (AiPausedException $e) {
            $this->assertSame(AiPausedException::MESSAGE, $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::withoutGlobalScopes()->count());

        // …and the panel shows the plain paused sentence.
        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook']);
        $this->assertSame(503, $res->status());
        $this->assertSame(AiPausedException::MESSAGE, $res->getData(true)['message']);
    }

    // ── Saving ───────────────────────────────────────────────────────────

    public function test_saving_an_option_makes_a_normal_ai_template_in_the_users_agency_and_is_idempotent(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);
        $gen = $this->builder()->generate($user, 'Sunny coastal feel', 'facebook', null);

        $res = $this->postSave($user, $gen, ['option' => 1, 'name' => 'My coastal look']);
        $data = $res->getData(true);

        $this->assertTrue($data['ok']);
        $tpl = PropertyAdTemplate::findOrFail($data['id']);
        $this->assertSame('My coastal look', $tpl->name);
        $this->assertTrue($tpl->ai_generated);
        $this->assertSame('Sunny coastal feel', $tpl->ai_idea);
        $this->assertSame($agency, (int) $tpl->agency_id);
        $this->assertSame($user->id, (int) $tpl->user_id);
        $this->assertSame($gen->options_json[1]['layout'], $tpl->layout_json);
        $this->assertNotEmpty($tpl->layout_json['elements']);
        $this->assertStringContainsString('/ad-templates/builder/' . $tpl->id, $data['edit_url']);
        $this->assertTrue($tpl->canBeManagedBy($user)); // archivable / restorable like any other template

        // Saving the same option again does not duplicate it.
        $again = $this->postSave($user, $gen->refresh(), ['option' => 1])->getData(true);
        $this->assertSame($tpl->id, $again['id']);
        $this->assertSame(1, PropertyAdTemplate::count());
    }

    public function test_save_reads_the_stored_layout_and_rejects_a_bad_option_number(): void
    {
        [, $user] = $this->agencyWithUser();
        // First answer has one good option; the single retry (asked only for the 2 missing) brings none.
        $this->fakeAi([[$this->validOption('A')], []]);
        $this->actingAs($user);
        $gen = $this->builder()->generate($user, 'x', 'facebook', null);

        $this->assertCount(1, $gen->options_json); // partial success: one valid option is enough
        Http::assertSentCount(2);
        $this->assertSame(422, $this->postSave($user, $gen, ['option' => 2])->status());
        $this->assertSame(0, PropertyAdTemplate::count());
    }

    // ── Multi-agency ─────────────────────────────────────────────────────

    public function test_another_agency_cannot_see_or_save_a_generation_and_a_colleague_gets_403(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        $colleague = $this->userIn($a);
        $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($ua);
        $gen = $this->builder()->generate($ua, 'Private idea', 'facebook', null);

        // Agency B: the route-model lookup AgencyScope performs finds nothing → 404.
        $this->actingAs($ub);
        $this->assertNull(AiTemplateGeneration::find($gen->id));
        // Same agency, different user → 403 (it is the creator's generation).
        $this->actingAs($colleague);
        $found = AiTemplateGeneration::findOrFail($gen->id);
        try {
            $this->postSave($colleague, $found, ['option' => 0]);
            $this->fail('Expected 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, PropertyAdTemplate::withoutGlobalScopes()->count());
    }

    public function test_brand_colours_come_from_the_requesting_agency_never_another(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $a)->update(['default_color' => '#112233', 'button_color' => '#aa0011', 'logo_path' => 'logos/a.png']);
        DB::table('agencies')->where('id', $b)->update(['default_color' => '#33aa55', 'button_color' => '#ffcc00', 'logo_path' => null]);
        $fake = $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);

        $this->actingAs($ub);
        $this->builder()->generate($ub, 'Brand test', 'facebook', null);

        $system = $fake->firstRequestBody()['system'];
        $this->assertStringContainsString('#33aa55', $system);
        $this->assertStringContainsString('#ffcc00', $system);
        $this->assertStringNotContainsString('#112233', $system);
        $this->assertStringNotContainsString('#aa0011', $system);
        $this->assertStringContainsString('no logo', $system); // B has none uploaded
        $this->assertStringNotContainsString('HFC', $system);
        $this->assertStringNotContainsString('Home Finders', $system);
    }

    public function test_an_agency_with_no_brand_colours_is_told_to_pick_a_neutral_palette(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agency)->update(['sidebar_color' => '', 'icon_color' => '', 'default_color' => '', 'button_color' => '']);
        $fake = $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);

        $this->builder()->generate($user, 'x', 'facebook', null);

        $this->assertStringContainsString('No brand colours are on file', $fake->firstRequestBody()['system']);
    }

    // ── Reference image ──────────────────────────────────────────────────

    public function test_reference_image_is_stored_privately_sent_to_the_model_and_purged_after_seven_days(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi([[$this->validOption('A'), $this->validOption('B'), $this->validOption('C')]]);
        $this->actingAs($user);

        $file = UploadedFile::fake()->image('inspo.png', 400, 300);
        $gen  = $this->builder()->generate($user, 'Like this picture', 'facebook', null, $file);

        // Private disk only — never the public disk.
        $this->assertNotNull($gen->reference_image_path);
        Storage::disk('local')->assertExists($gen->reference_image_path);
        $this->assertStringStartsWith('ai-template-refs/' . $agency . '/', $gen->reference_image_path);
        $this->assertFalse(Storage::disk('public')->exists($gen->reference_image_path));

        // Sent to the model as an image block alongside the text.
        $content = $fake->firstRequestBody()['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('image/png', $content[0]['source']['media_type']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertStringContainsString('reference image is attached', $content[1]['text']);

        // 6 days old: kept. 8 days old: purged by the (hand-runnable) command, row kept.
        AiTemplateGeneration::withoutGlobalScopes()->whereKey($gen->id)->update(['created_at' => now()->subDays(6)]);
        $this->artisan('ai-templates:purge-reference-images')->assertSuccessful();
        Storage::disk('local')->assertExists($gen->reference_image_path);

        $path = $gen->reference_image_path;
        AiTemplateGeneration::withoutGlobalScopes()->whereKey($gen->id)->update(['created_at' => now()->subDays(8)]);
        $this->artisan('ai-templates:purge-reference-images')->expectsOutputToContain('1 reference image(s) deleted')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $row = AiTemplateGeneration::withoutGlobalScopes()->findOrFail($gen->id);
        $this->assertNull($row->reference_image_path);
        $this->assertNotNull($row->reference_image_purged_at);
    }

    public function test_reference_image_rules_are_enforced_at_the_controller(): void
    {
        [, $user] = $this->agencyWithUser();
        Http::fake();
        $this->actingAs($user);

        foreach ([
            UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'),   // > 5 MB
            UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'), // not an image
            UploadedFile::fake()->createWithContent('fake.jpg', 'not really an image'),
        ] as $bad) {
            try {
                $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook'], $bad);
                $this->assertSame(422, $res->status());
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::withoutGlobalScopes()->count());
    }

    public function test_the_purge_command_is_scheduled_daily(): void
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertMatchesRegularExpression('/15\s+3\s+\*\s+\*\s+\*\s+php artisan ai-templates:purge-reference-images/', $out);
    }

    // ── Template Manager screen ──────────────────────────────────────────

    public function test_template_manager_shows_the_ai_badge_the_ai_made_filter_and_the_panel(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->makeTemplate($agency, $user, 'Hand made', false);
        $this->makeTemplate($agency, $user, 'Robot made', true);
        $colleague = $this->userIn($agency);
        $this->makeTemplate($agency, $colleague, 'Colleague hand', false);

        $this->actingAs($user);
        $names = fn (array $q) => $this->indexData($user, $q)['templates']->getCollection()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Colleague hand', 'Hand made', 'Robot made'], $names([]));
        $this->assertSame(['Robot made'], $names(['creator' => 'ai']));
        $this->assertSame(['Hand made', 'Robot made'], $names(['creator' => 'mine']));
        $this->assertSame(['Colleague hand', 'Hand made', 'Robot made'], $names(['creator' => 'bogus']));

        $html = $this->renderIndex($user, ['creator' => 'ai']);
        $this->assertStringContainsString('Robot made', $html);
        $this->assertStringContainsString('AI-made', $html);
        $this->assertStringContainsString('Create with AI', $html);
        // The panel is handed this month's allowance (Blade @js hex-escapes the quotes).
        $this->assertStringContainsString('\u0022remaining\u0022:20', $html);
    }

    public function test_the_create_with_ai_button_is_hidden_from_a_user_without_template_create_rights(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        // The suite treats an empty role_permissions table as "everyone may do everything". Seed one
        // grant (Ad Manager, but NOT access_properties) and switch to production posture.
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency]);
        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_ad_manager', 'agency_id' => $agency], []);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        $this->actingAs($user);

        try {
            $this->assertNull($this->indexData($user, [])['aiPanel']);
            // …and a forged POST is refused server-side as well.
            Http::fake();
            try {
                $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook']);
                $this->fail('Expected 403.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            Http::assertNothingSent();
        } finally {
            PermissionService::clearCache();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function builder(): AiTemplateBuilderService
    {
        return app(AiTemplateBuilderService::class);
    }

    /** @return array<string, mixed> */
    private function validOption(string $name): array
    {
        return [
            'name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
                ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
                ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 700, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
                ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
                ['field' => 'agent_name', 'x' => 760, 'y' => 540, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ],
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
            public function requestBody(int $n): array
            {
                return json_decode(Http::recorded()[$n][0]->body(), true);
            }

            public function firstRequestBody(): array
            {
                return $this->requestBody(0);
            }
        };
    }

    /** @return array{0:int, 1:User} */
    private function agencyWithUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $this->userIn($agencyId)];
    }

    private function userIn(int $agencyId): User
    {
        $branchId = DB::table('branches')->where('agency_id', $agencyId)->value('id')
            ?? (int) DB::table('branches')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent']);
    }

    private function seedRow(int $agencyId, User $user, array $attrs = []): void
    {
        DB::table('ai_template_generations')->insert(array_merge([
            'agency_id' => $agencyId, 'user_id' => $user->id, 'idea' => 'seed', 'ad_size' => 'facebook',
            'status' => 'success', 'cost_zar' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function seedSuccessful(int $agencyId, User $user, int $n, $at = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->seedRow($agencyId, $user, ['created_at' => $at ?? now()]);
        }
    }

    private function makeTemplate(int $agencyId, User $user, string $name, bool $ai): int
    {
        return (int) DB::table('property_ad_templates')->insertGetId([
            'agency_id' => $agencyId, 'user_id' => $user->id, 'name' => $name,
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'elements' => []]),
            'is_global' => 0, 'ai_generated' => $ai, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postGenerate(User $user, array $fields, ?UploadedFile $file = null)
    {
        $request = Request::create('/api/v1/ad-manager/templates/ai/generate', 'POST', $fields, [], $file ? ['reference_image' => $file] : []);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->generateWithAi($request, $this->builder());
    }

    private function postSave(User $user, AiTemplateGeneration $gen, array $fields)
    {
        $request = Request::create('/x', 'POST', $fields);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->saveAiOption($request, $gen, $this->builder());
    }

    private function indexData(User $user, array $query): array
    {
        $request = Request::create('/tools/ad-manager/templates', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->index($request)->getData();
    }

    private function renderIndex(User $user, array $query): string
    {
        $request = Request::create('/tools/ad-manager/templates', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->index($request)->render();
    }
}
