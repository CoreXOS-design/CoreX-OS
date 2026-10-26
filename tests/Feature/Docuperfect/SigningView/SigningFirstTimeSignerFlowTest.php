<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md §13 — the recipient signing page as a first-time signer meets it: ONE sticky header (document, who is
 * signing, one status line "Step N of 3: …" with the one progress figure, one next button, Finish), three steps, plain words,
 * no floating bar, the wording-changes panel closed until asked for.
 *
 * The step logic itself runs in the browser (it is derived from what is on the page); these tests pin everything the server
 * hands the page - the markup, the wording, the state it is given - plus the absence of the old jargon. The step progression is
 * walked in a real browser by scripts/esign/first-time-flow-proof.cjs.
 */
final class SigningFirstTimeSignerFlowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const PHONE = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function seller(string $mode, bool $pack = false): SignatureRequest
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();

        if ($pack) {
            $a = DocuperfectTemplate::create(['name' => 'Sole Mandate', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
            $b = DocuperfectTemplate::create(['name' => 'Disclosure Form', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
            $marker = '<p data-marker-party="seller" data-marker-type="signature" data-recipient-identity="' . $r->attestationIdentity() . '">x</p>';
            $document = $session['document'];
            $data = $document->web_template_data ?? [];
            $data['canonical_html'] = '<div class="corex-document-wrapper">' . $marker . '</div><div class="corex-document-wrapper">' . $marker . '</div>';
            $data['template_ids'] = [$a->id, $b->id];
            $document->update(['web_template_data' => $data]);
        }

        return $r->fresh();
    }

    /** What a signer can actually read: text nodes plus the quoted text inside x-text / title / placeholder - never script, style or identifiers. */
    private function visibleText(string $html): string
    {
        $html = preg_replace('#<script\b.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b.*?</style>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);

        $literals = [];
        preg_match_all('/(?:x-text|:title)="([^"]*)"/i', $html, $expr);
        foreach ($expr[1] as $e) {
            preg_match_all("/'([^']*)'/", html_entity_decode($e), $q);
            $literals = array_merge($literals, $q[1]);
        }
        preg_match_all('/\b(?:title|placeholder|aria-label|alt)="([^"]*)"/i', $html, $plain);

        return html_entity_decode(strip_tags($html)) . ' ' . implode(' ', $literals) . ' ' . html_entity_decode(implode(' ', $plain[1]));
    }

    public static function modes(): array
    {
        return [
            'sign each place, single' => ['sign_each', false],
            'adopt once, single' => ['adopt_once', false],
            'sign each place, pack' => ['sign_each', true],
            'adopt once, pack' => ['adopt_once', true],
        ];
    }

    // ── header ──

    #[DataProvider('modes')]
    public function test_the_header_has_one_status_line_one_next_button_and_finish(string $mode, bool $pack): void
    {
        $r = $this->seller($mode, $pack);
        $html = $this->asRecipient($r)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-signing-header[^>]*position:sticky/', $html);
        $this->assertStringContainsString('Signing as ' . $r->signer_name . ' (', $html);
        $this->assertSame(1, substr_count($html, 'data-header-status'));
        $this->assertSame(1, substr_count($html, 'data-header-next'));
        $this->assertSame(1, substr_count($html, 'data-header-finish'));
        $this->assertSame(1, substr_count($html, 'data-header-progress'));
        $this->assertStringContainsString(":disabled=\"completing || !canSubmitWeb\"", $html, 'Finish is disabled until everything is done');
        $this->assertStringContainsString(':title="finishTitle"', $html, 'hovering Finish says what is missing');
    }

    #[DataProvider('modes')]
    public function test_the_floating_bar_and_the_competing_counters_are_gone(string $mode, bool $pack): void
    {
        $html = $this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent();

        $text = $this->visibleText($html);
        foreach (['items remaining', 'item remaining', 'Go to next', 'Ready to submit', ' left — next:', 'places done'] as $old) {
            $this->assertStringNotContainsString($old, $text, "'{$old}' belonged to the old counters");
        }
        $this->assertStringNotContainsString("webIncompleteCount + ' item", $html);
        $this->assertStringContainsString("_setIfChanged('progressText', this.signedCount + ' of ' + this.totalRequired + ' done')", $html, 'one counter, one source');
        // the header and the left column both read that one figure
        $this->assertSame(1, substr_count($html, 'data-rail-progress'));
        $this->assertSame(2, substr_count($html, 'x-text="progressText"'));
    }

    // ── steps ──

    public function test_the_three_steps_and_their_wording_are_on_the_page(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();

        $this->assertStringContainsString("'Step ' + this.stepNo + ' of 3: ' + status", $html);
        $this->assertStringContainsString('Add your signature and initials', $html);
        $this->assertStringContainsString("How you'll sign", $html);
        $this->assertStringContainsString('Check your details', $html);
        $this->assertStringContainsString("_setIfChanged('primaryLabel', this.stepNo === 1 ? 'Start'", $html);
        $this->assertStringContainsString("'Next'", $html);
        $this->assertStringContainsString('Sign everything', $html);
        $this->assertStringContainsString('Finish', $html);
    }

    public function test_adopt_once_shows_the_save_step_and_sign_everything_while_sign_each_shows_how_youll_sign(): void
    {
        $once = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();
        $this->assertStringContainsString('"required":true', str_replace(' ', '', $once));
        $this->assertStringContainsString('Add your signature and initials</h2>', $once, 'the popup is retitled');
        $this->assertStringContainsString('@click="confirmFillAll()"', $once);
        $this->assertStringContainsString('data-header-sign-everything', $once);
        $this->assertStringContainsString('x-show="adoption && adoption.required && stepNo === 3 && !fillAllUnavailable"', $once, 'Sign everything only in step 3 of an adopt-once agency');
        $this->assertStringContainsString('Change my signature', $once);
        $this->assertStringContainsString('x-show="adoption && adoption.required && adoption.adopted"', $once, 'Change my signature only once one exists');

        $each = $this->asRecipient($this->seller('sign_each'))->assertOk()->getContent();
        $this->assertStringContainsString('"required":false', str_replace(' ', '', $each));
        $this->assertStringNotContainsString('Add your signature and initials</h2>', $each, 'a sign-each agency has no save popup');
        $this->assertStringNotContainsString('@click="confirmFillAll()"', $each, 'and no Sign everything confirmation');
        $this->assertStringContainsString("You'll sign each spot by hand as you go", html_entity_decode($each, ENT_QUOTES));
    }

    public function test_a_pack_names_its_documents_to_the_status_line_in_both_modes(): void
    {
        foreach (['sign_each', 'adopt_once'] as $mode) {
            $html = $this->asRecipient($this->seller($mode, pack: true))->assertOk()->getContent();

            $this->assertStringContainsString('Sole Mandate', $html, $mode);
            $this->assertStringContainsString('Disclosure Form', $html, $mode);
            $this->assertStringContainsString("' — Document ' + (i + 1) + ' of ' + info.docs.length + ' — ' + d.name", $html, $mode . ': "Document 1 of 2 — <name>"');
        }
        // a single document has no document line
        $single = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();
        $this->assertStringContainsString('"documents":[]', str_replace(' ', '', $single));
    }

    // ── words ──

    #[DataProvider('modes')]
    public function test_no_jargon_in_anything_the_signer_can_read(string $mode, bool $pack): void
    {
        $text = $this->visibleText($this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression('/\b(adopt\w*|marks|places|stamp\w*)\b/i', $text);
    }

    public function test_the_server_messages_a_signer_can_see_use_the_same_words(): void
    {
        $r = $this->seller('adopt_once');
        $res = $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertStatus(422);
        $this->assertDoesNotMatchRegularExpression('/\b(adopt\w*|marks|places|stamp\w*)\b/i', (string) $res->json('error'));
        $this->assertSame(true, $res->json('adoption_required'));

        $all = $this->postJson('/sign/' . $r->token . '/place-all', ['place_keys' => ['seller-sig-0'], 'confirmed' => true])->assertStatus(422);
        $this->assertStringContainsString('signature and initials', (string) $all->json('error'));
    }

    // ── left column, help, wording changes, fields ──

    public function test_the_left_column_is_three_steps_and_one_help_line_not_an_essay(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();

        $this->assertStringContainsString('Your three steps', $html);
        $this->assertStringContainsString('data-steps-rail', $html);
        $this->assertStringContainsString('Questions? Call', $html);
        $text = $this->visibleText($html);
        foreach (['How to sign', 'Review the document', 'Amend the wording', 'Initial each page', 'Sign at the bottom', 'Need help?'] as $old) {
            $this->assertStringNotContainsString($old, $text, "'{$old}' belonged to the old five-paragraph column");
        }
    }

    public function test_the_wording_changes_panel_is_closed_until_asked_for(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();

        $this->assertStringContainsString('Need to change the wording?', $html);
        $this->assertStringContainsString('data-amend-link', $html);
        $this->assertStringContainsString('data-amend-panel', $html);
        $this->assertMatchesRegularExpression('/showAmend:\s*false/', $html, 'closed by default when the document carries no changes');
        $this->assertStringContainsString('x-show="showAmend"', $html);
    }

    public function test_details_to_type_say_type_here_and_filled_ones_look_plain(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();

        $this->assertStringContainsString("input.placeholder = 'Type here'", $html);
        $this->assertStringContainsString('field-empty', $html);
        $this->assertStringContainsString('field-filled', $html);
        $this->assertStringContainsString('data-details-to-fill', $html);
        $this->assertStringContainsString("' to fill in'", $html);
    }

    // ── phone ──

    #[DataProvider('modes')]
    public function test_the_page_renders_for_a_phone_with_the_same_header_and_the_sheet_contained(string $mode, bool $pack): void
    {
        $html = $this->withHeaders(self::PHONE)->get('/sign/' . $this->seller($mode, $pack)->token)->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('data-signing-header', $html);
        $this->assertStringContainsString('data-header-status', $html);
        $this->assertStringContainsString('min-width: 0 !important', $html, 'the A4 sheet scrolls inside its own box instead of pushing the header off the screen');
        $this->assertStringContainsString('min-height:40px', $html);
    }

    // ── no freeze: no native pop-ups, no self-feeding recount ──

    #[DataProvider('modes')]
    public function test_the_page_never_opens_a_native_browser_dialog(string $mode, bool $pack): void
    {
        $html = $this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent();
        $code = preg_replace('#<!--.*?-->#s', '', $html);

        // A native alert / confirm / prompt blocks the whole tab and every automation tool with it (the 09 Oct no-pop-ups rule).
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$.])(?:window\.)?(?:alert|confirm|prompt)\s*\(/', $code);
        // ...so the page carries its own dialog instead.
        $this->assertStringContainsString('data-app-dialog', $html);
        $this->assertStringContainsString('askConfirm(', $html);
    }

    public function test_the_recount_cannot_feed_itself(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once', pack: true))->assertOk()->getContent();

        $this->assertStringContainsString('if (this._refreshing) return;', $html, 'a recount never re-enters itself');
        $this->assertStringContainsString("_setIfChanged('detailsToFill'", $html, 'an unchanged list is not re-assigned (it would re-render the left column every recount)');
        $this->assertStringContainsString("_setIfChanged('headerBlocker'", $html);
    }

    // ── cold load: the page never waits on another company's server ──

    #[DataProvider('modes')]
    public function test_the_page_pulls_in_no_third_party_stylesheet_that_could_block_it(string $mode, bool $pack): void
    {
        $html = $this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent();

        // A render-blocking <link rel="stylesheet"> to another host holds the first paint AND the page scripts until that host answers
        // (two Google Fonts links did: a 25s blank page on a slow cold load).
        $this->assertDoesNotMatchRegularExpression('#<link[^>]+rel="stylesheet"[^>]*https?://(?!' . preg_quote(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost', '#') . ')#i', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringContainsString('/css/esign-fonts.css', $html);
    }

    public function test_the_self_hosted_font_is_really_there_and_swaps_in(): void
    {
        $css = (string) file_get_contents(public_path('css/esign-fonts.css'));

        $this->assertStringContainsString("font-family: 'Dancing Script'", $css);
        $this->assertStringContainsString('font-display: swap', $css, 'text shows at once, the font swaps in when it arrives');
        preg_match_all("#url\\('(/fonts/[^']+)'\\)#", $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            $this->assertFileExists(public_path($url));
            $this->assertSame('wOF2', substr((string) file_get_contents(public_path($url), false, null, 0, 4), 0, 4), $url . ' is a woff2 font');
        }
    }

    public function test_the_page_starts_up_once_not_twice(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once'))->assertOk()->getContent();

        // Alpine calls init() itself and the root also says x-init="init()": the guard makes the second call a no-op.
        $this->assertStringContainsString('if (this._inited) return;', $html);
        $this->assertStringContainsString('this._inited = true;', $html);
    }

    // ── initials dialog: every word follows what is being captured (Johan 26 Oct, defect 1) ──

    #[DataProvider('modes')]
    public function test_the_initials_dialog_says_initials_not_signature(string $mode, bool $pack): void
    {
        $html = $this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent();
        $text = $this->visibleText($html);

        foreach (['Your initials', 'Type your initials', 'Apply initials', 'Your typed initials appear here', 'Draw your initials above'] as $s) {
            $this->assertStringContainsString($s, $text, "the initials dialog must say '{$s}'");
        }
        $this->assertStringContainsString('By adding your initials, you confirm they are yours', $html, 'the footer is about initials, not "By signing"');

        // ...and each of those is chosen by the same "is this an initial?" test the place that opened the dialog set, so a signature still reads as a signature.
        $html = html_entity_decode($html, ENT_QUOTES);   // Blade writes the quotes inside an attribute as entities; the browser reads them back
        $isInitial = "(currentWebSigBlockId || '').indexOf('-init-') !== -1";
        $this->assertStringContainsString("x-text=\"(" . $isInitial . ") ? 'Your initials' : ('Sign Here')\"", $html);
        $this->assertStringContainsString("(" . $isInitial . ") ? 'Type your initials' : 'Type your full name here'", $html);
        $this->assertStringContainsString("(" . $isInitial . ") ? 'Apply initials' : 'Apply Signature'", $html);
        $this->assertStringContainsString("(" . $isInitial . ") ? 'Your typed initials appear here' : 'Your typed signature appears here'", $html);
        $this->assertStringContainsString('By signing, you confirm your identity', $html, 'a signature keeps its own wording');
        $this->assertStringContainsString('Apply Signature', $html);
    }

    public function test_the_marker_dialog_gets_the_initials_wording_too(): void
    {
        $modal = file_get_contents(resource_path('views/docuperfect/signatures/partials/_capture-modal.blade.php'));
        $this->assertStringContainsString("activeMarker && activeMarker.type === 'initial'", $modal);
        foreach (['Your initials', 'Type your initials', 'Apply initials', 'Your typed initials appear here', 'Draw your initials above', 'Use my saved initials'] as $s) {
            $this->assertStringContainsString($s, $modal);
        }
        $this->assertStringNotContainsString('Type your initials here', $modal, 'the old half-reused label is gone');
    }

    // ── a pack is viewed document by document (Johan 26 Oct, defect 2) ──

    public function test_a_pack_takes_the_signer_to_every_document_before_finish(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once', pack: true))->assertOk()->getContent();

        // Next opens the next unseen document once nothing is left to sign in the ones already seen.
        $this->assertStringContainsString('_openDoc(i, info)', $html);
        $this->assertStringContainsString("if (u !== -1) this._openDoc(u, info);", $html);
        $this->assertStringContainsString("scrollIntoView({ behavior: 'smooth', block: 'start' })", $html, 'the next document is shown from its top');
        // the status line names it, and says so before it is opened
        $this->assertStringContainsString("'Press Next to open ' + this._docTitle(unviewed, info)", $html);
        $this->assertStringContainsString("blocker = { text: 'Next: open ' + this._docTitle(unviewed, info), kind: 'view' }", $html);
        // the Next button stays while a document is unseen, even when every place is already signed (Sign everything)
        $this->assertStringContainsString("(this.stepNo === 3 && here.length === 0 && unviewed === -1) ? '' : 'Next'", $html);
        // Sign everything is not a view: only a click on a place or Next marks a document as seen
        $this->assertStringContainsString('self._noteDocTouched(el);', $html);
        $this->assertSame(2, substr_count($html, 'self._noteDocTouched(el);'), 'signature places and initial places');
        // Finish and the tick wait for the last document, and cannot be reached by any other path
        $this->assertStringContainsString("_setIfChanged('showFinish', unviewed === -1)", $html);
        $this->assertStringContainsString('x-show="showFinish"', $html);
        $this->assertStringContainsString('this.unviewedDoc === -1', $html, 'canSubmitWeb also requires every document to have been reached');
    }

    public function test_the_sign_everything_confirmation_does_not_skip_the_viewing(): void
    {
        $html = $this->asRecipient($this->seller('adopt_once', pack: true))->assertOk()->getContent();
        $this->assertStringContainsString("_stepThreeWork(items, info)", $html);
        $this->assertStringNotContainsString('viewedDocs = {}; ', $html);
        // confirmFillAll never marks a document as seen
        $this->assertDoesNotMatchRegularExpression('/confirmFillAll\([^)]*\)\s*\{(?:(?!\n        \},).)*_markViewed/s', $html);
    }

    // ── one finish control (Johan 26 Oct, defect 3) ──

    #[DataProvider('modes')]
    public function test_there_is_one_finish_and_the_bottom_submit_button_is_gone(string $mode, bool $pack): void
    {
        $html = $this->asRecipient($this->seller($mode, $pack))->assertOk()->getContent();

        $this->assertStringNotContainsString('Submit Signed Document', $html);
        $this->assertSame(1, substr_count($html, 'data-header-finish'));
        $this->assertSame(1, substr_count($html, 'id="consent-checkbox"'), 'one tick');
        $this->assertSame(1, substr_count($html, 'id="consent-checkbox-label"'));
        $this->assertSame(1, substr_count($html, 'data-header-consent'));
        // the tick sits in the sticky header, before the page body
        $header = strpos($html, 'data-signing-header');
        $tick = strpos($html, 'id="consent-checkbox"');
        $finish = strpos($html, 'data-header-finish');
        $this->assertGreaterThan($header, $tick);
        $this->assertLessThan($finish + 5000, $tick, 'tick and Finish are next to each other');
        $this->assertLessThan(strpos($html, 'data-app-dialog'), $finish, 'both are inside the header, ahead of the document');
        // the legal wording is still there, one press away
        $this->assertStringContainsString('as a handwritten signature under South African law (ECTA Section 13)', $html);
        // the bottom keeps only the decline link
        $this->assertSame(1, substr_count($html, 'I decline to sign this document'));
    }
}

