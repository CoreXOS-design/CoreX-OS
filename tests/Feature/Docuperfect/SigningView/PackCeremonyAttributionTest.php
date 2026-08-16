<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use Tests\TestCase;

/**
 * Pack multi-recipient ceremony-value attribution (Johan 2026-07-30).
 *
 * Packs render off merged_html (canonical is empty), so
 * SignatureController::embedCeremonyValuesIntoHtml is the source of truth for the
 * agent-review + final document. It previously mis-parsed "seller_2_location"
 * (explode('_',key,2) -> party "seller") and prefix-matched ("seller" also hit the
 * "seller_2" span), which DROPPED rec 2's Location + dates and MIRRORED rec 1's
 * value onto rec 2 (attribution swap). It now delegates to
 * CanonicalInkComposer::applyCeremonyValues (field-type suffix parse + EXACT
 * data-marker-party), so each recipient's value binds to its OWN span across every
 * pack document, and carries wherever that recipient has a span — none mirrored.
 *
 * Pure string — NO RefreshDatabase (QA1 tree runs the live DB).
 */
final class PackCeremonyAttributionTest extends TestCase
{
    /** Three pack segments, each with seller / seller_2 / agent location + day spans. */
    private function packHtml(): string
    {
        $seg = function (string $doc): string {
            $s = '';
            foreach (['seller', 'seller_2', 'agent'] as $party) {
                foreach (['location', 'day'] as $type) {
                    $s .= '<span data-marker-party="' . $party . '" data-marker-type="' . $type . '">x</span>';
                }
            }
            return "<div class=\"corex-document-wrapper\" data-doc=\"$doc\">$s</div>";
        };
        return $seg('eats') . $seg('mdf') . $seg('addendumb');
    }

    public function test_each_recipients_ceremony_value_binds_to_its_own_span_across_all_pack_docs(): void
    {
        $out = app(SignatureController::class)->embedCeremonyValuesIntoHtml($this->packHtml(), [
            'seller_location'   => 'REC1-LOC',
            'seller_2_location' => 'REC2-LOC',
            'agent_location'    => 'AGENT-LOC',
            'seller_2_day'      => '02',
        ]);

        // rec 1 location + agent location carry to ALL three segments (3 spans each).
        $this->assertSame(3, substr_count($out, 'REC1-LOC'), 'rec 1 Location must carry to every pack doc');
        $this->assertSame(3, substr_count($out, 'AGENT-LOC'), 'agent Location carries to every pack doc');
        // rec 2 location bound (to its own seller_2 spans) — present, not dropped.
        $this->assertStringContainsString('REC2-LOC', $out, 'rec 2 Location must not be dropped');

        // NO SWAP: no seller_2 span carries rec 1's value; no seller span carries rec 2's.
        $this->assertDoesNotMatchRegularExpression(
            '/data-marker-party="seller_2"[^>]*data-marker-type="location"[^>]*>REC1-LOC</',
            $out,
            'rec 1 Location must never mirror onto a seller_2 span',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-marker-party="seller"[^>]*data-marker-type="location"[^>]*>REC2-LOC</',
            $out,
            'rec 2 Location must never land on a seller (rec 1) span',
        );

        // Multi-underscore date key parses correctly (was mangled by explode).
        $this->assertMatchesRegularExpression(
            '/data-marker-party="seller_2"[^>]*data-marker-type="day"[^>]*>02</',
            $out,
            'seller_2 date must bind (field-type suffix parse)',
        );
    }
}
