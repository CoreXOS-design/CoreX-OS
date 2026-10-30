<?php

namespace App\Support;

/**
 * AT-516 - the ONE place a rental-application identity / account number is masked for display. Used by the corrected-details
 * email to the applicant and by the review screen's Changes panel (both through RentalApplicationDetailEditor::displayPair()),
 * so the two can never show different amounts of the number. Display and email only: the change-log rows keep the full values
 * as evidence.
 *
 * Rule (Johan): old and new of the same length -> the positions that changed are shown in clear and every other position is *
 * ("8501015800083" -> "8001015800083" reads "*5***********" -> "*0***********"). Different lengths, or more than half of the
 * characters changed -> only the last 4 characters are shown, the rest *. A value of 4 characters or fewer is never shown in
 * clear in that fallback (the last 4 would be the whole number).
 */
final class SensitiveValueMask
{
    /** Field keys that hold an identity or account number. A path such as "co_applicant.id_number" is judged by its last segment. */
    public const SENSITIVE_KEYS = ['id_number', 'spouse_id', 'passport_number', 'bank_account_no', 'income_tax_no'];

    public static function isSensitive(string $path): bool
    {
        if (str_starts_with($path, 'custom_field_values.')) {
            return false;
        }
        $parts = explode('.', $path);

        return in_array(end($parts), self::SENSITIVE_KEYS, true);
    }

    /**
     * Mask an old/new pair for display. A missing side stays missing (null); the other side falls back to the last-4 rule.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function pair(?string $old, ?string $new): array
    {
        if ($old === null || $old === '' || $new === null || $new === '') {
            return [self::lastFour($old), self::lastFour($new)];
        }

        $oldChars = mb_str_split($old);
        $newChars = mb_str_split($new);
        if (count($oldChars) !== count($newChars)) {
            return [self::lastFour($old), self::lastFour($new)];
        }

        $changed = [];
        foreach ($oldChars as $i => $char) {
            if ($char !== $newChars[$i]) {
                $changed[] = $i;
            }
        }
        if (count($changed) * 2 > count($oldChars)) {
            return [self::lastFour($old), self::lastFour($new)];
        }

        $show = array_flip($changed);
        $apply = fn (array $chars) => implode('', array_map(fn ($char, $i) => isset($show[$i]) ? $char : '*', $chars, array_keys($chars)));

        return [$apply($oldChars), $apply($newChars)];
    }

    /** One value on its own (nothing to compare against): only the last 4 characters, the rest *. */
    public static function lastFour(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $chars = mb_str_split($value);
        $count = count($chars);
        if ($count <= 4) {
            return str_repeat('*', $count);
        }

        return str_repeat('*', $count - 4) . implode('', array_slice($chars, -4));
    }
}
