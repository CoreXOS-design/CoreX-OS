<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\AI\AiUsageEvent;
use App\Models\AiTemplateGeneration;
use App\Models\PropertyAdTemplate;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AdTemplateMustIncludes;
use App\Services\AdTemplates\AdTemplatePropertyTypes;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\AI\DTOs\NarrativeResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 §B.10 - the "Create with AI" pop-up: layout, must-include options, several property types.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.10 (decisions 17-18).
 *
 * Every Anthropic call is Http::fake()d with recorded-style answers; nothing here spends a cent.
 */
final class AiTemplateMultiTypeAndMustIncludeTest extends TestCase
{
    use RefreshDatabase;

    private const TYPES = ['House', 'Apartment / Flat', 'Vacant Land / Plot', 'Farm', 'Commercial Property', 'Industrial Property'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Storage::fake('local');
    }

    // ════════════════════════════════════════════════════════════════════
    // 1. The pop-up layout (markup that can be proved on the server)
    // ════════════════════════════════════════════════════════════════════

    public function test_the_pop_up_is_a_centred_dialog_with_a_pinned_header_and_footer_and_a_scrolling_body(): void
    {
        [$a, $user] = $this->agencyWithUser();
        $this->seedTypes($a, ['House']);
        $this->actingAs($user);
        $html = $this->renderIndex($user);
        // The pop-up plus the dialog shell it shares with the company-ad pop-up (styles, viewer and dialog behaviour live there).
        $partial = (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-template-modal.blade.php'))
            . (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-dialog-shell.blade.php'));

        // Structure: teleported to <body> (no page container can stretch it), real dialog semantics.
        $this->assertStringContainsString('x-teleport="body"', $html);
        foreach (['data-aitp="overlay"', 'data-aitp="dialog"', 'data-aitp="header"', 'data-aitp="body"', 'data-aitp="footer"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="aitp-title"', $html);
        // The footer's submit button belongs to the form even though it sits outside it.
        $this->assertStringContainsString('form="aitp-form"', $html);

        // Behaviour: Esc closes (not while generating), the backdrop closes, focus is kept inside and returned.
        $this->assertStringContainsString('@keydown.escape.window="onEscape()"', $html);
        $this->assertStringContainsString('if (this.open && !this.loading) this.closePanel();', $partial);
        $this->assertStringContainsString('@click="if (!loading) closePanel()"', $html);
        $this->assertStringContainsString('trapTab(e, box)', $partial);
        $this->assertStringContainsString('this._opener.focus()', $partial);
        $this->assertStringContainsString('aria-label="Close"', $html);

        // The old full-screen scroller (an overflow-y-auto fixed layer with a margin-top panel) is gone.
        $this->assertStringNotContainsString('fixed inset-0 z-50 overflow-y-auto', $html);
        $this->assertStringNotContainsString('my-6 w-[min(1100px,94vw)]', $html);

        // The rules: a bounded width and height, ONE scrolling region, pinned header/footer.
        $css = $this->css($partial);
        $this->assertMatchesRegularExpression('/\.aitp-dialog\s*\{[^}]*max-width:\s*1040px/', $css);
        $this->assertMatchesRegularExpression('/\.aitp-dialog\s*\{[^}]*max-height:\s*90vh/', $css);
        $this->assertMatchesRegularExpression('/\.aitp-dialog\s*\{[^}]*display:\s*flex;\s*flex-direction:\s*column/', $css);
        $this->assertMatchesRegularExpression('/\.aitp-body\s*\{[^}]*min-height:\s*0;[^}]*overflow-y:\s*auto;\s*overflow-x:\s*hidden/', $css);
        $this->assertMatchesRegularExpression('/\.aitp-head,\s*\.aitp-foot\s*\{[^}]*flex:\s*0 0 auto;\s*position:\s*sticky/', $css);
        $this->assertMatchesRegularExpression('/\.aitp-overlay\s*\{[^}]*position:\s*fixed;\s*inset:\s*0;[^}]*padding:\s*16px/', $css);
        // Phone width: one column form, a dialog that never exceeds the screen, cards in an auto-fitting grid that cannot overflow.
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $this->assertMatchesRegularExpression('/\.aitp-cards\s*\{[^}]*repeat\(auto-fill,\s*minmax\(min\(100%,\s*290px\),\s*1fr\)\)/', $css);
        // Colours come from CoreX tokens, so light and dark both work (no fixed page colours in the dialog's own rules).
        foreach (['var(--surface)', 'var(--surface-2)', 'var(--border)', 'var(--text-primary)'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        // The previews are scaled to the card and re-scaled on resize; the height is reserved from the aspect ratio.
        $this->assertStringContainsString('ResizeObserver', $partial);
        $this->assertStringContainsString('aspectRatio', $partial);
        // The loading state holds the cards' space (skeletons) so nothing jumps.
        $this->assertStringContainsString('aitp-skel', $html);
        // The styles are inline in the view: deploying this needs no front-end build.
        $this->assertStringContainsString('<style>', $partial);
    }

    public function test_the_panel_offers_the_agencys_own_property_types_and_stops_at_five(): void
    {
        [$a, $userA] = $this->agencyWithUser();
        [$b, $userB] = $this->agencyWithUser();
        $this->seedTypes($a, ['Cottage', 'Smallholding', 'Vacant Land / Plot']);
        $this->seedTypes($b, ['Penthouse', 'Warehouse']);
        $this->seedTypes($a, ['Retired Type'], active: false);

        $htmlA = $this->renderIndex($userA);
        foreach (['Cottage', 'Smallholding', 'Vacant Land / Plot'] as $name) {
            $this->assertStringContainsString('<span>' . e($name) . '</span>', $htmlA);
        }
        foreach (['Penthouse', 'Warehouse', 'Retired Type', 'Apartment / Flat'] as $name) {
            $this->assertStringNotContainsString('<span>' . e($name) . '</span>', $htmlA, "{$name} must not be offered to agency A");
        }
        $htmlB = $this->renderIndex($userB);
        $this->assertStringContainsString('<span>Penthouse</span>', $htmlB);
        $this->assertStringNotContainsString('Cottage', $htmlB);

        // The sixth tick is blocked on screen (and refused by the server, below).
        $this->assertStringContainsString('\u0022maxTypes\u0022:' . AdTemplatePropertyTypes::MAX_TYPES, $htmlA);
        $this->assertStringContainsString('types.length >= maxTypes', $htmlA);
        $this->assertSame(5, AdTemplatePropertyTypes::MAX_TYPES);

        // An agency with no types configured gets the plain explanation, not a built-in list.
        [, $userC] = $this->agencyWithUser();
        $htmlC = $this->renderIndex($userC);
        $this->assertStringContainsString('has no property types set up yet', $htmlC);
        $this->assertStringNotContainsString('Vacant Land', $htmlC);
    }

    public function test_the_panel_lists_every_must_include_option_grouped_and_the_ribbon_words_box_is_capped(): void
    {
        [, $user] = $this->agencyWithUser();
        $html = $this->renderIndex($user);

        foreach (['Big price', 'Show day date', 'Agent photo and contact', 'Agency logo', 'Key features as pills', 'QR code to the listing', 'Photo collage', 'Corner ribbon'] as $label) {
            $this->assertStringContainsString('<span>' . $label . '</span>', $html);
        }
        $this->assertStringContainsString('Information to show', $html);
        $this->assertStringContainsString('Design extras', $html);
        $this->assertStringContainsString('maxlength="' . AdLayoutValidator::MAX_RIBBON_TEXT . '"', $html);
        $this->assertStringContainsString("body.append('must_include[]', k)", (string) file_get_contents(resource_path('views/tools/ad-manager/_ai-template-modal.blade.php')));
        // This agency has no logo file: the box says what will happen instead.
        $this->assertStringContainsString('No logo file is set up for your agency yet', $html);
    }

    // ════════════════════════════════════════════════════════════════════
    // 2. Must-include options
    // ════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: array<int, string>, 2: array<string, mixed>}> */
    public static function mustIncludeMapping(): array
    {
        return [
            'big price needs a price of 48+'        => ['big_price', ['price'], ['price' => ['fontSize' => 56]]],
            'show day'                              => ['show_day', ['show_day'], []],
            'feature pills'                         => ['feature_pills', ['feature_pills'], []],
            'qr'                                    => ['qr', ['qr'], []],
            'collage'                               => ['collage', ['collage'], []],
            'logo (the logo element)'               => ['logo', ['logo'], []],
            'logo (the image element)'              => ['logo', ['agency_logo'], []],
        ];
    }

    #[DataProvider('mustIncludeMapping')]
    public function test_each_tick_is_satisfied_only_by_its_own_element_types(string $key, array $types, array $props): void
    {
        $label = AdTemplateMustIncludes::label($key);
        $with  = ['elements' => array_map(fn ($t) => ['field' => $t, 'fontSize' => 60] + ($props[$t] ?? []), $types)];

        $this->assertSame([], AdTemplateMustIncludes::missing($with, [$key]), "{$key} should be satisfied by " . implode('+', $types));
        $this->assertSame([$label], AdTemplateMustIncludes::missing(['elements' => [['field' => 'title']]], [$key]));
        // A hidden element never counts.
        $hidden = ['elements' => array_map(fn ($t) => ['field' => $t, 'fontSize' => 60, 'hidden' => true], $types)];
        $this->assertSame([$label], AdTemplateMustIncludes::missing($hidden, [$key]));
    }

    public function test_agent_photo_and_contact_needs_the_avatar_and_a_phone_or_an_email(): void
    {
        $miss = fn (array $fields) => AdTemplateMustIncludes::missing(['elements' => array_map(fn ($f) => ['field' => $f], $fields)], ['agent_contact']);

        $this->assertSame([], $miss(['agent_avatar', 'agent_phone']));
        $this->assertSame([], $miss(['agent_avatar', 'agent_email']));
        $this->assertNotSame([], $miss(['agent_avatar']), 'a photo with no way to reach the agent is not "photo and contact"');
        $this->assertNotSame([], $miss(['agent_phone', 'agent_email']), 'contact with no photo is not "photo and contact"');
        $this->assertNotSame([], $miss(['agent_name']));
    }

    public function test_a_big_price_must_really_be_big_and_a_ribbon_must_carry_the_users_exact_words(): void
    {
        $this->assertNotSame([], AdTemplateMustIncludes::missing(['elements' => [['field' => 'price', 'fontSize' => 40]]], ['big_price']));
        $this->assertSame([], AdTemplateMustIncludes::missing(['elements' => [['field' => 'price', 'fontSize' => AdTemplateMustIncludes::BIG_PRICE_MIN_FONT]]], ['big_price']));

        $ribbon = fn (string $text) => ['elements' => [['field' => 'ribbon', 'text' => $text]]];
        $this->assertSame([], AdTemplateMustIncludes::missing($ribbon('JUST LISTED'), ['ribbon'], 'JUST LISTED'));
        $this->assertNotSame([], AdTemplateMustIncludes::missing($ribbon('JUST LIST'), ['ribbon'], 'JUST LISTED'), 'a cut-short ribbon does not count');
        $this->assertNotSame([], AdTemplateMustIncludes::missing($ribbon('NEW'), ['ribbon'], 'JUST LISTED'));
    }

    public function test_the_prompt_states_every_ticked_item_as_a_hard_requirement_and_nothing_when_nothing_is_ticked(): void
    {
        $this->assertSame('', AdTemplateMustIncludes::promptBlock([]));

        $block = AdTemplateMustIncludes::promptBlock(['qr', 'ribbon', 'big_price'], 'SOLD "FAST"');
        $this->assertStringContainsString('MUST INCLUDE', $block);
        $this->assertStringContainsString('"qr" element', $block);
        $this->assertStringContainsString('fontSize of at least ' . AdTemplateMustIncludes::BIG_PRICE_MIN_FONT, $block);
        $this->assertStringContainsString('"SOLD \"FAST\""', $block, 'the ribbon words are quoted as data, quotes escaped');
        $this->assertStringNotContainsString('collage', $block, 'only ticked items are asked for');
    }

    public function test_a_design_missing_a_ticked_item_is_retried_once_and_told_exactly_what_is_missing(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(function (array $call) {
            // First answer: 3 valid designs with NO qr. Retry: 3 designs with a qr.
            return array_map(fn ($n) => $this->design('D' . $n, $call['n'] === 1 ? [] : ['qr']), [1, 2, 3]);
        });
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(2, $fake->calls());
        $retry = $fake->userPrompt(1);
        $this->assertStringContainsString('missing what the user ticked: QR code to the listing', $retry);
        $this->assertStringContainsString('Option 1 is missing', $retry);
        $this->assertCount(3, $gen->options_json);
        foreach ($gen->options_json as $opt) {
            $this->assertContains('qr', array_column($opt['layout']['elements'], 'field'));
        }
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
        $this->assertSame(['keys' => ['qr'], 'ribbon_text' => ''], $gen->must_include_json);
    }

    public function test_a_default_that_still_lacks_a_ticked_item_after_the_retry_is_dropped_never_shown(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        // Decision 19: only the first design ever has both ticked items; the other two never do - they are dropped, not shown.
        $fake = $this->fakeAi(fn (array $call) => $call['n'] % 2 === 1
            ? [$this->design('Has both', ['qr', 'show_day']), $this->design('No QR 1', ['show_day']), $this->design('No QR 2', [])]
            : [$this->design('No QR 3', ['show_day']), $this->design('No QR 4', ['qr'])]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr', 'show_day']);

        $this->assertSame(2, $fake->calls(), 'exactly one targeted retry');
        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(1, $gen->options_json, 'only the complete design is offered');
        $this->assertSame('Has both', $gen->options_json[0]['name']);
        $this->assertArrayNotHasKey('missing', $gen->options_json[0]);
        $this->assertSame(1, $this->builder()->usage($agency)['used'], 'what was shown is counted');
        // What was lacking stays in the log for diagnosis.
        $entry = collect($gen->validation_log_json)->firstWhere('name', 'No QR 1');
        $this->assertSame('incomplete', $entry['outcome']);
        $this->assertSame(['QR code to the listing'], $entry['missing']);

        // The endpoint hands over the complete designs only, with nothing to mark.
        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'must_include' => ['qr', 'show_day']])->getData(true);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['options']);
        $this->assertArrayNotHasKey('missing', $res['options'][0]);
    }

    public function test_when_no_default_is_complete_the_request_is_a_friendly_failure_not_counted_but_the_spend_is_recorded(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => [$this->design('A', []), $this->design('B', []), $this->design('C', [])]);
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);
            $this->fail('Expected a friendly failure.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('couldn\'t make a design that includes everything you ticked', $e->getMessage());
            $this->assertStringContainsString('untick something', $e->getMessage());
        }

        $this->assertSame(2, $fake->calls(), 'the one retry was still made');
        $this->assertSame(0, $this->builder()->usage($agency)['used'], 'not counted against the allowance');
        $gen = AiTemplateGeneration::where('agency_id', $agency)->latest('id')->firstOrFail();
        $this->assertSame(AiTemplateGeneration::STATUS_FAILED, $gen->status);
        // It was spent, so the ledger and the generation's cost still show it.
        $events = AiUsageEvent::where('agency_id', $agency)->where('source', AiUsageEvent::SOURCE_AD_TEMPLATE)->get();
        $this->assertCount(2, $events);
        $this->assertGreaterThan(0, (float) $events->sum('cost_zar'));
        $this->assertEqualsWithDelta((float) $events->sum('cost_zar'), (float) $gen->cost_zar, 0.001);

        // And the endpoint says it as a plain sentence.
        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'must_include' => ['qr']])->getData(true);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('includes everything you ticked', $res['message']);
    }

    public function test_with_nothing_ticked_a_valid_design_is_always_shown_and_counted_as_before(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => [$this->design('A', []), $this->design('B', []), $this->design('C', [])]);
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], []);

        $this->assertSame(1, $fake->calls());
        $this->assertCount(3, $gen->options_json);
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
        $this->assertNull($gen->must_include_json);
    }

    public function test_saving_a_dropped_option_is_refused_even_if_a_stale_browser_posts_it(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->fakeAi(fn (array $call) => $call['n'] % 2 === 1
            ? [$this->design('Has QR', ['qr']), $this->design('No QR 1', []), $this->design('No QR 2', [])]
            : [$this->design('No QR 3', []), $this->design('No QR 4', [])]);
        $this->actingAs($user);
        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);
        $this->assertCount(1, $gen->options_json);

        // Options 1 and 2 were dropped: the server has no such design, whatever the browser thinks.
        foreach ([1, 2] as $dropped) {
            $res = $this->postSave($user, $gen, ['option' => $dropped])->getData(true);
            $this->assertFalse($res['ok']);
        }
        $this->assertSame(0, PropertyAdTemplate::where('agency_id', $agency)->count());
        $this->assertTrue($this->postSave($user, $gen, ['option' => 0])->getData(true)['ok']);

        // A generation stored before this rule, whose option still carries what it lacks, is refused too.
        $old = $gen->replicate();
        $old->options_json = [['name' => 'Old', 'layout' => $gen->options_json[0]['layout'], 'style' => null, 'missing' => ['QR code to the listing'], 'variants' => [], 'variant_notes' => []]];
        $old->saved_template_ids = null;
        $old->save();
        $this->assertFalse($this->postSave($user, $old, ['option' => 0])->getData(true)['ok']);
    }

    public function test_nothing_valid_is_still_a_friendly_failure_even_when_must_includes_are_ticked(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $bad = ['name' => 'Nope', 'elements' => [['field' => 'rocket_launcher', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]];
        $this->fakeAi(fn () => [$bad, $bad, $bad]);
        $this->actingAs($user);

        $this->expectException(AiTemplateGenerationFailedException::class);
        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['qr']);
        } finally {
            $this->assertSame(0, $this->builder()->usage($agency)['used']);
        }
    }

    public function test_the_ribbon_words_are_used_verbatim_not_the_ais_wording(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => array_map(fn ($n) => $this->design('R' . $n, ['ribbon' => 'SOMETHING ELSE']), [1, 2, 3]));
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['ribbon'], 'Just Listed');

        $this->assertSame(1, $fake->calls());
        foreach ($gen->options_json as $opt) {
            $ribbon = collect($opt['layout']['elements'])->firstWhere('field', 'ribbon');
            $this->assertSame('Just Listed', $ribbon['text'], 'exactly as typed - not upper-cased, not the AI\'s words');
        }
        $this->assertStringContainsString('"Just Listed"', $fake->userPrompt(0));
        $this->assertSame('Just Listed', $gen->must_include_json['ribbon_text']);
    }

    public function test_the_ribbon_words_are_plain_text_capped_and_free_of_markup(): void
    {
        $this->assertSame('JUST LISTED', AdTemplateMustIncludes::cleanRibbonText("  <b>JUST</b>\n\t LISTED  "));
        $this->assertSame('alert(1) JUST', AdTemplateMustIncludes::cleanRibbonText('<script>alert(1)</script> JUST'));
        $long = AdTemplateMustIncludes::cleanRibbonText(str_repeat('A', 80));
        $this->assertSame(AdLayoutValidator::MAX_RIBBON_TEXT, mb_strlen($long));
        $this->assertSame('', AdTemplateMustIncludes::cleanRibbonText('   '));
        // Quotes are kept (they are just words); they are escaped where they are drawn, which the kernel does for every text.
        $this->assertSame('SOLD "FAST"', AdTemplateMustIncludes::cleanRibbonText('SOLD "FAST"'));
        $this->assertStringContainsString("esc(el.text || '')", (string) file_get_contents(base_path('public/js/corex-ad-render.js')));

        [, $user] = $this->agencyWithUser();
        $this->fakeAi(fn () => array_map(fn ($n) => $this->design('R' . $n, ['ribbon']), [1, 2, 3]));
        $this->actingAs($user);
        // 24 capital letters cannot fit the ribbon band: the validator's fit repair would have cut the words, so the design
        // counts as lacking the ribbon and (decision 19) is dropped - the user's words are never silently shortened and
        // passed off as theirs. Every design is like that here, so the request is a friendly failure and not counted.
        $cleaned = AdTemplateMustIncludes::cleanRibbonText('<i>NEW</i> ' . str_repeat('B', 60));
        $this->assertSame(AdLayoutValidator::MAX_RIBBON_TEXT, mb_strlen($cleaned));
        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['ribbon'], '<i>NEW</i> ' . str_repeat('B', 60));
            $this->fail('A ribbon that cannot carry the words whole must not be shown.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('includes everything you ticked', $e->getMessage());
        }
        $entry = collect(AiTemplateGeneration::latest('id')->firstOrFail()->validation_log_json)->firstWhere('outcome', 'incomplete');
        $this->assertSame(['Corner ribbon'], $entry['missing']);
    }

    public function test_a_ticked_ribbon_with_no_words_is_refused_before_any_ai_call(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        Http::fake();
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['ribbon'], '  <b></b> ');
            $this->fail('Expected a refusal.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('words you want on the corner ribbon', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::count());
        $this->assertSame(0, $this->builder()->usage($agency)['used']);
    }

    public function test_an_unknown_must_include_key_is_refused_by_the_endpoint(): void
    {
        [, $user] = $this->agencyWithUser();
        Http::fake();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        try {
            $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'must_include' => ['laser_show']]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_logo_tick_for_an_agency_without_a_logo_asks_for_the_logo_element_that_falls_back_to_the_name(): void
    {
        [, $user] = $this->agencyWithUser();
        $fake = $this->fakeAi(fn () => array_map(fn ($n) => $this->design('L' . $n, ['logo']), [1, 2, 3]));
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, [], ['logo']);

        $system = (string) $fake->system(0);
        $this->assertStringContainsString("use the 'logo' element", $system);
        $this->assertStringNotContainsString('do not use the logo elements', $system);
        $this->assertCount(3, $gen->options_json);
        $this->assertContains('logo', array_column($gen->options_json[0]['layout']['elements'], 'field'));
    }

    // ════════════════════════════════════════════════════════════════════
    // 3. Several property types
    // ════════════════════════════════════════════════════════════════════

    public function test_each_ticked_type_gets_a_version_of_every_design_adapted_to_it_and_it_counts_once(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $fake = $this->smartAi();
        $this->actingAs($user);

        // Ticked in a different case on purpose: the stored key is the agency list's own spelling.
        $gen = $this->builder()->generate($user, 'Bold look', 'facebook', null, null, 'auto', false, ['vacant land / plot', 'Apartment / Flat', 'House']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(['Vacant Land / Plot', 'Apartment / Flat', 'House'], $gen->property_types_json);
        $this->assertCount(3, $gen->options_json);
        $this->assertSame(1 + 3, $fake->calls(), 'one call for the Defaults, one per ticked type');

        foreach ($gen->options_json as $i => $opt) {
            // (MySQL's JSON column keeps object keys in its own order; the screen's order comes from property_types_json.)
            $this->assertEqualsCanonicalizing(['Vacant Land / Plot', 'Apartment / Flat', 'House'], array_keys($opt['variants']));
            $this->assertSame([], $opt['variant_notes']);
            foreach ($opt['variants'] as $type => $variant) {
                $this->assertSame(1200, $variant['layout']['canvasW'], 'same canvas as the Default');
                $this->assertSame('facebook', $variant['layout']['canvasPreset']);
                $this->assertSame($opt['layout']['canvasBg'], $variant['layout']['canvasBg'], 'same look family: the variant keeps the design\'s own background');
            }
            $fields = fn (string $type) => array_column($opt['variants'][$type]['layout']['elements'], 'field');
            // The Default has bedrooms and bathrooms; land has neither and shows the plot; an apartment adds the levy.
            $this->assertContains('beds', array_column($opt['layout']['elements'], 'field'));
            $this->assertNotContains('beds', $fields('Vacant Land / Plot'));
            $this->assertNotContains('baths', $fields('Vacant Land / Plot'));
            $this->assertContains('erf_size', $fields('Vacant Land / Plot'));
            $this->assertContains('levy', $fields('Apartment / Flat'));
            $this->assertContains('beds', $fields('House'));
        }

        // Each type's call carries that type, its hint and the three approved designs.
        $typeCalls = collect($fake->requests)->filter(fn ($r) => str_starts_with($r['user'], 'Adapt '))->values();
        $this->assertCount(3, $typeCalls);
        $land = $typeCalls->first(fn ($r) => str_contains($r['user'], '"Vacant Land / Plot"'));
        $this->assertStringContainsString('NO bedrooms', $land['user']);
        $this->assertStringContainsString('<design for_option="1"', $land['user']);
        $this->assertStringContainsString('<design for_option="3"', $land['user']);
        $this->assertStringContainsString('NOT a request for new designs', $land['user']);
        $this->assertStringNotContainsString('Apartment / Flat', $land['user'], 'a type call mentions only its own type');
        $this->assertStringNotContainsString('EXISTING TEMPLATES', $land['system']);

        // Decision 18: one request, one count - however many types.
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
        $this->assertSame(1, AiTemplateGeneration::count());
        // Every call is its own ledger line, all under the template builder's source, and the generation's cost is their sum.
        $events = AiUsageEvent::where('agency_id', $agency)->where('source', AiUsageEvent::SOURCE_AD_TEMPLATE)->get();
        $this->assertCount(4, $events);
        $this->assertEqualsWithDelta((float) $events->sum('cost_zar'), (float) $gen->cost_zar, 0.001);
    }

    public function test_saving_writes_the_section_18_variants_and_the_right_version_is_picked_per_listing(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $this->smartAi();
        $this->actingAs($user);
        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['Vacant Land / Plot', 'Apartment / Flat']);

        $res = $this->postSave($user, $gen, ['option' => 1, 'name' => 'Family of looks'])->getData(true);
        $this->assertTrue($res['ok']);

        $template = PropertyAdTemplate::findOrFail($res['id']);
        $layout   = $template->layout_json;
        $this->assertEqualsCanonicalizing(['Vacant Land / Plot', 'Apartment / Flat'], array_keys($layout['variants']), 'keys are the exact property-type strings');
        $this->assertSame(1200, $layout['canvasW'], 'the Default stays the template\'s own top-level design');
        $this->assertContains('beds', array_column($layout['elements'], 'field'));
        $this->assertSame($gen->options_json[1]['layout']['elements'], $layout['elements']);
        foreach ($layout['variants'] as $variant) {
            foreach (['canvasW', 'canvasH', 'canvasBg', 'canvasBgMode', 'canvasBgFrom', 'canvasBgTo', 'canvasBgAngle', 'canvasPreset', 'elements'] as $key) {
                $this->assertArrayHasKey($key, $variant, "§18 variant key {$key}");
            }
        }

        // §18 resolution: the land listing gets its version (case/space-insensitive), a type with no version gets the Default.
        $land = $template->resolvedLayoutFor(' vacant land / PLOT ');
        $this->assertNotContains('beds', array_column($land['elements'], 'field'));
        $this->assertContains('erf_size', array_column($land['elements'], 'field'));
        $this->assertContains('levy', array_column($template->resolvedLayoutFor('Apartment / Flat')['elements'], 'field'));
        $house = $template->resolvedLayoutFor('House');
        $this->assertContains('beds', array_column($house['elements'], 'field'));
        $this->assertSame($layout['elements'], $house['elements']);
        $this->assertSame($layout['elements'], $template->resolvedLayoutFor(null)['elements']);

        // The Template Manager counts them as property-type versions (the same §18 shape the Ad Builder's "Design for" bar reads).
        $this->assertSame(2, count($layout['variants']));
        $this->assertTrue($template->ai_generated);
        // Saving twice returns the same template and does not multiply it.
        $again = $this->postSave($user, $gen, ['option' => 1])->getData(true);
        $this->assertSame($res['id'], $again['id']);
    }

    public function test_a_version_that_fails_after_its_retry_is_dropped_and_that_type_uses_the_default(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $fake = $this->smartAi(variant: function (string $type, int $forOption, array $default, int $round) {
            if ($type === 'Apartment / Flat') {
                return ['name' => 'broken', 'elements' => [['field' => 'rocket_launcher', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]];
            }

            return null; // the normal adaptation
        });
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['Vacant Land / Plot', 'Apartment / Flat']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame(1 + 2 + 1, $fake->calls(), 'defaults + two types + exactly one retry, for the failed type only');
        foreach ($gen->options_json as $opt) {
            $this->assertSame(['Vacant Land / Plot'], array_keys($opt['variants']));
            $this->assertSame('No separate design for Apartment / Flat — uses the main design', $opt['variant_notes']['Apartment / Flat']);
        }
        $retry = collect($fake->requests)->last();
        $this->assertStringContainsString('"Apartment / Flat"', $retry['user']);
        $this->assertStringContainsString('previous answer for this property type was not fully usable', $retry['user']);

        // The note reaches the card, the saved template has no entry for that type, and §18 falls back to the Default.
        $res  = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'property_types' => ['Vacant Land / Plot', 'Apartment / Flat']])->getData(true);
        $this->assertStringContainsString('No separate design for Apartment / Flat', json_encode($res['options'][0]['variant_notes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $saved = PropertyAdTemplate::findOrFail($this->postSave($user, $gen, ['option' => 0])->getData(true)['id']);
        $this->assertSame(['Vacant Land / Plot'], array_keys($saved->layout_json['variants']));
        $this->assertSame($saved->layout_json['elements'], $saved->resolvedLayoutFor('Apartment / Flat')['elements']);
        $this->assertSame(2, $this->builder()->usage($agency)['used'], 'two requests, each counted once however many types (saving costs nothing)');
    }

    public function test_a_type_call_that_fails_outright_does_not_spoil_the_other_types_or_the_defaults(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $this->smartAi(httpFail: fn (string $type) => $type === 'Farm');
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['Farm', 'House']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(3, $gen->options_json);
        foreach ($gen->options_json as $opt) {
            $this->assertSame(['House'], array_keys($opt['variants']));
            $this->assertArrayHasKey('Farm', $opt['variant_notes']);
        }
    }

    public function test_a_version_that_lacks_a_ticked_item_is_retried_then_dropped_and_the_type_uses_the_main_design(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        // The Defaults have the QR; the apartment versions drop it, both times.
        $fake = $this->smartAi(
            defaultExtras: ['qr'],
            variant: function (string $type, int $forOption, array $default, int $round) {
                if ($type === 'Apartment / Flat') {
                    $default['elements'] = array_values(array_filter($default['elements'], fn ($e) => $e['field'] !== 'qr'));

                    return $default;
                }

                return null;
            },
        );
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['Apartment / Flat', 'House'], ['qr']);

        $this->assertSame(1 + 2 + 1, $fake->calls(), 'a retry round for the apartment only');
        $this->assertStringContainsString('missing what the user ticked: QR code to the listing', collect($fake->requests)->last()['user']);
        $this->assertCount(3, $gen->options_json);
        foreach ($gen->options_json as $opt) {
            $this->assertSame(['House'], array_keys($opt['variants']), 'the incomplete apartment version is dropped');
            $this->assertContains('qr', array_column($opt['variants']['House']['layout']['elements'], 'field'));
            $this->assertSame('No separate design for Apartment / Flat — uses the main design', $opt['variant_notes']['Apartment / Flat']);
        }
        $this->assertSame(1, $this->builder()->usage($agency)['used']);

        // Saving gives the template only the complete versions; the apartment falls back to the (complete) main design.
        $saved = PropertyAdTemplate::findOrFail($this->postSave($user, $gen, ['option' => 0])->getData(true)['id']);
        $this->assertSame(['House'], array_keys($saved->layout_json['variants']));
        $this->assertContains('qr', array_column($saved->resolvedLayoutFor('Apartment / Flat')['elements'], 'field'));
    }

    public function test_more_than_five_types_are_refused_with_no_ai_call_and_no_count(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        Http::fake();
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, self::TYPES);
            $this->fail('Six types must be refused.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('at most 5 property types', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::count());

        // The endpoint refuses it too (the form never sends more than five).
        try {
            $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'property_types' => self::TYPES]);
            $this->fail('Six types must be refused by the endpoint.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at most 5 property types', $e->errors()['property_types'][0]);
        }
        Http::assertNothingSent();

    }

    public function test_exactly_five_types_are_accepted_and_still_count_once(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $fake = $this->smartAi();
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, array_slice(self::TYPES, 0, 5));

        $this->assertCount(5, $gen->options_json[0]['variants']);
        $this->assertSame(1 + 5, $fake->calls());
        $this->assertSame(1, $this->builder()->usage($agency)['used']);
    }

    public function test_a_type_that_is_not_on_the_agencys_own_list_is_refused_even_if_another_agency_has_it(): void
    {
        [$a, $userA] = $this->agencyWithUser();
        [$b] = $this->agencyWithUser();
        $this->seedTypes($a, ['House']);
        $this->seedTypes($b, ['Warehouse']);
        Http::fake();
        $this->actingAs($userA);

        try {
            $this->builder()->generate($userA, 'x', 'facebook', null, null, 'auto', false, ['Warehouse']);
            $this->fail('Another agency\'s type must be refused.');
        } catch (AiTemplateGenerationFailedException $e) {
            $this->assertStringContainsString('"Warehouse" is not one of your agency\'s property types', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::count());
    }

    public function test_the_second_agency_gets_its_own_types_brand_and_variants_and_none_of_the_first(): void
    {
        [$a, $userA] = $this->agencyWithUser();
        [$b, $userB] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $a)->update(['name' => 'Alpha Realty Qx', 'default_color' => '#112233']);
        DB::table('agencies')->where('id', $b)->update(['name' => 'Bravo Homes Zy', 'default_color' => '#aabbcc']);
        $this->seedTypes($a, ['House']);
        $this->seedTypes($b, ['Warehouse']);
        $fake = $this->smartAi();

        $this->actingAs($userB);
        $gen = $this->builder()->generate($userB, 'x', 'facebook', null, null, 'auto', false, ['Warehouse']);

        $this->assertSame((int) $b, (int) $gen->agency_id);
        $this->assertSame(['Warehouse'], array_keys($gen->options_json[0]['variants']));
        $all = json_encode($fake->requests);
        $this->assertStringContainsString('#aabbcc', $all);
        $this->assertStringNotContainsString('#112233', $all);
        $this->assertStringNotContainsString('Alpha', $all);
        // Commercial wording comes from the agency's own type name, not from a built-in list.
        $this->assertStringContainsString('bedrooms and bathrooms do not apply', $all);

        // Agency A cannot save agency B's generation (AgencyScope: not found).
        $this->actingAs($userA);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        AiTemplateGeneration::findOrFail($gen->id);
    }

    public function test_a_paused_agency_is_refused_before_any_call_even_with_types_and_must_includes(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, ['House']);
        DB::table('agencies')->where('id', $agency)->update(['ai_paused_at' => now()]);
        Http::fake();
        $this->actingAs($user);

        try {
            $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['House'], ['qr']);
            $this->fail('A paused agency must be refused.');
        } catch (AiPausedException $e) {
            $this->assertSame(AiPausedException::MESSAGE, $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiTemplateGeneration::count());
    }

    public function test_a_pause_that_happens_between_the_stages_keeps_the_defaults_and_drops_the_type_versions(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, ['House', 'Farm']);
        // The cut-out lands right after the Default designs came back.
        $this->smartAi(afterDefault: fn () => DB::table('agencies')->where('id', $agency)->update(['ai_paused_at' => now()]));
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['House', 'Farm']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertCount(3, $gen->options_json);
        $this->assertSame([], $gen->options_json[0]['variants']);
        $this->assertArrayHasKey('House', $gen->options_json[0]['variant_notes']);
        $this->assertArrayHasKey('Farm', $gen->options_json[0]['variant_notes']);
        Http::assertSentCount(1);
    }

    public function test_a_pause_that_lands_during_the_first_round_stops_the_retry_round(): void
    {
        // AT-456 audit D-M3: the cut-out can trip on round 1's own spend; the retry round must not be sent after it.
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, ['House', 'Farm']);
        $this->smartAi(variant: function (string $type, int $forOption, array $default, int $round) use ($agency) {
            DB::table('agencies')->where('id', $agency)->update(['ai_paused_at' => now()]);

            return ['name' => 'unusable', 'elements' => []]; // forces a retry round
        });
        $this->actingAs($user);

        $gen = $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['House', 'Farm']);

        $this->assertSame(AiTemplateGeneration::STATUS_SUCCESS, $gen->status);
        $this->assertSame([], $gen->options_json[0]['variants']);
        Http::assertSentCount(3); // the Default designs + one call per type in round 1; round 2 never went out
    }

    public function test_the_cards_list_the_versions_in_the_order_the_types_were_ticked(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $this->smartAi();
        $this->actingAs($user);

        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'property_types' => ['Vacant Land / Plot', 'House', 'Apartment / Flat']])->getData(true);

        $this->assertSame(['Vacant Land / Plot', 'House', 'Apartment / Flat'], $res['property_types']);
        $this->assertSame(['Vacant Land / Plot', 'House', 'Apartment / Flat'], array_keys($res['options'][0]['variants']));
        $this->assertArrayHasKey('layout', $res['options'][0]['variants']['House']);
        $this->assertArrayNotHasKey('missing', $res['options'][0]['variants']['House']);
    }

    public function test_the_legacy_single_property_type_stays_context_only_and_makes_no_extra_call(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $fake = $this->smartAi();
        $this->actingAs($user);

        $res = $this->postGenerate($user, ['idea' => 'x', 'ad_size' => 'facebook', 'property_type' => 'House'])->getData(true);

        $this->assertTrue($res['ok']);
        $this->assertSame(1, $fake->calls());
        $this->assertSame([], $res['options'][0]['variants']);
        $this->assertSame([], $res['property_types']);
    }

    public function test_the_defaults_prompt_mentions_the_ticked_types_and_must_includes(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->seedTypes($agency, self::TYPES);
        $fake = $this->smartAi(defaultExtras: ['qr', 'collage']);
        $this->actingAs($user);

        $this->builder()->generate($user, 'x', 'facebook', null, null, 'auto', false, ['House', 'Farm'], ['qr', 'collage']);

        $first = $fake->requests[0]['user'];
        $this->assertStringContainsString('adapted to these kinds of property: House, Farm', $first);
        $this->assertStringContainsString('MUST INCLUDE', $first);
        // The same hard requirements travel to every type call.
        foreach (collect($fake->requests)->skip(1) as $r) {
            $this->assertStringContainsString('MUST INCLUDE', $r['user']);
            $this->assertStringContainsString('"qr" element', $r['user']);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. The gateway's concurrent batch keeps every per-call guarantee
    // ════════════════════════════════════════════════════════════════════

    public function test_generate_many_makes_one_ledger_line_per_call_and_one_failure_does_not_stop_the_others(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        $this->actingAs($user);
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            if (str_contains($body['messages'][0]['content'], 'FAIL')) {
                return Http::response(['error' => 'overloaded'], 529);
            }

            return Http::response(['content' => [['type' => 'text', 'text' => 'ok ' . $body['messages'][0]['content']]], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 500]], 200);
        });

        $mk = fn (string $text, string $key) => new NarrativeRequest(
            narrativeType: 'ad_template', cacheKey: 'many:' . $key . ':' . Str::random(4), modelAlias: 'quality', systemPrompt: 's', userPrompt: $text,
            agencyId: $agency, forceRefresh: true, source: AiUsageEvent::SOURCE_AD_TEMPLATE, timeoutSeconds: 30,
        );
        $out = app(AnthropicGateway::class)->generateMany(['a' => $mk('one', 'a'), 'b' => $mk('FAIL', 'b'), 'c' => $mk('three', 'c')]);

        $this->assertSame(['a', 'b', 'c'], array_keys($out), 'results come back in the order asked');
        $this->assertInstanceOf(NarrativeResponse::class, $out['a']);
        $this->assertSame('ok one', $out['a']->outputText);
        $this->assertInstanceOf(\Throwable::class, $out['b']);
        $this->assertSame('ok three', $out['c']->outputText);
        $this->assertSame(2, AiUsageEvent::where('agency_id', $agency)->where('source', AiUsageEvent::SOURCE_AD_TEMPLATE)->where('fallback', false)->count(), 'one costed ledger line per successful call');
        $this->assertSame(1, AiUsageEvent::where('agency_id', $agency)->where('source', AiUsageEvent::SOURCE_AD_TEMPLATE)->where('fallback', true)->where('cost_zar', 0)->count(), 'the failed call is on the ledger at cost 0 (AT-456 audit D-M1)');
        Http::assertSentCount(3);
    }

    public function test_generate_many_refuses_a_paused_agency_without_any_http_call(): void
    {
        [$agency, $user] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agency)->update(['ai_paused_at' => now()]);
        $this->actingAs($user);
        Http::fake();

        $req = new NarrativeRequest(
            narrativeType: 'ad_template', cacheKey: 'many:paused', modelAlias: 'quality', systemPrompt: 's', userPrompt: 'u',
            agencyId: $agency, forceRefresh: true, source: AiUsageEvent::SOURCE_AD_TEMPLATE,
        );
        $out = app(AnthropicGateway::class)->generateMany(['x' => $req]);

        $this->assertInstanceOf(AiPausedException::class, $out['x']);
        Http::assertNothingSent();
    }

    // ════════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════════

    /** The CSS inside the partial's <style> block. */
    private function css(string $partial): string
    {
        preg_match('/<style>(.*?)<\/style>/s', $partial, $m);

        return (string) ($m[1] ?? '');
    }

    /**
     * A valid facebook-size design (price 56px, beds + baths on a dark scrim) plus optional extra blocks.
     *
     * @param  array<int|string, mixed> $extras  block keys (qr, show_day, ribbon, ...) or key => ribbon words
     * @return array<string, mixed>
     */
    private function design(string $name, array $extras = [], bool $land = false): array
    {
        $els = [
            ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 1200, 'h' => 628, 'zIndex' => 1],
            ['field' => 'gradient', 'x' => 0, 'y' => 300, 'w' => 1200, 'h' => 328, 'zIndex' => 2, 'gradFrom' => '#0b1220', 'gradTo' => 'rgba(11,18,32,0)', 'gradAngle' => 0],
            ['field' => 'title', 'x' => 40, 'y' => 440, 'w' => 700, 'h' => 50, 'zIndex' => 3, 'fontSize' => 30, 'fontWeight' => '700', 'color' => '#ffffff'],
            ['field' => 'price', 'x' => 40, 'y' => 500, 'w' => 500, 'h' => 80, 'zIndex' => 3, 'fontSize' => 56, 'fontWeight' => '800', 'color' => '#ffffff'],
            ['field' => 'agent_name', 'x' => 760, 'y' => 540, 'w' => 400, 'h' => 40, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
        ];
        if ($land) {
            $els[] = ['field' => 'erf_size', 'x' => 40, 'y' => 590, 'w' => 300, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'];
        } else {
            $els[] = ['field' => 'beds', 'x' => 40, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'];
            $els[] = ['field' => 'baths', 'x' => 260, 'y' => 590, 'w' => 200, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'];
        }

        $blocks = [
            'qr'            => ['field' => 'qr', 'x' => 1040, 'y' => 20, 'w' => 140, 'h' => 140, 'zIndex' => 4, 'qrDark' => '#000000', 'qrLight' => '#ffffff'],
            'show_day'      => ['field' => 'show_day', 'x' => 480, 'y' => 590, 'w' => 260, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            'feature_pills' => ['field' => 'feature_pills', 'x' => 40, 'y' => 380, 'w' => 500, 'h' => 44, 'zIndex' => 4, 'pillCount' => 3, 'pillDirection' => 'row', 'bgColor' => '#ffffff', 'bgOpacity' => 0.9, 'color' => '#111111', 'fontSize' => 16, 'padding' => 6],
            'collage'       => ['field' => 'collage', 'x' => 600, 'y' => 20, 'w' => 400, 'h' => 260, 'zIndex' => 4, 'collagePreset' => '3_hero_left'],
            'logo'          => ['field' => 'logo', 'x' => 40, 'y' => 20, 'w' => 200, 'h' => 60, 'zIndex' => 4, 'fontSize' => 22, 'color' => '#ffffff', 'shadowOn' => true],
            'agent_contact' => [
                ['field' => 'agent_avatar', 'x' => 1060, 'y' => 440, 'w' => 100, 'h' => 100, 'zIndex' => 4, 'shapeType' => 'circle'],
                ['field' => 'agent_phone', 'x' => 760, 'y' => 590, 'w' => 400, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'],
            ],
            'ribbon'        => ['field' => 'ribbon', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 200, 'zIndex' => 5, 'text' => 'WRONG WORDS', 'fontSize' => 18, 'bgColor' => '#e63946', 'bgOpacity' => 1, 'color' => '#ffffff', 'ribbonCorner' => 'tl'],
        ];
        foreach ($extras as $k => $v) {
            $key = is_int($k) ? $v : $k;
            $one = $blocks[$key];
            foreach ((isset($one['field']) ? [$one] : $one) as $el) {
                if ($key === 'ribbon' && is_string($v) && $k !== 0 && ! is_int($k)) {
                    $el['text'] = $v;
                }
                $els[] = $el;
            }
        }

        return ['name' => $name, 'canvasBgMode' => 'solid', 'canvasBg' => '#0b1220', 'elements' => $els];
    }

    /**
     * Fake Anthropic for the simple cases: every call is answered by $answer(['n' => call number, 'user' => prompt]).
     *
     * @param callable(array): array<int, array<string, mixed>> $answer
     */
    private function fakeAi(callable $answer): object
    {
        $state = new class {
            public array $requests = [];
            public function calls(): int { return count($this->requests); }
            public function userPrompt(int $n): string { return $this->requests[$n]['user']; }
            public function system(int $n): string { return $this->requests[$n]['system']; }
        };
        Http::fake(function ($request) use ($state, $answer) {
            $body = json_decode($request->body(), true);
            $state->requests[] = ['user' => $this->userText($body), 'system' => (string) $body['system']];

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['options' => $answer(['n' => count($state->requests), 'user' => $this->userText($body)])])]],
                'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
            ], 200);
        });

        return $state;
    }

    /**
     * Fake Anthropic that understands both stages: a Defaults call gets three designs (beds + baths + $defaultExtras);
     * a property-type call ("Adapt ...") gets each approved design back adapted to the type named in the prompt
     * (land: no beds/baths, the plot instead; apartment: adds the levy) unless $variant returns something else.
     *
     * @param array<int, string>        $defaultExtras
     * @param ?callable                 $variant     (type, forOption, defaultDesign, round) => raw option | null (= adapt normally)
     * @param ?callable                 $httpFail    (type) => bool, true = that type's call answers HTTP 500
     * @param ?callable                 $afterDefault runs once, right after the Defaults call is answered
     */
    private function smartAi(array $defaultExtras = [], ?callable $variant = null, ?callable $httpFail = null, ?callable $afterDefault = null): object
    {
        $state = new class {
            public array $requests = [];
            public array $rounds = [];
            public function calls(): int { return count($this->requests); }
        };

        Http::fake(function ($request) use ($state, $defaultExtras, $variant, $httpFail, $afterDefault) {
            $body = json_decode($request->body(), true);
            $user = $this->userText($body);
            $state->requests[] = ['user' => $user, 'system' => (string) $body['system']];

            if (! str_starts_with($user, 'Adapt ')) {
                $options = array_map(fn ($n) => $this->design('Design ' . $n, $defaultExtras), [1, 2, 3]);
                if ($afterDefault) {
                    $afterDefault();
                }

                return Http::response([
                    'content' => [['type' => 'text', 'text' => json_encode(['options' => $options])]],
                    'usage'   => ['input_tokens' => 4000, 'output_tokens' => 3000],
                ], 200);
            }

            preg_match('/PROPERTY TYPE[^:]*: (".*?")\n/', $user, $t);
            $type = (string) json_decode($t[1], true);
            if ($httpFail && $httpFail($type)) {
                return Http::response(['error' => 'boom'], 500);
            }
            $state->rounds[$type] = ($state->rounds[$type] ?? 0) + 1;

            preg_match_all('/<design for_option="(\d+)" name="[^"]*">(.*?)<\/design>/s', $user, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $d) {
                $forOption = (int) $d[1];
                $default   = $this->design('Design ' . $forOption, $defaultExtras);
                $custom    = $variant ? $variant($type, $forOption, $default, $state->rounds[$type]) : null;
                if ($custom === null) {
                    $custom = $this->adapt($default, $type);
                }
                $custom['for_option'] = $forOption;
                $out[] = $custom;
            }

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['options' => $out])]],
                'usage'   => ['input_tokens' => 6000, 'output_tokens' => 3500],
            ], 200);
        });

        return $state;
    }

    /** A design adapted to a type the way the AI is asked to: land loses beds/baths and gains the plot; an apartment gains the levy. */
    private function adapt(array $design, string $type): array
    {
        $fields = fn (array $d) => array_column($d['elements'], 'field');
        if (str_contains(strtolower($type), 'land')) {
            $design['elements'] = array_values(array_filter($design['elements'], fn ($e) => ! in_array($e['field'], ['beds', 'baths'], true)));
            $design['elements'][] = ['field' => 'erf_size', 'x' => 40, 'y' => 590, 'w' => 300, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'];
        } elseif (str_contains(strtolower($type), 'apartment')) {
            $design['elements'][] = ['field' => 'levy', 'x' => 480, 'y' => 590, 'w' => 260, 'h' => 30, 'zIndex' => 3, 'fontSize' => 20, 'color' => '#ffffff'];
        }

        return $design;
    }

    private function userText(array $body): string
    {
        $c = $body['messages'][0]['content'];

        return is_array($c) ? (string) collect($c)->firstWhere('type', 'text')['text'] : (string) $c;
    }

    private function builder(): AiTemplateBuilderService
    {
        return app(AiTemplateBuilderService::class);
    }

    /** @param array<int, string> $names */
    private function seedTypes(int $agencyId, array $names, bool $active = true): void
    {
        foreach ($names as $i => $name) {
            DB::table('property_setting_items')->insert([
                'agency_id' => $agencyId, 'group' => 'property_type', 'name' => $name, 'sort_order' => $i,
                'active' => $active, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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

    private function postGenerate(User $user, array $fields)
    {
        $request = Request::create('/api/v1/ad-manager/templates/ai/generate', 'POST', $fields);
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

    private function renderIndex(User $user): string
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates', 'GET', []);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->index($request)->render();
    }
}
