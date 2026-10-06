<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * "What changed" between two versions of the wording (spec §11.14): clause-aligned, with a word-level diff inside an
 * edited clause. Works on the clauses' Markdown source so formatting changes (a bold, a link) show up too.
 */
class AgreementDiff
{
    /**
     * @param string[] $a clauses of the older version
     * @param string[] $b clauses of the newer version
     * @return array<int,array{type:string,a:?string,b:?string,aHtml:?string,bHtml:?string}> type: same | removed | added | changed
     */
    public static function clauses(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $rows = [];
        $remA = [];
        $remB = [];
        $flush = function () use (&$rows, &$remA, &$remB) {
            foreach (self::pair($remA, $remB) as $r) {
                $rows[] = $r;
            }
            $remA = [];
            $remB = [];
        };
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $flush();
                $rows[] = ['type' => 'same', 'a' => $a[$i], 'b' => $b[$j], 'aHtml' => null, 'bHtml' => null];
                $i++;
                $j++;
            } elseif ($j >= $m || ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $remA[] = $a[$i++];
            } else {
                $remB[] = $b[$j++];
            }
        }
        $flush();

        return $rows;
    }

    /** Pair removed with added clauses that are clearly the same clause edited; the rest stay removed / added. */
    private static function pair(array $removed, array $added): array
    {
        $out = [];
        $j = 0;
        foreach ($removed as $r) {
            $hit = null;
            for ($k = $j; $k < count($added); $k++) {
                if (self::similarity($r, $added[$k]) >= 0.45) {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null) {
                $out[] = ['type' => 'removed', 'a' => $r, 'b' => null, 'aHtml' => null, 'bHtml' => null];
                continue;
            }
            for (; $j < $hit; $j++) {
                $out[] = ['type' => 'added', 'a' => null, 'b' => $added[$j], 'aHtml' => null, 'bHtml' => null];
            }
            [$ah, $bh] = self::words($r, $added[$hit]);
            $out[] = ['type' => 'changed', 'a' => $r, 'b' => $added[$hit], 'aHtml' => $ah, 'bHtml' => $bh];
            $j = $hit + 1;
        }
        for (; $j < count($added); $j++) {
            $out[] = ['type' => 'added', 'a' => null, 'b' => $added[$j], 'aHtml' => null, 'bHtml' => null];
        }

        return $out;
    }

    private static function similarity(string $x, string $y): float
    {
        $wx = array_unique(preg_split('/\s+/u', trim($x)) ?: []);
        $wy = array_unique(preg_split('/\s+/u', trim($y)) ?: []);
        $union = count(array_unique(array_merge($wx, $wy)));

        return $union === 0 ? 1.0 : count(array_intersect($wx, $wy)) / $union;
    }

    /** @return array{0:string,1:string} the two clauses as escaped HTML with <del>/<ins> around the differing words */
    public static function words(string $x, string $y): array
    {
        $tx = preg_split('/(\s+)/u', $x, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $ty = preg_split('/(\s+)/u', $y, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tx) > 1600 || count($ty) > 1600) {
            return [e($x), e($y)];
        }
        $n = count($tx);
        $m = count($ty);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $tx[$i] === $ty[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }
        $ha = '';
        $hb = '';
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $tx[$i] === $ty[$j]) {
                $ha .= e($tx[$i]);
                $hb .= e($ty[$j]);
                $i++;
                $j++;
            } elseif ($j >= $m || ($i < $n && $l[$i + 1][$j] >= $l[$i][$j + 1])) {
                $ha .= trim($tx[$i]) === '' ? e($tx[$i]) : '<del>' . e($tx[$i]) . '</del>';
                $i++;
            } else {
                $hb .= trim($ty[$j]) === '' ? e($ty[$j]) : '<ins>' . e($ty[$j]) . '</ins>';
                $j++;
            }
        }

        // Merge neighbouring marks: "<del>a</del> <del>b</del>" → "<del>a b</del>"
        $merge = fn (string $h, string $tag) => preg_replace('#</' . $tag . '>(\s+)<' . $tag . '>#u', '$1', $h);

        return [$merge($ha, 'del'), $merge($hb, 'ins')];
    }

    /** @return array{added:int,removed:int,changed:int} */
    public static function summary(array $rows): array
    {
        $s = ['added' => 0, 'removed' => 0, 'changed' => 0];
        foreach ($rows as $r) {
            if (isset($s[$r['type']])) {
                $s[$r['type']]++;
            }
        }

        return $s;
    }
}
