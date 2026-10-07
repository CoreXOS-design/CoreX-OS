<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\PlatformEsign\WordingVersion;

/**
 * Pagination (spec §11.6): which top-level blocks sit on which page. One stored layout per wording version is used by the
 * on-screen sheets AND the PDF, so "initial every page" means the same pages the sealed PDF will have.
 * Heights are estimated, then CALIBRATED against a real DomPDF render of a worst-case filled sample.
 */
class AgreementLayout
{
    public const LINE = 11.9;       // pt
    public const CPL = 88;          // characters per line at body width, DejaVu Sans 8.6pt
    public const START_BUDGET = 600;
    public const REV = 3;           // bump to invalidate stored layouts when the estimator/CSS changes

    public function __construct(private AgreementRenderer $renderer, private AgreementPdf $pdf)
    {
    }

    /** The version's stored layout, computed (and stored) on first use. @return array{rev:int,budget:float,parts:array<string,int[]>,total:int} */
    public function ensure(WordingVersion $v): array
    {
        $l = $v->layout_json;
        if (is_array($l) && ($l['rev'] ?? 0) === self::REV && !empty($l['parts'])) {
            return $l;
        }
        // A PUBLISHED version's pagination is what agencies have already initialled page by page, so a REV bump
        // (estimator/CSS change) must never repaginate it mid-signing — keep the layout it was published with.
        // Only a draft, or a published version with no layout at all, is (re)computed.
        if ($v->is_published && is_array($l) && !empty($l['parts'])) {
            return $l;
        }
        $l = $this->compute($v);
        $v->forceFill(['layout_json' => $l])->save();

        return $l;
    }

    public function compute(WordingVersion $v): array
    {
        $blocks = [];
        foreach (array_keys(AgreementContent::PARTS) as $part) {
            $blocks[$part] = $this->renderer->blocks($v, $part, 'canon');
        }
        $est = array_map(fn ($list) => array_map(fn ($h) => self::estimate($h), $list), $blocks);
        // A heading stays with the block after it; so does the agents/branches entry (marked in canon mode) — it must never be
        // parted from the fee table it feeds by a page break.
        $isHeading = array_map(fn ($list) => array_map(fn ($h) => (bool) preg_match('/^<h[1-3][ >]/', $h) || str_contains($h, 'keep-next'), $list), $blocks);

        $budget = (float) self::START_BUDGET;
        $layout = null;
        for ($i = 0; $i < 12; $i++) {
            $parts = $this->pack($est, $isHeading, $budget);
            $layout = ['rev' => self::REV, 'budget' => round($budget, 1), 'parts' => $parts, 'total' => array_sum(array_map('count', $parts))];
            $physical = $this->pdf->countContractPages($v, $layout, AgreementSample::ctx($v));
            if ($physical === $layout['total']) {
                break;
            }
            $budget *= 0.95;
            if ($budget < 380) {
                break;
            }
        }

        return $layout;
    }

    /**
     * @param array<string,float[]> $est
     * @param array<string,bool[]> $isHeading
     * @return array<string,int[]> part => block count per page
     */
    private function pack(array $est, array $isHeading, float $budget): array
    {
        $out = [];
        foreach ($est as $part => $heights) {
            $counts = [];
            $cur = 0.0;
            $n = 0;
            foreach ($heights as $i => $h) {
                if ($n > 0 && $cur + $h > $budget) {
                    $carry = 0;
                    $carryH = 0.0;
                    // keep a heading with the block that follows it
                    if ($isHeading[$part][$i - 1] ?? false) {
                        $carry = 1;
                        $carryH = $heights[$i - 1];
                        $n--;
                    }
                    if ($n > 0) {
                        $counts[] = $n;
                    }
                    $n = $carry;
                    $cur = $carryH;
                }
                $n++;
                $cur += $h;
            }
            if ($n > 0) {
                $counts[] = $n;
            }
            $out[$part] = $counts;
        }

        return $out;
    }

    /** Estimated rendered height (pt) of one HTML block. */
    public static function estimate(string $html): float
    {
        $lh = self::LINE;
        $extra = substr_count($html, 'sigbox') * 38.0 + substr_count($html, '<textarea') * 12;
        if (preg_match('/^<table/', $html)) {
            preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $html, $rows);
            $total = 8.0;
            foreach ($rows[1] as $row) {
                preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#s', $row, $cells);
                $n = max(1, count($cells[1]));
                $lines = 1;
                foreach ($cells[1] as $c) {
                    $share = $n === 2 ? 0.5 : 1 / $n;
                    $paras = max(1, substr_count($c, '</p>') + substr_count($c, '<br'));
                    $lines = max($lines, ceil(self::len($c) / (self::CPL * $share * 0.88)) + ($paras - 1));
                }
                $total += $lines * $lh + 7.5;
            }

            return $total + $extra;
        }
        if (preg_match('/^<h1/', $html)) {
            return 42 + $extra;
        }
        if (preg_match('/^<h2/', $html)) {
            return 30 + $extra;
        }
        if (preg_match('/^<h3/', $html)) {
            return 24 + $extra;
        }
        if (preg_match('/^<(ul|ol)/', $html)) {
            preg_match_all('#<li[^>]*>(.*?)</li>#s', $html, $li);
            $lines = 0;
            foreach ($li[1] as $x) {
                $lines += max(1, ceil(self::len($x) / (self::CPL - 6)));
            }

            return $lines * $lh + 7 + $extra;
        }
        if (preg_match('/^<blockquote/', $html)) {
            $lines = max(1, ceil(self::len($html) / (self::CPL - 12)));

            return $lines * $lh + 10 + substr_count($html, '</p>') * 3 + $extra;
        }
        $lines = max(1, ceil(self::len($html) / self::CPL) + substr_count($html, '<br'));

        return $lines * $lh + 6.5 + $extra;
    }

    private static function len(string $html): int
    {
        return mb_strlen(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
