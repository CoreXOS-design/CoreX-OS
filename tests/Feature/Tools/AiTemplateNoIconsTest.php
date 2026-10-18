<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\User;
use App\Services\AdTemplates\AdIdeaWishes;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AdTemplates\CompanyAdBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "No icons" in the user's idea is binding (AdIdeaWishes): the prompt says so as a hard rule, and every design - property
 * templates (both stages) and company ads - is cleaned on the server, so not one icon reaches an ad whatever the model answers.
 *
 * Every Anthropic call is Http::fake()d; nothing here spends a cent.
 */
final class AiTemplateNoIconsTest extends TestCase
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

    public function test_the_ways_people_ask_for_no_icons_are_recognised_and_other_ideas_are_not(): void
    {
        foreach ([
            'Modern and clean, no icons', 'do not use icons on the text', "Please don't add any icons", 'without icons please',
            'NO ICONS', 'icon-free, minimal', 'Icons are not needed', 'remove the icons', 'dont use icons', 'no text icons', 'geen ikone nie',
            'not a single icon', "please don’t use icons", "Don‘t add icons",
        ] as $idea) {
            $this->assertTrue(AdIdeaWishes::forbidsIcons($idea), $idea);
        }
        foreach (['Modern and clean', 'Use icons for beds and baths', "Don't forget the icons", 'Iconic coastal look', '', 'nothing fancy'] as $idea) {
            $this->assertFalse(AdIdeaWishes::forbidsIcons($idea), $idea);
        }
    }

    public function test_a_property_template_asked_without_icons_has_none_and_the_counts_keep_their_words(): void
    {
        $user = $this->user();
        $fake = $this->fakeAi(fn () => [$this->design('A'), $this->design('B'), $this->design('C')]);
        $this->actingAs($user);

        $gen = app(AiTemplateBuilderService::class)->generate($user, 'Clean modern look, do not use icons on the text', 'facebook', null, null, 'auto', false, [], ['big_price']);

        $this->assertCount(3, $gen->options_json, 'the designs are kept, only the icons go');
        foreach ($gen->options_json as $opt) {
            foreach ($opt['layout']['elements'] as $el) {
                $this->assertEmpty($el['icon'] ?? null, "{$el['field']} still has an icon");
            }
            $beds = collect($opt['layout']['elements'])->firstWhere('field', 'beds');
            $this->assertSame('label', $beds['numberFormat'], 'a lone "3" never ends up on the ad');
            $this->assertTrue($beds['showLabel'] ?? true);
        }
        $entry = collect($gen->validation_log_json)->firstWhere('name', 'A');
        $this->assertContains('Removed 2 icons - the idea asked for no icons.', $entry['repairs']);

        $this->assertStringContainsString('THE USER ASKED FOR NO ICONS', $fake->requests[0]['user']);
        $this->assertStringNotContainsString('- Icons: "icon" works on ANY text element', $fake->requests[0]['system']);
    }

    public function test_icons_stay_when_the_idea_does_not_rule_them_out(): void
    {
        $user = $this->user();
        $fake = $this->fakeAi(fn () => [$this->design('A'), $this->design('B'), $this->design('C')]);
        $this->actingAs($user);

        $gen = app(AiTemplateBuilderService::class)->generate($user, 'Clean modern look', 'facebook', null, null, 'auto', false, [], ['big_price']);

        $beds = collect($gen->options_json[0]['layout']['elements'])->firstWhere('field', 'beds');
        $this->assertSame('bed', $beds['icon']);
        $this->assertStringNotContainsString('NO ICONS', $fake->requests[0]['user']);
        $this->assertStringContainsString('- Icons: "icon" works on ANY text element', $fake->requests[0]['system']);
    }

    public function test_a_company_ad_asked_without_icons_has_none(): void
    {
        $user = $this->user();
        $fake = $this->fakeAi(fn () => [$this->companyOption('A'), $this->companyOption('B'), $this->companyOption('C')]);
        $this->actingAs($user);

        $gen = app(CompanyAdBuilderService::class)->generate($user, 'Thank you to all our clients - no icons', 'facebook', 'celebration', 'none');

        $this->assertNotEmpty($gen->options_json);
        foreach ($gen->options_json as $opt) {
            foreach ($opt['layout']['elements'] as $el) {
                $this->assertEmpty($el['icon'] ?? null, "{$el['field']} still has an icon");
            }
        }
        $this->assertStringContainsString('THE USER ASKED FOR NO ICONS', $fake->requests[0]['user']);
        $this->assertStringContainsString('No icons on this ad', $fake->requests[0]['system']);
        $this->assertStringNotContainsString('lines and icons only', $fake->requests[0]['user']);
    }

    /** A valid property design whose beds and baths lean on icons (numberFormat number, label off). */
    private function design(string $name): array
    {
        return ['name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220', 'elements' => [
            ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
            ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
            ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 700, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
            ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
            ['field' => 'agent_name', 'x' => 760, 'y' => 540, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ['field' => 'beds', 'x' => 40, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff', 'icon' => 'bed', 'iconSize' => 20, 'showLabel' => false],
            ['field' => 'baths', 'x' => 260, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff', 'icon' => 'bath', 'iconSize' => 20, 'showLabel' => false],
        ]];
    }

    private function companyOption(string $name): array
    {
        return [
            'name' => $name, 'style' => 'luxury', 'caption' => 'Thank you from all of us!', 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220',
            'elements' => [
                ['field' => 'custom_text', 'x' => 100, 'y' => 120, 'w' => 900, 'h' => 120, 'zIndex' => 3, 'fontSize' => 44, 'fontWeight' => '800', 'color' => '#ffffff', 'text' => 'Thank you', 'icon' => 'heart', 'iconSize' => 40],
                ['field' => 'custom_text', 'x' => 100, 'y' => 260, 'w' => 900, 'h' => 60, 'zIndex' => 3, 'fontSize' => 22, 'color' => '#ffffff', 'text' => 'To every client this year', 'icon' => 'star'],
                ['field' => 'logo', 'x' => 100, 'y' => 500, 'w' => 300, 'h' => 80, 'zIndex' => 3],
            ],
        ];
    }

    private function fakeAi(callable $answer): object
    {
        $state = new class {
            public array $requests = [];
        };
        Http::fake(function ($request) use ($state, $answer) {
            $body = json_decode($request->body(), true);
            $c    = $body['messages'][0]['content'];
            $user = is_array($c) ? (string) collect($c)->firstWhere('type', 'text')['text'] : (string) $c;
            $state->requests[] = ['user' => $user, 'system' => (string) $body['system']];

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['options' => $answer()])]],
                'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
            ], 200);
        });

        return $state;
    }

    private function user(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent']);
    }
}
