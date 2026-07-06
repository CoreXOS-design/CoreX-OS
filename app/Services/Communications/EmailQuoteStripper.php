<?php

declare(strict_types=1);

namespace App\Services\Communications;

/**
 * AT-182 (thread de-duplication) — derive the DISPLAY body of an archived email by removing
 * the quoted reply history, so the thread conversation view shows only each message's NEW
 * content (Johan's ruling: THREAD = conversation; OPEN = the full original).
 *
 * DISPLAY-LAYER ONLY. The raw stored `body_text` (and the on-disk raw email) are NEVER
 * modified — the archive is an immutable compliance record. This produces a derived string
 * stored alongside it in `communications.body_display`.
 *
 * Operates on PLAIN TEXT (the poller stores `getTextBody()` or `stripHtml(getHTMLBody())`).
 *
 * UNCERTAINTY RULE (BUILD_STANDARD prevent-or-absorb): when the stripper cannot confidently
 * isolate the new part — no boundary found, or stripping would leave ~nothing (a bare
 * forward/quote) — it returns the FULL body unchanged. Duplicated beats lost: never hide
 * potentially-unique content.
 */
final class EmailQuoteStripper
{
    /** Below this many chars of surviving new content, assume we over-reached → keep full. */
    private const MIN_NEW_LENGTH = 2;

    /**
     * @return array{display: string, stripped: bool}
     *   display  — the new-content-only body (or the full body if uncertain)
     *   stripped — true only when quoted history was confidently removed
     */
    public function strip(?string $bodyText): array
    {
        $full = (string) $bodyText;
        if (trim($full) === '') {
            return ['display' => $full, 'stripped' => false];
        }

        // Normalise line endings so line-anchored patterns and offsets are consistent.
        $text = str_replace(["\r\n", "\r"], "\n", $full);

        // Cut quoted history at the earliest boundary (if any), then trim a trailing
        // signature. The "-- " signature delimiter is an unambiguous RFC marker, so it is
        // safe to honour even when there is no quote to strip.
        $cut = $this->earliestBoundaryOffset($text);
        $kept = $cut === null ? $text : substr($text, 0, $cut);
        $display = $this->trimTrailingSignature(rtrim($kept));

        // Uncertainty rule: stripping left ~nothing (a bare quote/forward) → keep the full body.
        if (mb_strlen(trim($display)) < self::MIN_NEW_LENGTH) {
            return ['display' => $full, 'stripped' => false];
        }

        // Nothing was actually removed → not stripped; return the original body verbatim.
        if (trim($display) === trim($text)) {
            return ['display' => $full, 'stripped' => false];
        }

        return ['display' => trim($display), 'stripped' => true];
    }

    /**
     * The earliest BYTE offset at which quoted history begins, or null if none is found.
     * All offsets are byte offsets (PREG_OFFSET_CAPTURE + strlen) so min() is consistent.
     */
    private function earliestBoundaryOffset(string $text): ?int
    {
        $offsets = [];

        // 1. "On <date>, <name> wrote:" — Gmail / Apple Mail (single line, possibly long).
        if (preg_match('/^On\b.{0,240}?\bwrote:[ \t]*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }
        // Wrapped variant: "On <date …>\n<name> wrote:"
        if (preg_match('/^On\b.{0,240}?\n.{0,160}?\bwrote:[ \t]*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }

        // 2. Outlook / generic original-message divider.
        if (preg_match('/^[ \t]*-{2,}[ \t]*Original Message[ \t]*-{2,}[ \t]*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }

        // 3. Forwarded blocks.
        if (preg_match('/^[ \t]*-{2,}[ \t]*Forwarded message[ \t]*-{2,}[ \t]*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }
        if (preg_match('/^[ \t]*Begin forwarded message:[ \t]*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }

        // 4. Outlook header block: a "From:" line followed within a few lines by Sent/Date/To/Subject.
        if (preg_match('/^From:.*(?:\n.*){0,4}?\n(?:Sent|Date|To|Subject):/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }

        // 5. Trailing block of plain-text ">" quoting (runs to EOF, blank lines allowed).
        $q = $this->trailingQuoteBlockOffset($text);
        if ($q !== null) {
            $offsets[] = $q;
        }

        $offsets = array_filter($offsets, static fn ($o): bool => is_int($o) && $o >= 0);

        return $offsets === [] ? null : min($offsets);
    }

    /**
     * Byte offset where the trailing contiguous run of ">"-quoted lines begins (allowing
     * interspersed blank lines), or null if the body does not end in a quote block. Requires
     * at least two quoted lines so a lone "> x" mid-sentence is not mistaken for history.
     */
    private function trailingQuoteBlockOffset(string $text): ?int
    {
        $lines = explode("\n", $text);
        $n = count($lines);

        $i = $n - 1;
        while ($i >= 0 && trim($lines[$i]) === '') {
            $i--; // skip trailing blank lines
        }
        if ($i < 0 || !$this->isQuoted($lines[$i])) {
            return null; // does not end in a quoted line
        }

        $firstQuotedLine = $i;
        $quotedCount = 0;
        while ($i >= 0) {
            if ($this->isQuoted($lines[$i])) {
                $firstQuotedLine = $i;
                $quotedCount++;
                $i--;
            } elseif (trim($lines[$i]) === '') {
                $i--; // blanks inside the block are allowed but don't extend it alone
            } else {
                break; // a real non-quoted line ends the block
            }
        }

        if ($quotedCount < 2) {
            return null;
        }

        // Byte offset of the first quoted line = sum of preceding line lengths + newlines.
        $offset = 0;
        for ($k = 0; $k < $firstQuotedLine; $k++) {
            $offset += strlen($lines[$k]) + 1; // +1 for the stripped "\n"
        }

        return $offset;
    }

    private function isQuoted(string $line): bool
    {
        return (bool) preg_match('/^[ \t]*>/', $line);
    }

    /**
     * Conservatively remove a trailing RFC-2646 signature block (a line that is exactly "-- ")
     * and everything after it. Only the canonical delimiter is honoured, so real content is
     * never mistaken for a signature.
     */
    private function trimTrailingSignature(string $text): string
    {
        return (string) preg_replace('/\n-- \n.*\z/s', '', $text);
    }
}
