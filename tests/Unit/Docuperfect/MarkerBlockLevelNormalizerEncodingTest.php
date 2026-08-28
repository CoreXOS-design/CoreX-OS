<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect;

use App\Services\Docuperfect\MarkerBlockLevelNormalizer;
use Tests\TestCase;

/**
 * AT-387 — MarkerBlockLevelNormalizer::normalize() called DOMDocument::loadHTML()
 * without declaring UTF-8, so libxml assumed ISO-8859-1 for the input bytes and
 * every multi-byte UTF-8 character came out double-mangled (the Ã¢ÂÂ signature).
 * Proved live on QA1 against the real function before this fix landed. This test
 * asserts on raw bytes, not rendered strings, so a future regression can't hide
 * behind a terminal/editor that silently re-decodes the mangled output back to
 * something that "looks fine" on screen.
 */
final class MarkerBlockLevelNormalizerEncodingTest extends TestCase
{
    private function n(): MarkerBlockLevelNormalizer
    {
        return app(MarkerBlockLevelNormalizer::class);
    }

    public function test_curly_apostrophe_survives_alongside_a_marker(): void
    {
        $html = "Test paragraph: company\u{2019}s policy ~~~~OTHER_CONDITIONS~~~~";
        $out = $this->n()->normalize($html);

        $this->assertStringContainsString(
            "company\u{2019}s policy",
            $out,
            'curly apostrophe must survive byte-identical'
        );
        $this->assertStringNotContainsString('Ã', $out, 'no mojibake byte sequence introduced');
    }

    public function test_en_dash_curly_quotes_and_real_bullet_survive_alongside_a_marker(): void
    {
        $html = "Pages 1\u{2013}2 range. \u{201c}For Sale\u{201d} board. \u{2022} A real bullet. ~~~~OTHER_CONDITIONS~~~~";
        $out = $this->n()->normalize($html);

        $this->assertStringContainsString("1\u{2013}2 range", $out, 'en dash must survive byte-identical');
        $this->assertStringContainsString("\u{201c}For Sale\u{201d}", $out, 'curly double quotes must survive byte-identical');
        $this->assertStringContainsString("\u{2022} A real bullet", $out, 'real U+2022 bullet must survive byte-identical — this is the character now used in all 22 prepared documents');
        $this->assertStringNotContainsString('Ã', $out, 'no mojibake byte sequence introduced');
    }

    public function test_non_breaking_space_survives_alongside_a_marker(): void
    {
        $html = "1.\u{00A0}DOMICILUM CITANDI ET EXECUTANDI ~~~~OTHER_CONDITIONS~~~~";
        $out = $this->n()->normalize($html);

        $this->assertStringContainsString(
            "1.\u{00A0}DOMICILUM",
            $out,
            'non-breaking space (Pattern A on the wet-ink PDF) must survive byte-identical'
        );
        $this->assertStringNotContainsString('Ã', $out, 'no mojibake byte sequence introduced');
    }

    public function test_content_without_marker_is_returned_untouched(): void
    {
        $html = "Plain paragraph, company\u{2019}s policy, no markers here.";
        $out = $this->n()->normalize($html);

        $this->assertSame($html, $out, 'the ~~~~ guard must still early-return the exact input string, unchanged');
    }

    public function test_marker_is_still_wrapped_in_its_own_block_level_element(): void
    {
        // The behaviour this function exists for — unrelated to the encoding fix —
        // must be provably unchanged: a marker inline in text still gets promoted
        // to the sole content of its own block-level element.
        $html = '<p>Other: ~~~~OTHER_CONDITIONS~~~~ end of clause.</p>';
        $out = $this->n()->normalize($html);

        $this->assertMatchesRegularExpression(
            '/<p[^>]*data-insertable-block-marker="1"[^>]*>~~~~OTHER_CONDITIONS~~~~<\/p>/',
            $out,
            'marker must still be split into its own block-level element, exactly as before the encoding fix'
        );
    }

    public function test_marker_still_wrapped_correctly_when_curly_characters_are_present(): void
    {
        // Combines the encoding fix and the structural behaviour in one document —
        // the actual production shape (template 68/85 both had this exact combination).
        $html = '<div class="corex-clause-text">Other: ~~~~OTHER_CONDITIONS~~~~</div>'
            . "<div>my company\u{2019}s policy on \u{201c}For Sale\u{201d} boards</div>";
        $out = $this->n()->normalize($html);

        $this->assertStringContainsString('data-insertable-block-marker="1"', $out);
        $this->assertStringContainsString("company\u{2019}s policy", $out);
        $this->assertStringContainsString("\u{201c}For Sale\u{201d}", $out);
        $this->assertStringNotContainsString('Ã', $out);
    }
}
