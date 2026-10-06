<?php

namespace App\Services\PlatformEsign\Agreement;

use Symfony\Component\Process\Process;

/**
 * Word-for-word proof that what the client sees and signs is the signed-off text (spec §11.15).
 *
 * Everything is compared as ONE continuous word sequence — never paragraph by paragraph, so nothing can be "skipped".
 * The expected sequence is read straight from the two source files by a deliberately independent, simple normaliser
 * (it does NOT use the CommonMark renderer the product uses, so a renderer bug cannot hide itself).
 *
 * The ONLY tolerated differences are structural, and each is explicit in this file:
 *   - markup (tables, bold, headings, tags) is not text; reading order of table cells is the source's own order
 *   - whitespace / line wraps; "1st" superscript spacing
 *   - BLANK runs (______, ………, …..) may be filled by a form field or a filled value (reported, never silent)
 *   - tick boxes (☐) may render as a radio control (no text) or as ☐/☒
 * Anything else — a missing, extra, changed or reordered word — is returned as a difference.
 */
class AgreementFidelity
{
    public const BLANK = "\u{E000}";
    public const BOX = "\u{E001}";
    private const PIPE = "\u{E002}";
    /** The most words one blank may be filled with (a company address is the longest). */
    public const MAX_FILL = 24;
    /** How far ahead to look for the point where the two sequences agree again. */
    private const RESYNC = 40;
    private const ANCHOR = 3;

    /** Text as a list of words from the two source files, in reading order. @return string[] */
    public static function sourceTokens(string $agreementMd, string $mandateMd): array
    {
        return array_merge(self::fromSource($agreementMd), self::fromSource($mandateMd));
    }

    /** @return string[] */
    public static function fromSource(string $md): array
    {
        $s = str_replace(["\r\n", "\r"], "\n", $md);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace('\\|', self::PIPE, $s);                                         // an escaped pipe is a literal "|" in the text
        $s = preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!<>])/u', '$1', $s);                  // markdown escapes (\_ \* …)
        $s = preg_replace('/<(https?:\/\/[^\s<>]+|[^\s<>@]+@[^\s<>]+)>/u', '$1', $s);      // <https://…> and <mail@address> autolinks
        $s = preg_replace('/\[([^\]\n]+)\]\((?:https?:\/\/|mailto:)[^)\s]*\)/u', '$1', $s);  // [text](url) → text
        $s = preg_replace('#<td\b[^>]*>\s*</td>#i', ' <td> ' . self::BLANK . ' </td> ', $s);   // an empty HTML cell is a blank to fill
        $s = preg_replace('#</?(strong|em|sup|sub|b|i|span)\b[^>]*>#i', '', $s);        // inline tags: no word break
        $s = preg_replace('#</?(colgroup|col)\b[^>]*>#i', '', $s);                      // table column widths
        $s = preg_replace('#</?(p|td|th|tr|table|thead|tbody|br|ul|ol|li|div|h[1-6])\b[^>]*>#i', ' ', $s); // block tags: word break
        $s = preg_replace('/^[ \t]*(>[ \t]*)+/m', '', $s);                                // block-quote markers
        $s = preg_replace('/^[ \t]*\|?[ \t:|\-]+\|?[ \t]*$/m', ' ', $s);                // table rules: |---|---|, |||
        $s = preg_replace_callback('/^[ \t]*\|.*$/m', function ($m) {                      // an empty cell in a table row is a blank to fill
            $cells = array_slice(preg_split('/(?<!\x{E002})\|/u', $m[0]), 1, -1);
            return implode(' | ', array_map(fn ($c) => trim($c) === '' ? ' ' . self::BLANK . ' ' : $c, $cells));
        }, $s);
        $s = preg_replace('/^[ \t]*#{1,6}[ \t]+/m', '', $s);                            // heading markers
        $s = str_replace('*', '', $s);                                                   // bold / italic marks
        $s = str_replace('|', ' ', $s);                                                  // table pipes
        $s = str_replace(self::PIPE, '|', $s);
        $s = preg_replace('/_{2,}|…+\.*|\.{2,}/u', ' ' . self::BLANK . ' ', $s);        // blanks: ______  ………  ……..  ....
        $s = str_replace('☐', ' ' . self::BOX . ' ', $s);

        return self::collapseBlanks(self::words($s));
    }

    /** Words of rendered text (web DOM text, or pdftotext output). @return string[] */
    public static function fromRendered(string $text, bool $joinHyphenWraps = false): array
    {
        $s = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($joinHyphenWraps) {
            $s = preg_replace('/(\p{L})-\n(\p{Ll})/u', '$1-$2', $s); // a PDF line that wraps after a hyphen ("month-" / "end") is one word "month-end"
            $s = preg_replace('/([“‘])\s+/u', '$1', $s);               // the extractor spaces a bold word off its opening quote
            $s = preg_replace('/\s+([”’])/u', '$1', $s);
        }
        $s = str_replace(['☐', '☒'], ' ' . self::BOX . ' ', $s);

        return self::words($s);
    }

    /** @return string[] */
    public static function words(string $s): array
    {
        $s = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}", "\u{00AD}"], [' ', '', '', ''], $s);
        $s = preg_replace('/(\d)\s+(st|nd|rd|th)\b/u', '$1$2', $s);                       // "1 st" superscript spacing
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values($words);
    }

    /** Neighbouring blanks collapse into one; a blank next to a tick box stays separate. @param string[] $w @return string[] */
    private static function collapseBlanks(array $w): array
    {
        $out = [];
        foreach ($w as $t) {
            if ($t === self::BLANK && $out && end($out) === self::BLANK) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * @param string[] $expected source tokens (may contain BLANK / BOX)
     * @param string[] $actual rendered words (BOX for ☐/☒)
     * @return array{differences: array<int,array{at:int,context:string,expected:string,actual:string,kind:string}>, fills: array<int,array{context:string,text:string}>, matched:int, expected:int, actual:int}
     */
    public static function compare(array $expected, array $actual): array
    {
        $diffs = [];
        $fills = [];
        $matched = 0;
        $i = 0;
        $j = 0;
        $ne = count($expected);
        $na = count($actual);
        $ctx = fn (int $at) => implode(' ', array_map([self::class, 'show'], array_slice($expected, max(0, $at - 6), 6)));

        while ($i < $ne && $j < $na) {
            $e = $expected[$i];
            if ($e === self::BLANK) {
                $anchor = self::anchor($expected, $i + 1);
                $k = 0;
                while ($k <= self::MAX_FILL && $j + $k <= $na && !self::startsWith($actual, $j + $k, $anchor)) {
                    $k++;
                }
                if ($k > self::MAX_FILL || $j + $k > $na) {
                    $diffs[] = ['at' => $i, 'context' => $ctx($i), 'expected' => '(a blank, then) ' . self::show(implode(' ', $anchor)), 'actual' => implode(' ', array_map([self::class, 'show'], array_slice($actual, $j, 12))), 'kind' => 'blank-not-resolved'];
                    $i++;
                    continue;
                }
                if ($k > 0) {
                    $fills[] = ['context' => $ctx($i), 'text' => implode(' ', array_slice($actual, $j, $k))];
                }
                $j += $k;
                $i++;
                continue;
            }
            if ($e === self::BOX) {
                // a tick box: a control (no text) or one ☐/☒ glyph
                if ($actual[$j] === self::BOX) {
                    $j++;
                }
                $i++;
                $matched++;
                continue;
            }
            if ($actual[$j] === self::BOX && $e !== self::BOX) {
                $diffs[] = ['at' => $i, 'context' => $ctx($i), 'expected' => '', 'actual' => self::show(self::BOX), 'kind' => 'extra'];
                $j++;
                continue;
            }
            if ($e === $actual[$j]) {
                $i++;
                $j++;
                $matched++;
                continue;
            }
            // mismatch: find the smallest (words missing from rendering, words extra in rendering) after which we agree again
            [$missing, $extra] = self::resync($expected, $i, $actual, $j);
            $exp = array_slice($expected, $i, $missing);
            $act = array_slice($actual, $j, $extra);
            $diffs[] = ['at' => $i, 'context' => $ctx($i), 'expected' => implode(' ', array_map([self::class, 'show'], $exp)), 'actual' => implode(' ', array_map([self::class, 'show'], $act)),
                'kind' => $missing && $extra ? 'changed' : ($missing ? 'missing' : 'extra')];
            $i += $missing;
            $j += $extra;
        }
        while ($i < $ne) {
            if ($expected[$i] !== self::BLANK && $expected[$i] !== self::BOX) {
                $diffs[] = ['at' => $i, 'context' => $ctx($i), 'expected' => self::show($expected[$i]), 'actual' => '', 'kind' => 'missing'];
            }
            $i++;
        }
        if ($j < $na) {
            $diffs[] = ['at' => $ne, 'context' => $ctx($ne), 'expected' => '', 'actual' => implode(' ', array_map([self::class, 'show'], array_slice($actual, $j, 60))) . ($na - $j > 60 ? ' …(' . ($na - $j) . ' words)' : ''), 'kind' => 'extra'];
        }

        return ['differences' => $diffs, 'fills' => $fills, 'matched' => $matched, 'expected' => $ne, 'actual' => $na];
    }

    /** The next ANCHOR plain tokens of $expected from $from (stops at a blank/box). @return string[] */
    private static function anchor(array $expected, int $from): array
    {
        $out = [];
        for ($x = $from; $x < count($expected) && count($out) < self::ANCHOR; $x++) {
            if ($expected[$x] === self::BLANK || $expected[$x] === self::BOX) {
                break;
            }
            $out[] = $expected[$x];
        }

        return $out ?: ['(end)'];
    }

    private static function startsWith(array $actual, int $at, array $anchor): bool
    {
        if ($anchor === ['(end)']) {
            return $at >= count($actual);
        }
        foreach ($anchor as $n => $tok) {
            if (($actual[$at + $n] ?? null) !== $tok) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0:int,1:int} [words of expected to skip, words of actual to skip] */
    private static function resync(array $expected, int $i, array $actual, int $j): array
    {
        $best = null;
        for ($total = 1; $total <= self::RESYNC * 2 && !$best; $total++) {
            for ($m = 0; $m <= $total; $m++) {
                $x = $total - $m;
                if ($m > self::RESYNC || $x > self::RESYNC || ($m === 0 && $x === 0)) {
                    continue;
                }
                $anchor = self::anchor($expected, $i + $m);
                if (self::startsWith($actual, $j + $x, $anchor) && ($m > 0 || $x > 0)) {
                    $best = [$m, $x];
                    break;
                }
            }
        }

        return $best ?: [1, 1];
    }

    public static function show(string $t): string
    {
        return str_replace([self::BLANK, self::BOX], ['[blank]', '☐'], $t);
    }

    // ── PDF text ───────────────────────────────────────────────────────────

    /**
     * The contract text of a PDF as written on the pages (letterhead above 96pt and footer below 772pt are cropped;
     * the signing-record pages after the contract are left out), in content-stream order.
     */
    public static function pdfText(string $pdfBinary, int $contractPages): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agrfid');
        file_put_contents($tmp, $pdfBinary);
        try {
            $p = new Process(['pdftotext', '-raw', '-enc', 'UTF-8', '-r', '72', '-x', '0', '-y', '96', '-W', '595', '-H', '676', '-f', '1', '-l', (string) $contractPages, $tmp, '-']);
            $p->setTimeout(120);
            $p->mustRun();

            return $p->getOutput();
        } finally {
            @unlink($tmp);
        }
    }

    /** The footer/letterhead band of a PDF page, for the version-footer check. */
    public static function pdfFooterText(string $pdfBinary, int $page = 1): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agrfid');
        file_put_contents($tmp, $pdfBinary);
        try {
            $p = new Process(['pdftotext', '-raw', '-enc', 'UTF-8', '-r', '72', '-x', '0', '-y', '772', '-W', '595', '-H', '70', '-f', (string) $page, '-l', (string) $page, $tmp, '-']);
            $p->setTimeout(60);
            $p->mustRun();

            return $p->getOutput();
        } finally {
            @unlink($tmp);
        }
    }
}
