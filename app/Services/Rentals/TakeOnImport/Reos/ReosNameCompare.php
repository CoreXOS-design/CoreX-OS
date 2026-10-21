<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/**
 * AT-479 (Johan, 21 Oct) — a contact matched on phone / e-mail can be a DIFFERENT person than the one reOS names (a shared
 * number, a re-used e-mail, a company whose phone is its contact person's). The review screen flags it; this is the comparison.
 *
 * Names are compared normalised — case, spacing, punctuation, "&"/"and" and legal suffixes (Pty, Ltd, Limited) ignored — and
 * an initial matches a full first name ("A Smith" = "Andre Smith"). A name matches when every word of the SHORTER name is found in
 * the longer one, so a joint "William & Dorothy Geyser" matching one spouse, or a missing middle name, is not noise.
 * A flag is a WARNING for the person looking at the row, never a blocker.
 */
final class ReosNameCompare
{
    public const OK = null;
    public const DIFFERS = 'differs';
    public const PERSON_FOR_COMPANY = 'person_for_company';

    private const IGNORED = ['and', 'the', 'pty', 'ltd', 'limited'];

    /**
     * @param bool $contactIsEntity  the CoreX contact is a company / trust (contact_kind = entity)
     * @return string|null  null = same name; DIFFERS; PERSON_FOR_COMPANY (reOS names a company, CoreX has a person)
     */
    public static function flag(string $reosName, string $contactName, bool $contactIsEntity): ?string
    {
        if (ReosContactNames::isCompany($reosName) && ! $contactIsEntity) {
            return self::PERSON_FOR_COMPANY;
        }

        return self::sameName($reosName, $contactName) ? self::OK : self::DIFFERS;
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
