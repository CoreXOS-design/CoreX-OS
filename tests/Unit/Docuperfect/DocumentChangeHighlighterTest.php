<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect;

use App\Services\Docuperfect\DocumentChangeHighlighter;
use Tests\TestCase;

/**
 * AT-368 — locks the returned-doc wet-ink change-highlight render contract. Pure (no DB): the highlighter
 * only needs the app container (RoleBlockDetectionService + Log). Every change class + the persistence
 * signals (appendix, per-change initialed tag, fail-safe) are asserted here so the render contract can
 * never silently regress. See .ai/specs/esign-returned-doc-change-highlight.md.
 */
final class DocumentChangeHighlighterTest extends TestCase
{
    private function h(): DocumentChangeHighlighter
    {
        return app(DocumentChangeHighlighter::class);
    }

    private function changeId(string $key, string $old, string $new): string
    {
        return substr(sha1($key . '|' . $old . '|' . $new), 0, 12);
    }

    public function test_field_value_change_renders_struck_old_plus_inline_new(): void
    {
        $base = '<div class="doc"><span data-field="commission_percent">7.5</span></div>';
        $cur  = '<div class="doc"><span data-field="commission_percent">10</span></div>';
        $out = $this->h()->highlight($cur, $base);

        $this->assertMatchesRegularExpression(
            '/data-field="commission_percent"[^>]*>\s*<del class="change-del">7.5<\/del>\s*<ins class="change-ins"[^>]*>10<\/ins>/',
            $out
        );
    }

    public function test_field_delete_only_strikes_with_no_insert(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><span data-field="occupation_date"></span></div>',
            '<div class="doc"><span data-field="occupation_date">2026-01-01</span></div>'
        );
        $this->assertStringContainsString('data-field="occupation_date"><del class="change-del">2026-01-01</del>', $out);
        $this->assertDoesNotMatchRegularExpression('/data-field="occupation_date">[^<]*<ins/', $out);
    }

    public function test_field_add_only_inserts_with_no_strike(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><span data-field="note">Urgent</span></div>',
            '<div class="doc"><span data-field="note"></span></div>'
        );
        $this->assertStringContainsString('data-field="note"><ins class="change-ins"', $out);
        $this->assertStringContainsString('>Urgent</ins>', $out);
        $this->assertDoesNotMatchRegularExpression('/data-field="note">[^<]*<del/', $out);
    }

    public function test_small_clause_change_is_inline_word_level(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><div class="corex-clause">Commission shall be five percent</div></div>',
            '<div class="doc"><div class="corex-clause">Commission shall be seven percent</div></div>'
        );
        $this->assertStringContainsString('<del class="change-del">seven</del>', $out);
        $this->assertStringContainsString('five', $out);
        $this->assertStringContainsString('Commission shall be', $out);
    }

    public function test_big_clause_change_strikes_and_cross_references_other_conditions(): void
    {
        $big = 'Notwithstanding any prior clause the purchaser accepts the property strictly voetstoots and waives every warranty whatsoever';
        $out = $this->h()->highlight(
            '<div class="doc"><div class="corex-clause" data-role-block="terms">' . $big . '</div></div>',
            '<div class="doc"><div class="corex-clause" data-role-block="terms">The seller warrants the property is free of all encumbrances at date of mandate</div></div>'
        );
        $this->assertStringContainsString('change-clause', $out);
        $this->assertStringContainsString('See Other Conditions', $out);
    }

    public function test_unchanged_clause_is_left_clean(): void
    {
        $doc = '<div class="doc"><div class="corex-clause">Voetstoots applies</div>'
             . '<div class="corex-clause">Commission is five percent</div></div>';
        $base = '<div class="doc"><div class="corex-clause">Voetstoots applies</div>'
              . '<div class="corex-clause">Commission is seven percent</div></div>';
        $out = $this->h()->highlight($doc, $base);
        $this->assertDoesNotMatchRegularExpression('/change-[a-z]+[^>]*>[^<]*Voetstoots applies/', $out);
        $this->assertStringContainsString('Voetstoots applies', $out);
    }

    public function test_no_diff_returns_input_unchanged(): void
    {
        $doc = '<div class="doc"><span data-field="x">same</span></div>';
        $this->assertSame($doc, $this->h()->highlight($doc, $doc));
    }

    public function test_empty_inputs_are_safe(): void
    {
        $this->assertSame('', $this->h()->highlight('', '<div>x</div>'));
        $this->assertSame('<div>x</div>', $this->h()->highlight('<div>x</div>', ''));
    }

    public function test_appendix_schedule_of_amendments_is_appended(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><span data-field="commission_percent">10</span></div>',
            '<div class="doc"><span data-field="commission_percent">7.5</span></div>'
        );
        $this->assertStringContainsString('Schedule of Amendments', $out);
        $this->assertStringContainsString('Commission percent', $out);   // pretty label
        $this->assertStringContainsString('>Initialed<', $out);          // column header
    }

    public function test_per_change_initialed_tag_only_on_initialed_change(): void
    {
        $base = '<div class="doc"><span data-field="a">1</span><span data-field="b">2</span></div>';
        $cur  = '<div class="doc"><span data-field="a">9</span><span data-field="b">8</span></div>';
        $initials = [ $this->changeId('a', '1', '9') => ['name' => 'A. Authoriser'] ];
        $out = $this->h()->highlight($cur, $base, $initials);

        $this->assertStringContainsString('Initialed by A. Authoriser', $out);
        // exactly one inline initialed tag (change 'a'); change 'b' stays pending
        $this->assertSame(1, substr_count($out, 'class="change-initialed"'));
    }

    /**
     * INTEGRATION — cc6's ClauseEditService bakes struck clauses into merged_html using OUR change-* classes
     * + data-change-id. Even with an EMPTY baseline (re-authorised: amendment_render cleared) the highlighter
     * must still inject the CSS, list the mark in the appendix, and stamp its initialed tag — so cc6's
     * permanent clause strikes stay STYLED on the final document.
     */
    public function test_absorbs_and_styles_pre_authored_cc6_marks_with_empty_baseline(): void
    {
        $cid = 'abc123def456';
        $cur = '<div class="doc"><div class="corex-clause" data-clause-ref="5.1" data-change-id="' . $cid . '" data-strikethrough-applied="1">'
             . '<del class="change-del change-clause" data-change-id="' . $cid . '">old text</del> '
             . '<ins class="change-ins" data-change-id="' . $cid . '">new text</ins></div></div>';

        // empty baseline → no diff, absorb-only
        $out = $this->h()->highlight($cur, '', [ $cid => ['name' => 'B. Manager'] ]);

        $this->assertStringContainsString('<style>', $out);                 // CSS injected
        $this->assertStringContainsString('.change-del{', $out);
        $this->assertStringContainsString('Schedule of Amendments', $out);  // listed in appendix
        $this->assertStringContainsString('Clause 5.1', $out);              // labelled by data-clause-ref
        $this->assertStringContainsString('Initialed by B. Manager', $out); // initialed via change_initials
    }

    public function test_empty_baseline_with_no_marks_is_a_noop(): void
    {
        $plain = '<div class="doc"><p>Nothing to see here</p></div>';
        $this->assertSame($plain, $this->h()->highlight($plain, ''));
    }

    /** The data-change-id convention MUST match cc6's ClauseEditService: sha1(key|old|new)[:12]. */
    public function test_change_id_convention_matches_cc6(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><span data-field="commission_percent">10</span></div>',
            '<div class="doc"><span data-field="commission_percent">7.5</span></div>'
        );
        $expected = substr(sha1('commission_percent|7.5|10'), 0, 12);
        $this->assertStringContainsString('data-change-id="' . $expected . '"', $out);
    }

    // ---------- SELECTION model (arbitrary span strike + margin initials) ----------

    private const PARTIES = [
        ['key' => 'seller_1', 'label' => 'Seller 1'],
        ['key' => 'seller_2', 'label' => 'Seller 2'],
        ['key' => 'agent_1',  'label' => 'Agent'],
    ];

    public function test_selection_strikes_arbitrary_span_and_writes_in_replacement(): void
    {
        $body = '<div class="doc"><p>Pay a deposit of ten percent within seven days.</p></div>';
        $out = $this->h()->highlight($body, '', [], [
            ['select' => 'ten percent', 'nth' => 1, 'insert' => 'twelve percent'],
        ], self::PARTIES);

        $this->assertMatchesRegularExpression(
            '/<del class="change-del"[^>]*>ten percent<\/del>\s*<ins class="change-ins"[^>]*>twelve percent<\/ins>/',
            $out
        );
    }

    public function test_selection_hits_only_the_nth_occurrence(): void
    {
        $body = '<div class="doc"><p>ten percent deposit and ten percent balance</p></div>';
        $out = $this->h()->highlight($body, '', [], [
            ['select' => 'ten percent', 'nth' => 2, 'insert' => 'twelve percent'],
        ], self::PARTIES);

        // exactly one strike; the FIRST "ten percent" stays as plain text
        $this->assertSame(1, substr_count($out, '<del class="change-del"'));
        $this->assertStringContainsString('>ten percent deposit', $out);
    }

    public function test_selection_deletion_only_has_no_insert(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><p>given on the date of transfer here</p></div>',
            '', [], [['select' => 'on the date of transfer', 'nth' => 1, 'insert' => '']], self::PARTIES
        );
        $this->assertStringContainsString('<del class="change-del"', $out);
        $this->assertStringNotContainsString('<ins class="change-ins"', $out);
    }

    public function test_selection_unlocatable_span_is_skipped_safely(): void
    {
        $body = '<div class="doc"><p>nothing matching here</p></div>';
        // no marks produced, no parties margin, no crash
        $out = $this->h()->highlight($body, '', [], [
            ['select' => 'this text is absent', 'nth' => 1, 'insert' => 'x'],
        ], self::PARTIES);
        $this->assertStringNotContainsString('change-del', $out);
    }

    /**
     * WET-INK PRESERVATION (AT-368): a big/reference selection edit strikes the span and cross-references
     * Other Conditions inline (the replacement is routed to OC, NOT written in), mirroring cc6's baked
     * reference path. This is what the SERVE path overlays onto a preserved SIGNED canonical when the amend
     * was recorded under web_template_data['pending_body_changes'] with mode='reference'.
     */
    public function test_selection_reference_mode_cross_references_other_conditions(): void
    {
        $out = $this->h()->highlight(
            '<div class="doc"><p>Pay a deposit of ten percent within seven days.</p></div>',
            '', [], [['select' => 'ten percent', 'insert' => 'as per addendum', 'mode' => 'reference', 'oc_ref' => 3]], self::PARTIES
        );
        $this->assertStringContainsString('<del class="change-del"', $out);            // struck
        $this->assertStringContainsString('change-xref', $out);                        // cross-ref rendered
        $this->assertStringContainsString('See Other Conditions — clause 3', $out);    // to OC entry #3
        $this->assertStringNotContainsString('<ins class="change-ins"', $out);         // replacement NOT inlined
    }

    /**
     * The selection overlay is IDEMPOTENT: on a body where the strike is ALREADY baked (the selected text sits
     * inside an existing <del>), re-applying the same change must NOT double-strike. This is what lets the
     * serve path re-run pending_body_changes over cc6's baked canonical without producing duplicate marks.
     */
    public function test_selection_overlay_is_idempotent_against_already_baked_strike(): void
    {
        $baked = '<div class="doc"><p>deposit of <span class="change-anchor" data-change-id="x1">'
            . '<del class="change-del" data-change-id="x1">ten percent</del> '
            . '<ins class="change-ins" data-change-id="x1">twelve percent</ins></span> within seven days.</p></div>';
        $out = $this->h()->highlight($baked, '', [], [
            ['change_id' => 'x1', 'select' => 'ten percent', 'insert' => 'twelve percent', 'mode' => 'selection'],
        ], self::PARTIES);

        $this->assertSame(1, substr_count($out, '<del class="change-del"'));   // exactly one strike — no double
    }

    public function test_margin_initials_one_slot_per_party_with_per_party_state(): void
    {
        $cid = substr(sha1('deposit|1|down payment'), 0, 12);
        $body = '<div class="doc"><p>A deposit is payable.</p></div>';
        $out = $this->h()->highlight($body, '', [
            $cid => ['seller_1' => ['name' => 'Alice Brown'], 'agent_1' => ['name' => 'Bob Carter']],
        ], [['select' => 'deposit', 'nth' => 1, 'insert' => 'down payment']], self::PARTIES);

        // one margin block, three party slots
        $this->assertStringContainsString('class="change-margin-initials"', $out);
        $this->assertStringContainsString('data-party="seller_1"', $out);
        $this->assertStringContainsString('data-party="seller_2"', $out);
        $this->assertStringContainsString('data-party="agent_1"', $out);
        // per-party initials filled where initialed, blank where not
        $this->assertStringContainsString('cis-ink cis-done">AB<', $out);   // seller_1
        $this->assertStringContainsString('cis-ink cis-done">BC<', $out);   // agent_1
        $this->assertMatchesRegularExpression('/data-party="seller_2"[^>]*>.*?<span class="cis-ink">____<\/span>/s', $out);
    }
}
