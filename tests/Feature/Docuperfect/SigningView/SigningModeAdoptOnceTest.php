<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md §1 (Johan's ruling, 26 Oct 2026): two choices - sign each place, or "adopt once" (adopt a signature and
 * initial, then click each place and/or press "Apply to all my places"). A click or an apply-to-all with no adoption is answered
 * with a signal that opens the adopt popup (never a red block or a silent failure); the sticky header carries the model in plain
 * words and live progress; the page works on a phone.
 */
final class SigningModeAdoptOnceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const INIT = 'data:image/png;base64,iVBORw0KGgoCCCC=';
    private const PHONE = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function seller(string $mode, bool $adopted = true): SignatureRequest
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        if ($adopted) {
            $this->postJson('/sign/' . $r->token . '/adopt', ['signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true])->assertOk();
        }

        return $r;
    }

    // ── click before adopting ──

    public function test_a_click_on_a_place_with_no_adoption_is_answered_with_the_adopt_signal_and_writes_nothing(): void
    {
        $r = $this->seller('adopt_once', adopted: false);

        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])
            ->assertStatus(422)->assertJsonPath('adoption_required', true)->assertJsonPath('ok', false);
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-init-0'])
            ->assertStatus(422)->assertJsonPath('adoption_required', true);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_after_adopting_the_same_click_applies(): void
    {
        $r = $this->seller('adopt_once', adopted: false);
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertStatus(422);

        $this->postJson('/sign/' . $r->token . '/adopt', ['signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true])->assertOk();
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, SignaturePlaceGeneration::count());
    }

    // ── apply to all needs BOTH marks ──

    public function test_apply_to_all_with_no_adoption_is_refused_with_the_adopt_signal(): void
    {
        $r = $this->seller('adopt_once', adopted: false);

        $this->postJson('/sign/' . $r->token . '/place-all', ['place_keys' => ['seller-sig-0', 'seller-init-0'], 'confirmed' => true])
            ->assertStatus(422)->assertJsonPath('adoption_required', true);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_apply_to_all_with_only_one_mark_on_record_is_refused_and_nothing_is_filled(): void
    {
        $r = $this->seller('adopt_once');
        // An adoption missing its initial (cannot be written through the endpoint, so force the row): never a half-adopted fill.
        SignatureAdoption::where('signature_request_id', $r->id)->update(['initial_image' => '']);

        $this->postJson('/sign/' . $r->token . '/place-all', ['place_keys' => ['seller-sig-0', 'seller-init-0'], 'confirmed' => true])
            ->assertStatus(422)->assertJsonPath('adoption_required', true);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_a_click_and_apply_to_all_work_on_the_same_envelope(): void
    {
        $r = $this->seller('adopt_once');

        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk();
        $res = $this->postJson('/sign/' . $r->token . '/place-all', ['place_keys' => ['seller-sig-0', 'seller-init-0', 'seller-init-1'], 'confirmed' => true, 'one_by_one' => 0])->assertOk();

        $this->assertContains('seller-sig-0', $res->json('already'), 'the place already clicked is left exactly as it is');
        $this->assertSame(3, SignaturePlaceGeneration::count());
        $this->assertSame(1, SignaturePlaceGeneration::where('method', 'stamped_click')->count());
        $this->assertSame(2, SignaturePlaceGeneration::where('method', 'stamped_auto')->count());
    }

    // ── legacy values ──

    public function test_both_retired_mode_values_behave_as_adopt_once(): void
    {
        foreach (['adopt_then_stamp', 'adopt_and_fill_all'] as $legacy) {
            $r = $this->seller($legacy);
            $html = $this->asRecipient($r)->assertOk()->getContent();

            $this->assertStringContainsString('"required":true', str_replace(' ', '', $html), $legacy);
            $this->assertStringContainsString('"mode":"adopt_once"', str_replace(' ', '', $html), $legacy . ' is reported to the page as adopt_once');
            $this->assertStringContainsString('@click="confirmFillAll()"', $html, $legacy . ' offers apply to all');
            $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk();
        }
    }

    // ── the sticky header ──

    public function test_the_header_is_sticky_and_shows_the_model_and_progress_for_adopt_once(): void
    {
        $r = $this->seller('adopt_once');
        $html = $this->asRecipient($r)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-signing-header[^>]*position:sticky/', $html);
        $this->assertStringContainsString('Adopt once, click to apply or apply to all', $html);
        $this->assertStringContainsString('data-header-progress', $html);
        $this->assertStringContainsString('data-header-apply-all', $html);
        $this->assertStringContainsString('data-header-finish', $html);
        $this->assertStringContainsString('data-header-blocker', $html);
    }

    public function test_the_header_says_sign_each_place_for_the_default_mode_and_offers_no_adoption_actions_there(): void
    {
        $r = $this->seller('sign_each', adopted: false);
        $html = $this->asRecipient($r)->assertOk()->getContent();

        $this->assertStringContainsString('Sign each place', $html);
        $this->assertStringContainsString('data-header-progress', $html);
        $this->assertStringContainsString('"required":false', str_replace(' ', '', $html));
        // the buttons are conditional on the adoption state the server sent; with none required they never show
        $this->assertStringContainsString('x-show="adoption && adoption.required"', $html);
    }

    public function test_the_page_binds_each_places_click_once_and_opens_the_popup_for_an_unadopted_click(): void
    {
        $r = $this->seller('adopt_once', adopted: false);
        $html = $this->asRecipient($r)->assertOk()->getContent();

        $this->assertStringContainsString('_corexClickBound', $html);          // one handler per place (a second made a click stamp, then ask to take it back)
        $this->assertStringContainsString('_needAdoption(', $html);            // no adoption: the popup opens and the click is remembered
        $this->assertStringContainsString('pendingAdoptAction', $html);        // ...and applied right after adopting
        $this->assertStringContainsString('adoption_required', $html);         // the server's signal is handled too
    }

    // ── mobile ──

    public function test_the_page_renders_for_a_phone_with_the_compact_header(): void
    {
        $r = $this->seller('adopt_once', adopted: false);
        $html = $this->withHeaders(self::PHONE)->get('/sign/' . $r->token)->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('data-signing-header', $html);
        $this->assertStringContainsString('sm:hidden', $html);                 // phone-only short labels
        $this->assertStringContainsString('Apply to all', $html);
        $this->assertStringContainsString('min-height:40px', $html);            // tap targets
    }
}
