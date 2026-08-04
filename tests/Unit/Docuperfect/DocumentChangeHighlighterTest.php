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
}
