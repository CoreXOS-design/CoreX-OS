<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/**
 * AT-479 (Johan, 21 Oct) — a contact matched on phone / e-mail can be a DIFFERENT person than the one reOS names. The review screen
 * says so, in two strengths (Johan, after the real-file check: 26 of 46 rows flagged was noise):
 *
 *   SPELLING  muted grey, not counted — the same name written differently: same surname once spaces are squashed ("Mc Neil" =
 *             "McNeil"), a middle name on one side only, or a small typo (edit distance ≤ 2) in the first name or surname
 *             ("Mkhozi"/"Mkozi", "Desire"/"Desiree", "LTY"/"LTD"), or the first name and surname written the other way round
 *             ("Nkome Langelihle" / "Langelihle Nkomo" — the name as a set of words, each within the same small typo allowance).
 *   DIFFERS   amber, counted — anything else ("Aiesha Steady" vs "Elana Reid", "Andre" vs "Johan Esterhuizen").
 *   PERSON_FOR_COMPANY  amber, counted — reOS names a company and CoreX holds a person ("likely the company's contact person").
 *
 * Names are compared normalised — case, spacing, punctuation, "&"/"and" and the legal suffixes Pty/Ltd/Limited ignored — and an
 * initial matches a full first name. A name matches outright when every word of the SHORTER name is found in the longer one. All of
 * these are WARNINGS for the person looking at the row, never blockers.
 */
final class ReosNameCompare
{
    public const OK = null;
    public const SPELLING = 'spelling';
    public const DIFFERS = 'differs';
    public const PERSON_FOR_COMPANY = 'person_for_company';

    private const IGNORED = ['and', 'the', 'pty', 'ltd', 'limited'];

    /** Flags that count in the amber total (SPELLING does not). */
    public const AMBER = [self::DIFFERS, self::PERSON_FOR_COMPANY];

    /**
     * @param bool $contactIsCompany  the CoreX contact is a company — a company record, OR a person-kind record whose name reads as a
     *                                company (CoreX is full of those: ReosContactNames::isCompanyContact())
     * @return string|null  null = same name; SPELLING; DIFFERS; PERSON_FOR_COMPANY
     */
    public static function flag(string $reosName, string $contactName, bool $contactIsCompany): ?string
    {
        $reosIsCompany = ReosContactNames::isCompany($reosName);

        // A company is only ever a "person's company" when CoreX really holds a person. A company matched to a company is a company
        // match — at most a spelling difference — and an entity is never offered as somebody's contact person.
        if ($reosIsCompany && ! $contactIsCompany) {
            return self::PERSON_FOR_COMPANY;
        }
        if (self::sameName($reosName, $contactName)) {
            return self::OK;
        }

        return self::spellingOnly($reosName, $contactName, $reosIsCompany && $contactIsCompany) ? self::SPELLING : self::DIFFERS;
    }

    public static function sameName(string $a, string $b): bool
    {
        $x = self::words($a);
        $y = self::words($b);
        if ($x === [] || $y === []) {
            return true;   // nothing to compare on one side: do not cry wolf
        }
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];

        foreach ($short as $w) {
            $hit = false;
            foreach ($long as $i => $l) {
                if (self::wordsAgree($w, $l)) {
                    unset($long[$i]);   // each word of the longer name is used once
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    /** Not the same name, but only a spelling apart (see the class note). */
    public static function spellingOnly(string $a, string $b, bool $bothCompanies = false): bool
    {
        $x = self::words($a);
        $y = self::words($b);
        if ($x === [] || $y === []) {
            return true;
        }
        $sx = implode('', $x);
        $sy = implode('', $y);
        if ($sx === $sy) {
            return true;   // "Mc Neil" = "McNeil"
        }

        if ($bothCompanies) {
            // Company names: a typo or two in the whole name, or every word of the shorter within one letter of a word of the longer.
            if (self::near($sx, $sy, 2)) {
                return true;
            }
            [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];
            foreach ($short as $w) {
                $hit = false;
                foreach ($long as $i => $l) {
                    if ($w === $l || self::near($w, $l, 1)) {
                        unset($long[$i]);
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    return false;
                }
            }

            return true;
        }

        // People: the first name agrees (equal, an initial, or a small typo) AND the surname agrees once squashed — the surname being
        // the last one or two words, so a middle name on one side only, or "Mc Neil", does not matter.
        if (self::sameWordsAnyOrder($x, $y)) {
            return true;   // first name and surname swapped (with the usual typo allowance on each word)
        }
        $firstOk = self::wordsAgree($x[0], $y[0]) || self::near($x[0], $y[0], 2);
        if (! $firstOk) {
            return false;
        }
        foreach ([1, 2] as $n) {
            foreach ([1, 2] as $m) {
                if (count($x) < $n || count($y) < $m) {
                    continue;
                }
                if (self::near(implode('', array_slice($x, -$n)), implode('', array_slice($y, -$m)), 2)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every word of the shorter name is found, once each, in the longer one — equal, an initial, or within two edits — in ANY order.
     * Needs two or more words on the shorter side: a single word has no order to swap, so "Mkozi" vs "Thabo Mkhozi" stays a real difference.
     *
     * @param list<string> $x
     * @param list<string> $y
     */
    private static function sameWordsAnyOrder(array $x, array $y): bool
    {
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];
        if (count($short) < 2) {
            return false;
        }
        foreach ($short as $w) {
            $hit = false;
            foreach ($long as $i => $l) {
                if (self::wordsAgree($w, $l) || self::near($w, $l, 2)) {
                    unset($long[$i]);
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    /** equal, or within $max edits — but only for words long enough (4+) for an edit to mean "a typo" rather than "another name" */
    private static function near(string $a, string $b, int $max): bool
    {
        if ($a === $b) {
            return true;
        }
        if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
            return false;
        }

        return levenshtein(self::ascii($a), self::ascii($b)) <= $max;
    }

    private static function ascii(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return $t === false || $t === '' ? $s : $t;
    }

    /** equal, or one is a single-letter initial of the other */
    private static function wordsAgree(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (mb_strlen($a) === 1) {
            return mb_substr($b, 0, 1) === $a;
        }
        if (mb_strlen($b) === 1) {
            return mb_substr($a, 0, 1) === $b;
        }

        return false;
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        $s = mb_strtolower($name);
        $s = str_replace('&', ' ', $s);
        $s = (string) preg_replace('/[^\pL\pN]+/u', ' ', $s);

        return array_values(array_filter(explode(' ', $s), fn ($w) => $w !== '' && ! in_array($w, self::IGNORED, true)));
    }
}
