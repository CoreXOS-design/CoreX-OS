<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Tests\TestCase;

/**
 * REGRESSION (Johan 2026-08-06) — initialling an AMENDMENT must not wipe the already-applied document
 * initials.
 *
 * Repro: on the signing view the agent/recipient initials the WHOLE document ("apply to all" fills every
 * field-initial), THEN clicks to initial an amendment (a strike/change block). The document RESET and every
 * applied field-initial vanished — only the amendments remained.
 *
 * Root cause: the field initials from "apply to all" are applied CLIENT-SIDE only (painted into the DOM /
 * held in web-sig state) and are not persisted server-side until final submit. The amendment-initial handler
 * (`_isChangeInitial` branch of applySignature) called `window.location.reload()` after POSTing the initial —
 * re-fetching the server document, which has NO record of those in-progress field initials, so they were
 * wiped. The change-initial itself is persisted server-side by recordChangeInitial, so the reload was only
 * ever to reflect it.
 *
 * Fix: the amendment-initial branch must NOT reload; it paints the captured initial into its slot in place
 * (`_paintChangeInitialSlot`) so the change-initial shows while every already-applied field initial + signature
 * stays exactly as it is. This test guards BOTH signing blades (internal agent + external recipient) against
 * a reintroduced reload — a client-side behaviour that no server-side unit test can exercise.
 */
final class AmendmentInitialPreservesFieldInitialsTest extends TestCase
{
    /** @return array<string,string> */
    private function views(): array
    {
        $base = base_path('resources/views/docuperfect/signatures');

        return [
            'internal agent signing view' => $base . '/sign.blade.php',
            'external recipient signing view' => $base . '/external/sign.blade.php',
        ];
    }

    /** The `_isChangeInitial` branch of applySignature — the amendment-initial handler. */
    private function changeInitialBranch(string $src): string
    {
        $start = strpos($src, 'if (this.activeMarker._isChangeInitial) {');
        $this->assertNotFalse($start, 'the _isChangeInitial branch must exist');
        // The branch ends at its `return;` — capture from the branch start THROUGH that return so the whole
        // handler (including the comment + the in-place paint call) is in the window.
        $ret = strpos($src, 'return;', (int) $start);
        $this->assertNotFalse($ret, 'the _isChangeInitial branch must end in a return');

        return substr($src, (int) $start, ($ret - (int) $start) + 20);
    }

    public function test_amendment_initial_handler_does_not_reload_the_page(): void
    {
        foreach ($this->views() as $label => $path) {
            $this->assertFileExists($path, "$label blade must exist");
            $branch = $this->changeInitialBranch((string) file_get_contents($path));

            $this->assertStringNotContainsString(
                'window.location.reload()',
                $branch,
                "$label: initialling an amendment must NOT reload the page — a reload wipes the client-side "
                . 'field initials applied via "apply to all".'
            );
        }
    }

    public function test_amendment_initial_handler_paints_the_slot_in_place(): void
    {
        foreach ($this->views() as $label => $path) {
            $branch = $this->changeInitialBranch((string) file_get_contents($path));

            $this->assertStringContainsString(
                '_paintChangeInitialSlot(',
                $branch,
                "$label: the amendment-initial must be reflected by painting the slot in place, preserving "
                . 'every already-applied initial.'
            );
        }
    }

    public function test_both_views_define_the_in_place_paint_helper(): void
    {
        foreach ($this->views() as $label => $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString(
                '_paintChangeInitialSlot(changeId, partyKey, imageDataUrl)',
                $src,
                "$label: must define the _paintChangeInitialSlot helper that mirrors the server-rendered filled slot."
            );
            // The helper must mark the slot filled and write the captured initial image (the server's render).
            $this->assertMatchesRegularExpression('/cir-filled/', $src, "$label: painted slot must be marked filled");
        }
    }

    /**
     * CREATE-AMENDMENT path (Johan 2026-08-06). Same bug class: creating a NEW amendment at signing time
     * (`selectionEditor.submit` in the agent view) used to `window.location.reload()` after the server saved
     * the change — which wiped the in-progress field initials/signatures exactly like the amendment-initial
     * did. The submit must instead paint the new amendment in place (dispatch `corex-amendment-created` →
     * `_paintNewAmendment` renders the struck mark + the per-party initial row at the captured selection),
     * preserving every applied initial. The recipient (external) view has no amendment-creation UI yet
     * (upcoming), so this guard is scoped to the agent view where the path exists.
     */
    private function agentSubmitRegion(): string
    {
        $src = (string) file_get_contents(base_path('resources/views/docuperfect/signatures/sign.blade.php'));
        $start = strpos($src, 'async submit() {');
        $this->assertNotFalse($start, 'the selectionEditor.submit handler must exist');
        // Through the end of the try/catch that POSTs the amendment (the reload used to live here).
        $anchor = strpos($src, "this.err = 'Network error", (int) $start);
        $this->assertNotFalse($anchor, 'submit must end in its network-error catch');

        return substr($src, (int) $start, ($anchor - (int) $start) + 40);
    }

    public function test_creating_an_amendment_does_not_reload_the_page(): void
    {
        $region = $this->agentSubmitRegion();
        $this->assertStringNotContainsString(
            'window.location.reload()',
            $region,
            'agent create-amendment: saving a new amendment must NOT reload — a reload wipes the client-side '
            . 'field initials/signatures applied via "apply to all".'
        );
    }

    public function test_creating_an_amendment_paints_it_in_place(): void
    {
        $region = $this->agentSubmitRegion();
        $this->assertStringContainsString(
            "corex-amendment-created",
            $region,
            'agent create-amendment: the saved amendment must be painted in place (dispatch corex-amendment-created).'
        );

        $src = (string) file_get_contents(base_path('resources/views/docuperfect/signatures/sign.blade.php'));
        $this->assertStringContainsString('_paintNewAmendment(detail)', $src, 'must define the in-place amendment painter');
        $this->assertStringContainsString('corex-amendment-created', $src, 'must listen for the created-amendment event');
        // The painter renders the canonical change-mark + the per-party initial row (no reload).
        $this->assertStringContainsString("change-initial-row", $src, 'painter must render the per-party initial row');
        $this->assertStringContainsString("change-inline", $src, 'painter must render the struck change-mark wrapper');
    }
}
