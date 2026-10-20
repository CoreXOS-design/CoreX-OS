<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Exceptions\AI\AiTemplateLimitReachedException;
use App\Http\Controllers\Tools\CompanyAdsController;
use App\Models\AiTemplateGeneration;
use App\Models\CompanyAd;
use App\Models\CompanyAdImage;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AdTemplates\CompanyAdBuilderService;
use App\Services\AdTemplates\CompanyAdLibrary;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Company Ads, step E-2 (.ai/specs/company-ads.md §8, §10): "Create company ad with AI".
 *
 * Every Anthropic call is Http::fake()d with recorded answers. Calls the controller / services directly, like the other
 * Template Manager tests (the routes sit behind a `feature:ad-manager` flag a fresh test agency does not have).
 */
final class CompanyAdAiBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    // ── The happy path ─────────────────────────────────────────────────────

    public function test_a_christmas_idea_with_a_library_picture_gives_three_designs_in_the_agencys_brand_and_counts_once(): void
    {
        [$a, $user] = $this->agencyWithUser(['default_color' => '#123456', 'button_color' => '#abcdef']);
        [$b] = $this->agencyWithUser(['default_color' => '#fe0000']);
        $pic  = $this->libraryPicture('christmas');
        $fake = $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Merry Christmas, closed 24 Dec - 2 Jan', 'facebook', 'christmas', 'library', null, $pic);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(AiTemplateGeneration::KIND_COMPANY_AD, $gen->kind);
        $this->assertSame('christmas', $gen->occasion);
        $this->assertSame('library', $gen->image_mode);
        $this->assertCount(3, $gen->options_json);
        foreach ($gen->options_json as $opt) {
            $pictures = collect($opt['layout']['elements'])->where('field', 'custom_image');
            $this->assertCount(1, $pictures);
            // The server put the library picture in - the address the model wrote is gone.
            $this->assertSame($pic, $pictures->first()['src']);
            $this->assertNotSame('', $opt['caption']);
        }
        $this->assertSame(1, app(AiTemplateBuilderService::class)->usage($a)['used']);
        Http::assertSentCount(1);

        // The model saw the picture, the occasion, and THIS agency's colours only.
        $body = $fake->requestBody(0);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
        $text = $this->userText($body);
        $this->assertStringContainsString('THE PICTURE is attached', $text);
        $this->assertStringContainsString('OCCASION: Christmas', $text);
        $this->assertStringContainsString('#123456', $body['system']);
        $this->assertStringNotContainsString('#fe0000', $body['system']);
        $this->assertStringContainsString('COMPANY ad', $body['system']);
        $this->assertStringNotContainsString('Home Finders', $body['system']);
        // The prompt never offers a listing element.
        foreach (['- price:', '- image_1:', '- qr:', '- collage:', '- address:'] as $listing) {
            $this->assertStringNotContainsString($listing, $body['system']);
        }
    }

    public function test_saving_a_design_makes_an_ai_company_ad_with_its_caption_and_is_idempotent(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $pic = $this->libraryPicture('easter');
        $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);
        $gen = $this->service()->generate($user, 'Happy Easter', 'facebook', 'easter', 'library', null, $pic);

        $res = $this->postSave($user, $gen, ['option' => 1, 'name' => 'Easter 2026', 'caption' => '<b>Happy</b> Easter from our team!']);
        $data = $res->getData(true);
        $this->assertTrue($data['ok']);

        $ad = CompanyAd::findOrFail($data['id']);
        $this->assertSame($a, (int) $ad->agency_id);
        $this->assertTrue($ad->ai_generated);
        $this->assertSame('Easter 2026', $ad->name);
        $this->assertSame('easter', $ad->occasion);
        $this->assertSame('Happy Easter', $ad->ai_idea);
        $this->assertSame('Happy Easter from our team!', $ad->caption, 'markup is stripped from the caption');
        $this->assertSame('facebook', $ad->canvas);
        $this->assertSame($pic, collect($ad->layout_json['elements'])->firstWhere('field', 'custom_image')['src']);

        // Saving the same design again returns the same ad.
        $again = $this->postSave($user, $gen->refresh(), ['option' => 1, 'name' => 'Other name'])->getData(true);
        $this->assertSame($data['id'], $again['id']);
        $this->assertSame(1, CompanyAd::count());
    }

    // ── The picture rules ──────────────────────────────────────────────────

    public function test_put_this_picture_in_my_ad_uses_the_agencys_own_upload_and_never_another_agencys(): void
    {
        [$a, $user] = $this->agencyWithUser();
        [$b, $other] = $this->agencyWithUser();
        $mine   = $this->upload($a, $user);
        $theirs = $this->upload($b, $other);
        $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Welcome our new agent', 'instagram', 'welcome', 'place', $mine->id);
        foreach ($gen->options_json as $opt) {
            $this->assertSame($mine->url(), collect($opt['layout']['elements'])->firstWhere('field', 'custom_image')['src']);
        }

        // Another agency's picture is refused before anything is reserved or called.
        Http::fake();
        $before = AiTemplateGeneration::withoutGlobalScopes()->count();
        try {
            $this->service()->generate($user, 'Welcome', 'instagram', null, 'place', $theirs->id);
            $this->fail('Another agency\'s picture was accepted.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }
        $this->assertSame($before, AiTemplateGeneration::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_copy_the_look_never_shows_the_uploaded_picture_and_is_stored_privately(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);

        $file = UploadedFile::fake()->image('their-post.png', 600, 600);
        $gen  = $this->service()->generate($user, 'Happy New Year like this post', 'instagram', 'new_year', 'look', null, null, $file);

        // Not one design holds a picture box: the inspiration can never appear on the ad.
        foreach ($gen->options_json as $opt) {
            $this->assertCount(0, collect($opt['layout']['elements'])->where('field', 'custom_image'));
        }
        $this->assertStringContainsString('copy the look', implode(' ', collect($gen->validation_log_json)->pluck('repairs')->flatten()->all()));
        // Private disk, the same 7-day purge as a property reference image; never the public disk; no company_ad_images row.
        Storage::disk('local')->assertExists($gen->reference_image_path);
        $this->assertStringStartsWith(AiTemplateBuilderService::REFERENCE_DIR . '/' . $a . '/', $gen->reference_image_path);
        $this->assertFalse(Storage::disk('public')->exists($gen->reference_image_path));
        $this->assertSame(0, CompanyAdImage::count());
        // Sent as inspiration only, with the "never copy their words / logo" rule.
        $text = $this->userText($fake->requestBody(0));
        $this->assertStringContainsString('INSPIRATION ONLY', $text);
        $this->assertStringContainsString('Never reproduce any of its words', $text);
    }

    public function test_no_picture_removes_any_picture_box_the_model_adds(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Thank you to all our clients', 'facebook', 'celebration', 'none');

        foreach ($gen->options_json as $opt) {
            $this->assertCount(0, collect($opt['layout']['elements'])->where('field', 'custom_image'));
        }
        $this->assertStringContainsString('There is NO picture', $this->userText($fake->requestBody(0)));
        $this->assertStringNotContainsString('- custom_image:', $fake->requestBody(0)['system']);
        // Audit 2026-10-20: the overlap rule's "may sit on" list does not name the picture box the prompt forbids.
        $system = $fake->requestBody(0)['system'];
        $rule   = substr($system, strpos($system, '1c. No overlaps'), 300);
        $this->assertStringNotContainsString('custom_image', $rule);
    }

    public function test_a_design_that_ignores_the_chosen_picture_is_retried_then_dropped(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $pic = $this->libraryPicture('christmas');
        $noPicture = $this->option('No picture');
        $noPicture['elements'] = array_values(array_filter($noPicture['elements'], fn ($e) => $e['field'] !== 'custom_image'));
        $fake = $this->fakeAi([[$this->option('A'), $noPicture, $this->option('C')], [$noPicture]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Merry Christmas', 'facebook', 'christmas', 'library', null, $pic);

        $this->assertCount(2, $gen->options_json, 'a design without the chosen picture is never shown');
        Http::assertSentCount(2);
        $this->assertStringContainsString('the picture', $this->userText($fake->requestBody(1)));
    }

    // ── The company-mode validator ─────────────────────────────────────────

    public function test_company_mode_removes_listing_elements_adds_the_agency_and_allows_one_picture(): void
    {
        $v = app(AdLayoutValidator::class);
        $option = [
            'name' => 'Mixed', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'custom_image', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 628, 'src' => 'https://elsewhere.example/a.jpg'],
                ['field' => 'custom_image', 'x' => 600, 'y' => 0, 'w' => 600, 'h' => 300],
                ['field' => 'price', 'x' => 700, 'y' => 400, 'w' => 400, 'h' => 80, 'fontSize' => 40, 'color' => '#ffffff'],
                ['field' => 'image_1', 'x' => 700, 'y' => 300, 'w' => 300, 'h' => 100],
                ['field' => 'qr', 'x' => 1000, 'y' => 300, 'w' => 150, 'h' => 150],
                ['field' => 'custom_text', 'x' => 640, 'y' => 320, 'w' => 520, 'h' => 100, 'fontSize' => 44, 'color' => '#ffffff', 'text' => 'Season\'s greetings'],
            ],
        ];

        $res = $v->validateAndRepair($option, 'facebook', AdLayoutValidator::MODE_COMPANY);

        $this->assertTrue($res['ok'], implode(' | ', $res['errors']));
        $fields = collect($res['layout']['elements'])->pluck('field')->all();
        $this->assertSame(['custom_image', 'custom_text', 'agency_name'], $fields);
        $this->assertSame('', $res['layout']['elements'][0]['src'], 'the validator never keeps a picture address from the model');
        $this->assertStringContainsString('listing element has no place on a company ad', implode(' ', $res['repairs']));
        $this->assertStringContainsString('agency was not on the ad', implode(' ', $res['repairs']));

        // Strict mode reports what repair fixes.
        $strict = $v->validate(['name' => 'x', 'canvasBg' => '#0b1220', 'elements' => [
            ['field' => 'custom_text', 'x' => 40, 'y' => 40, 'w' => 600, 'h' => 100, 'fontSize' => 40, 'color' => '#ffffff', 'text' => 'Hi'],
            ['field' => 'line', 'x' => 40, 'y' => 200, 'w' => 600, 'h' => 4],
            ['field' => 'shape', 'x' => 40, 'y' => 300, 'w' => 100, 'h' => 100],
        ]], 'facebook', AdLayoutValidator::MODE_COMPANY);
        $this->assertFalse($strict['ok']);
        $this->assertStringContainsString('must show the agency', implode(' ', $strict['errors']));

        // Property mode is unchanged: no picture box, and a property photo is still required.
        $prop = $v->validateAndRepair($option, 'facebook');
        $this->assertNotContains('custom_image', collect($prop['layout']['elements'] ?? [])->pluck('field')->all());
        $noPhoto = $v->validate(['name' => 'x', 'canvasBg' => '#0b1220', 'elements' => [
            ['field' => 'agency_name', 'x' => 40, 'y' => 40, 'w' => 600, 'h' => 100, 'fontSize' => 40, 'color' => '#ffffff'],
            ['field' => 'line', 'x' => 40, 'y' => 200, 'w' => 600, 'h' => 4],
            ['field' => 'shape', 'x' => 40, 'y' => 300, 'w' => 100, 'h' => 100],
        ]], 'facebook');
        $this->assertStringContainsString('at least one property photo', implode(' ', $noPhoto['errors']));
    }

    public function test_the_ai_never_types_the_agency_name_and_agent_elements_need_the_me_tick(): void
    {
        [$a, $user] = $this->agencyWithUser([], 'Coastline Rentals');
        $opt = $this->option('A');
        $opt['elements'][] = ['field' => 'badge', 'x' => 760, 'y' => 30, 'w' => 380, 'h' => 50, 'zIndex' => 4, 'fontSize' => 18, 'text' => 'From COASTLINE RENTALS'];
        $opt['elements'][] = ['field' => 'agent_name', 'x' => 760, 'y' => 440, 'w' => 380, 'h' => 40, 'zIndex' => 4, 'fontSize' => 20, 'color' => '#ffffff'];
        $opt['elements'][] = ['field' => 'watermark', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 2, 'opacity' => 0.05, 'text' => 'Some Other Agency'];
        $this->fakeAi([[$opt, $opt, $opt]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Happy holidays', 'facebook', null, 'none');

        foreach ($gen->options_json as $o) {
            $fields = collect($o['layout']['elements']);
            $this->assertNull($fields->firstWhere('field', 'badge'), 'typed agency name removed');
            $this->assertNull($fields->firstWhere('field', 'agent_name'), '"Me" was not ticked');
            $this->assertSame('', $fields->firstWhere('field', 'watermark')['text'], 'the watermark draws the agency\'s own name');
        }
    }

    public function test_ticked_items_are_on_every_design_shown(): void
    {
        [, $user] = $this->agencyWithUser(['website_url' => 'https://example.test']);
        $full = $this->option('Full');
        array_push($full['elements'],
            ['field' => 'website', 'x' => 760, 'y' => 420, 'w' => 380, 'h' => 30, 'zIndex' => 4, 'fontSize' => 18, 'color' => '#ffffff'],
            ['field' => 'agent_name', 'x' => 760, 'y' => 460, 'w' => 380, 'h' => 30, 'zIndex' => 4, 'fontSize' => 18, 'color' => '#ffffff'],
            ['field' => 'agent_phone', 'x' => 760, 'y' => 590, 'w' => 380, 'h' => 30, 'zIndex' => 4, 'fontSize' => 18, 'color' => '#ffffff'],
            ['field' => 'agent_avatar', 'x' => 1080, 'y' => 30, 'w' => 90, 'h' => 90, 'zIndex' => 4],
        );
        $partial = $this->option('Partial'); // no website / agent
        $fake = $this->fakeAi([[$full, $partial, $full], [$partial]]);
        $this->actingAs($user);

        $gen = $this->service()->generate($user, 'Welcome Thandi to the team', 'facebook', 'welcome', 'none', mustInclude: ['contact', 'me']);

        $this->assertCount(2, $gen->options_json);
        foreach ($gen->options_json as $o) {
            $fields = collect($o['layout']['elements'])->pluck('field');
            foreach (['website', 'agent_name', 'agent_phone', 'agent_avatar'] as $f) {
                $this->assertContains($f, $fields);
            }
        }
        $this->assertStringContainsString('EVERY DESIGN MUST INCLUDE', $this->userText($fake->requestBody(0)));
    }

    // ── Allowance and failures ─────────────────────────────────────────────

    public function test_company_ads_share_the_monthly_allowance_and_the_21st_request_is_refused_without_an_ai_call(): void
    {
        [$a, $user] = $this->agencyWithUser();
        for ($i = 0; $i < 19; $i++) {
            $this->seedRow($a, $user);
        }
        $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);
        $this->service()->generate($user, 'Happy Heritage Day', 'facebook', 'heritage_day', 'none');

        $builder = app(AiTemplateBuilderService::class);
        $this->assertSame(['property_template' => 19, 'company_ad' => 1], $builder->usedThisMonthByKind($a));
        $this->assertSame(0, $builder->usage($a)['remaining']);

        Http::fake();
        try {
            $this->service()->generate($user, 'One more', 'facebook', null, 'none');
            $this->fail('The 21st request was allowed.');
        } catch (AiTemplateLimitReachedException $e) {
            $this->assertStringContainsString(now()->startOfMonth()->addMonth()->format('j F Y'), $e->getMessage());
        }
        Http::assertNothingSent();

        // The property-template creator is refused too: one pool.
        $this->expectException(AiTemplateLimitReachedException::class);
        $builder->generate($user, 'A property template', 'facebook', null);
    }

    public function test_no_usable_design_is_a_friendly_failure_that_is_not_counted(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $bad = ['name' => 'Bad', 'canvasBg' => 'url(javascript:alert(1))', 'elements' => [['field' => 'custom_text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'text' => 'x']]];
        $this->fakeAi([[$bad, $bad, $bad]]);
        $this->actingAs($user);

        $res  = $this->postGenerate($user, ['idea' => 'Happy Youth Day', 'ad_size' => 'facebook', 'picture' => 'none']);
        $data = $res->getData(true);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('failed', $data['kind']);
        $this->assertStringContainsString('not counted', $data['message']);
        $this->assertSame(0, $data['usage']['used']);
        Http::assertSentCount(2); // one call + one targeted retry
    }

    public function test_the_controller_returns_designs_with_captions_and_the_usage_line(): void
    {
        [, $user] = $this->agencyWithUser();
        $long = $this->option('A');
        $long['caption'] = "<script>x</script>Merry\x07 Christmas " . str_repeat('and a happy new year ', 30);
        $this->fakeAi([[$long, $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);

        $data = $this->postGenerate($user, ['idea' => 'Merry Christmas', 'ad_size' => 'facebook', 'picture' => 'library',
            'library_picture' => $this->libraryPicture('christmas'), 'occasion' => 'christmas'])->getData(true);

        $this->assertTrue($data['ok']);
        $this->assertCount(3, $data['options']);
        $caption = $data['options'][0]['caption'];
        $this->assertLessThanOrEqual(CompanyAdBuilderService::CAPTION_MAX, mb_strlen($caption));
        $this->assertStringNotContainsString('<', $caption);
        $this->assertStringNotContainsString("\x07", $caption);
        $this->assertStringStartsWith('xMerry Christmas', $caption);
        $this->assertSame(1, $data['usage']['used']);

        // Unknown occasion / missing library picture are refused by the request rules.
        foreach ([['occasion' => 'not_a_day', 'picture' => 'none'], ['picture' => 'library']] as $bad) {
            try {
                $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook'] + $bad);
                $this->fail('Accepted ' . json_encode($bad));
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── Who may do what ────────────────────────────────────────────────────

    public function test_the_two_kinds_never_cross_and_others_cannot_save(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $colleague = $this->userIn($a);
        $this->fakeAi([[$this->option('A'), $this->option('B'), $this->option('C')]]);
        $this->actingAs($user);
        $gen = $this->service()->generate($user, 'Happy Diwali', 'facebook', 'diwali', 'none');

        // A company-ad design can never be saved as a property template.
        try {
            app(AiTemplateBuilderService::class)->saveOption($gen, 0, $user);
            $this->fail('A company-ad request was saved as a property template.');
        } catch (AiTemplateGenerationFailedException) {
            $this->assertSame(0, DB::table('property_ad_templates')->count());
        }
        // … and a property-template request can never be saved as a company ad.
        $propertyGen = AiTemplateGeneration::create(['agency_id' => $a, 'user_id' => $user->id, 'idea' => 'p', 'ad_size' => 'facebook',
            'status' => 'success', 'options_json' => $gen->options_json]);
        $this->assertSame(AiTemplateGeneration::KIND_PROPERTY_TEMPLATE, $propertyGen->refresh()->kind);
        try {
            $this->postSave($user, $propertyGen, ['option' => 0]);
            $this->fail('A property request was saved as a company ad.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        // A colleague cannot save someone else's designs; another agency cannot even find them.
        try {
            $this->postSave($colleague, $gen, ['option' => 0]);
            $this->fail('A colleague saved another user\'s design.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        [, $outsider] = $this->agencyWithUser();
        $this->actingAs($outsider);
        $this->assertNull(AiTemplateGeneration::find($gen->id));
        $this->assertSame(0, CompanyAd::withoutGlobalScopes()->count());
    }

    public function test_without_the_permission_there_is_no_button_and_a_forged_request_is_403(): void
    {
        [$a, $user] = $this->agencyWithUser();
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $a]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_ad_manager', 'agency_id' => $a], []);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        Http::fake();
        $this->actingAs($user);

        $html = $this->renderIndex($user);
        $this->assertStringNotContainsString('Create with AI', $html);
        $this->assertStringNotContainsString('companyAdAiPanel(', $html);

        foreach ([
            fn () => $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'picture' => 'none']),
            fn () => $this->postSave($user, AiTemplateGeneration::create(['agency_id' => $a, 'user_id' => $user->id, 'kind' => 'company_ad', 'idea' => 'x', 'ad_size' => 'facebook', 'status' => 'success']), ['option' => 0]),
        ] as $call) {
            try {
                $call();
                $this->fail('Allowed without ' . CompanyAd::PERMISSION);
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    // ── The pop-up ─────────────────────────────────────────────────────────

    public function test_the_list_offers_the_pop_up_with_the_shared_shell_once(): void
    {
        [, $user] = $this->agencyWithUser();
        $html = $this->renderIndex($user);

        $this->assertStringContainsString('Create with AI', $html);
        $this->assertStringContainsString("open-company-ad-ai", $html);
        $this->assertStringContainsString('companyAdAiPanel(', $html);
        $this->assertSame(1, substr_count($html, 'function corexAiDialogShell()'), 'the shared shell is included once');
        foreach (['data-aitp="overlay"', 'data-aitp="dialog"', 'data-aitp="footer"', 'aria-labelledby="cadai-title"', 'form="cadai-form"',
            'data-aitv="overlay"', 'data-aitv="prev"', 'data-cadai="library"', 'data-cadai="look-note"', 'data-cadai="caption"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('data-aitv="version"', $html, 'no property-type versions on a company ad');
        $this->assertStringContainsString('Put this picture in my ad', $html);
        $this->assertStringContainsString('Copy the look of this post', $html);
        $this->assertStringContainsString("We'll copy the layout and style only — never another company's logo, words or photos.", $html);
        $this->assertStringContainsString('shared with property templates', $html);
        // Download from the pop-up goes through the list's own capture path.
        $this->assertStringContainsString('x-on:company-ad-download.window="downloadLayout($event.detail)"', $html);
        $this->assertMatchesRegularExpression('#company-ads\\\\*/ai\\\\*/generate#', $html); // @js() escapes the slashes
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function service(): CompanyAdBuilderService
    {
        return app(CompanyAdBuilderService::class);
    }

    /** A valid company design: a picture box on the left (with an address the server must throw away), words, the logo. */
    private function option(string $name): array
    {
        return [
            'name' => $name, 'style' => 'luxury', 'caption' => 'Wishing you a joyful festive season from all of us!',
            'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'custom_image', 'x' => 0, 'y' => 0, 'w' => 700, 'h' => 628, 'zIndex' => 1, 'src' => 'https://evil.example/x.jpg'],
                ['field' => 'custom_text', 'x' => 740, 'y' => 120, 'w' => 420, 'h' => 120, 'zIndex' => 3, 'fontSize' => 44, 'fontWeight' => '800', 'color' => '#ffffff', 'text' => 'Merry Christmas'],
                ['field' => 'custom_text', 'x' => 740, 'y' => 260, 'w' => 420, 'h' => 60, 'zIndex' => 3, 'fontSize' => 22, 'color' => '#ffffff', 'text' => 'Closed 24 Dec - 2 Jan'],
                ['field' => 'logo', 'x' => 740, 'y' => 500, 'w' => 300, 'h' => 80, 'zIndex' => 3],
            ],
        ];
    }

    /** @param array<int, array<int, array<string, mixed>>> $batches each call returns the next batch */
    private function fakeAi(array $batches): object
    {
        $i = 0;
        Http::fake(function () use (&$i, $batches) {
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
        };
    }

    /** The user prompt's text, whether or not an image block comes before it. */
    private function userText(array $body): string
    {
        $content = $body['messages'][0]['content'];
        if (is_string($content)) {
            return $content;
        }

        return (string) collect($content)->firstWhere('type', 'text')['text'];
    }

    private function libraryPicture(string $occasion): string
    {
        $group = collect(CompanyAdLibrary::grouped())->firstWhere('occasion', $occasion);
        $this->assertNotNull($group, "the library has {$occasion} pictures");

        return $group['pictures'][0]['url'];
    }

    private function upload(int $agencyId, User $user): CompanyAdImage
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        $path  = 'company-ads/' . $agencyId . '/' . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, $bytes);

        $image = new CompanyAdImage(['user_id' => $user->id, 'path' => $path, 'width' => 800, 'height' => 600]);
        $image->agency_id = $agencyId;
        $image->save();

        return $image;
    }

    private function seedRow(int $agencyId, User $user): void
    {
        DB::table('ai_template_generations')->insert([
            'agency_id' => $agencyId, 'user_id' => $user->id, 'idea' => 'seed', 'ad_size' => 'facebook',
            'status' => 'success', 'cost_zar' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postGenerate(User $user, array $fields, array $files = [])
    {
        $this->actingAs($user); // binding a new request re-points its user resolver at the auth guard
        $request = Request::create('/api/v1/ad-manager/company-ads/ai/generate', 'POST', $fields, [], $files);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(CompanyAdsController::class)->generateWithAi($request, $this->service(), app(AiTemplateBuilderService::class));
    }

    private function postSave(User $user, AiTemplateGeneration $gen, array $fields)
    {
        $this->actingAs($user); // binding a new request re-points its user resolver at the auth guard
        $request = Request::create('/x', 'POST', $fields);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(CompanyAdsController::class)->saveAiOption($request, $gen, $this->service());
    }

    private function renderIndex(User $user): string
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates/company', 'GET');
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(CompanyAdsController::class)->index($request)->render();
    }

    /** @return array{0: int, 1: User} */
    private function agencyWithUser(array $agencyAttrs = [], ?string $name = null): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId(array_merge([
            'name' => $name ?? 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ], $agencyAttrs));

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
}
