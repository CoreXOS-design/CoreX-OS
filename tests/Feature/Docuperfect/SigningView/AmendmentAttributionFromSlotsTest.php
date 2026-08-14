<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Services\Docuperfect\DocumentChangeHighlighter;
use Tests\TestCase;

/**
 * Render-boundary attribution fix (Johan/cc2 flag 2026-08-06). `recordChangeInitial` writes the
 * `change_initials` map FLAT — change_initials[cid] = {name}, last-writer-wins — so on a multi-party change
 * it holds only the LAST party to initial (doc 705 rendered "Andre Roets" for BOTH changes). The
 * authoritative per-party record is the FILLED cir-slots (cc2 keeps each party's fill in canonical_html +
 * merged_html). The Schedule of Amendments + the inline "Initialed by" tag must attribute from the cir-slots,
 * never from the flat map.
 */
final class AmendmentAttributionFromSlotsTest extends TestCase
{
    private function docWithTwoInitialedSlots(string $cid): string
    {
        // A wet-ink amendment (struck + reword) whose initial row has TWO filled cir-slots: agent + seller.
        return '<div class="corex-document-wrapper">'
            . '<p class="corex-clause" data-change-id="' . $cid . '">Fee '
            . '<span class="change-inline" data-strikethrough-applied="1" data-change-id="' . $cid . '">'
            . '<del class="change-del" data-change-id="' . $cid . '">seven percent</del> '
            . '<ins class="change-ins" data-change-id="' . $cid . '">six percent</ins></span> of the price.</p>'
            . '<div class="change-initial-row" data-change-id="' . $cid . '">'
            . '<span class="cir-slot cir-filled" data-change-id="' . $cid . '" data-party-key="agent" data-party-name="Esign Verify">'
            . '<span class="cir-name">Esign Verify</span><span class="cir-ink"><img src="data:image/png;base64,AAAA"></span></span>'
            . '<span class="cir-slot cir-filled" data-change-id="' . $cid . '" data-party-key="seller" data-party-name="Anine Van der Westhuizen">'
            . '<span class="cir-name">Anine Van der Westhuizen</span><span class="cir-ink"><img src="data:image/png;base64,AAAA"></span></span>'
            . '</div></div>';
    }

    public function test_schedule_attributes_all_filled_slot_parties_not_the_flat_last_writer(): void
    {
        $cid = 'abc123def456';
        $html = $this->docWithTwoInitialedSlots($cid);
        // The FLAT change_initials names a DIFFERENT single party (simulating last-writer-wins).
        $flat = [$cid => ['name' => 'Andre Roets', 'at' => '2026-08-06T10:00:00+02:00']];

        $out = app(DocumentChangeHighlighter::class)->highlight($html, '', $flat, [], []);

        // The Schedule of Amendments renders.
        $this->assertStringContainsString('Schedule of Amendments', $out);
        // Attribution comes from the FILLED cir-slots — BOTH parties, not the flat last-writer.
        $this->assertStringContainsString('Esign Verify', $out, 'the agent slot party is attributed');
        $this->assertStringContainsString('Anine Van der Westhuizen', $out, 'the seller slot party is attributed');
        // The flat map name (a party who did NOT fill a slot here) must NOT be shown as the initialer.
        $this->assertStringNotContainsString('Andre Roets', $out, 'the flat last-writer name must not attribute the change');
    }

    public function test_falls_back_to_flat_map_when_no_filled_slots(): void
    {
        // A legacy pill-only mark: a change with NO cir-slots at all → attribution falls back to the flat map.
        $cid = 'fee0feed1234';
        $html = '<div class="corex-document-wrapper"><p class="corex-clause" data-change-id="' . $cid . '">Fee '
            . '<span class="change-inline" data-strikethrough-applied="1" data-change-id="' . $cid . '">'
            . '<del class="change-del" data-change-id="' . $cid . '">seven</del> '
            . '<ins class="change-ins" data-change-id="' . $cid . '">six</ins></span>.</p></div>';
        $flat = [$cid => ['name' => 'Solo Signer', 'at' => '2026-08-06T10:00:00+02:00']];

        $out = app(DocumentChangeHighlighter::class)->highlight($html, '', $flat, [], []);
        $this->assertStringContainsString('Solo Signer', $out, 'a slot-less change still attributes from the flat map');
    }
}
