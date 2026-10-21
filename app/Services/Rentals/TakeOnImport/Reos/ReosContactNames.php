<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/**
 * AT-479 / rental-takeon-import.md R6 — how an Owner / Tenant cell becomes a contact. Pure text rules, no database.
 *
 *   company → one ENTITY contact (the entity path needs no registration number — checked)
 *   joint   → ONE contact, full name kept ("William & Dorothy" / "Geyser"); never split on the first space
 *   person  → first word / the rest, as the existing take-on does
 */
final class ReosContactNames
{
    /** Whole words (case-insensitive). "(PTY) LTD", "Pty Ltd", "CC", "Trust", "Inc." … */
    private const COMPANY = '/(?<![\pL\pN])(?:\(\s*pty\s*\)|pty|ltd|limited|cc|trust|inc|npc|investments?|holdings?|church|jv)(?![\pL\pN])/iu';

    public static function isCompany(string $name): bool
    {
        return (bool) preg_match(self::COMPANY, $name);
    }

    /**
     * Is this CoreX contact a company? A company record, OR a person-kind record whose name reads as a company: CoreX holds thousands of
     * those (10,706 of 10,807 contacts on QA1 are "natural person" records, many named "… Pty Ltd" — Johan's BEBO Investments).
     */
    public static function isCompanyContact(\App\Models\Contact $c): bool
    {
        return $c->isEntity() || self::isCompany(trim((string) $c->full_name)) || self::isCompany(trim($c->first_name . ' ' . $c->last_name));
    }

    /** "X & Y Smith", "X and Y Smith" — and not a company. */
    public static function isJoint(string $name): bool
    {
        return ! self::isCompany($name) && (bool) preg_match('/\S\s*&\s*\S|\s+and\s+/iu', $name);
    }

    /** @return array{kind:string, first_name:string, last_name:string, entity_name:?string} */
    public static function classify(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (self::isCompany($name)) {
            // Same shape ComposeSellerService::resolveOrCreateEntitySellerContact() writes: first_name mirrors the name (NOT NULL).
            return ['kind' => 'company', 'first_name' => $name, 'last_name' => '', 'entity_name' => $name];
        }

        $words = explode(' ', $name);

        if (self::isJoint($name) && count($words) > 1) {
            $last = array_pop($words);

            return ['kind' => 'joint', 'first_name' => implode(' ', $words), 'last_name' => $last, 'entity_name' => null];
        }

        $parts = preg_split('/\s+/u', $name, 2);

        return ['kind' => 'person', 'first_name' => $parts[0] ?? $name, 'last_name' => $parts[1] ?? '', 'entity_name' => null];
    }

    /** Matching key for "the same owner on several rows": lower-case words, & = and, punctuation gone, words sorted. */
    public static function key(?string $name): string
    {
        $s = mb_strtolower((string) $name);
        $s = str_replace('&', ' and ', $s);
        $s = (string) preg_replace('/[^\pL\pN]+/u', ' ', $s);
        $words = array_values(array_filter(explode(' ', $s), fn ($w) => $w !== ''));
        sort($words);

        return implode(' ', $words);
    }

    /**
     * A cell that may hold several values ("a@x.test; b@y.test", "082 111 2222 / 031 555 1212").
     *
     * @return list<string>
     */
    public static function splitValues(?string $cell, bool $email): array
    {
        if ($cell === null || trim($cell) === '') {
            return [];
        }
        $parts = $email ? preg_split('/[;,\s]+/u', $cell) : preg_split('/[;\/]|\s{2,}|,/u', $cell);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if ($email && ! filter_var($p, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (! $email && strlen(preg_replace('/\D/', '', $p)) < 9) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }
}
