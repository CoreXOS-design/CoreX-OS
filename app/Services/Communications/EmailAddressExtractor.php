<?php

namespace App\Services\Communications;

/**
 * Single source for pulling normalised e-mail addresses out of a webklex
 * message address field (AT-40).
 *
 * webklex/php-imap 6.x `getFrom()/getTo()/getCc()` return a
 * `Webklex\PHPIMAP\Attribute` which implements ArrayAccess but NOT Traversable
 * — a plain `foreach` over it yields nothing (the original AT-33 bug that left
 * `from_identifier` NULL on every captured row). The Address objects must be
 * read via `->all()`. Both the live poller and the pending-reprocess command
 * extract addresses through here so the two paths can never diverge again.
 */
class EmailAddressExtractor
{
    /**
     * Normalise an address attribute to a list of lowercased e-mail strings.
     *
     * @param mixed $attribute result of $message->getFrom()/getTo()/getCc()
     * @return string[]
     */
    public static function normalize($attribute): array
    {
        $items = self::toAddressList($attribute);

        $out = [];
        foreach ($items as $addr) {
            $mail = self::mailOf($addr);
            if ($mail !== null && $mail !== '') {
                $out[] = strtolower(trim($mail));
            }
        }

        return array_values(array_unique($out));
    }

    /** First normalised address, or null. */
    public static function first($attribute): ?string
    {
        return self::normalize($attribute)[0] ?? null;
    }

    /** Coerce a webklex Attribute (or array/iterable) into a flat list. */
    private static function toAddressList($attribute): array
    {
        if (is_object($attribute) && method_exists($attribute, 'all')) {
            $all = $attribute->all();

            return is_array($all) ? $all : (array) $all;
        }
        if (is_iterable($attribute)) {
            return is_array($attribute) ? $attribute : iterator_to_array($attribute);
        }

        return [];
    }

    /** Pull the e-mail out of a webklex Address, or accept a raw string. */
    private static function mailOf($addr): ?string
    {
        if (is_string($addr)) {
            return $addr;
        }
        if (! is_object($addr)) {
            return null;
        }

        $mail = $addr->mail ?? null;
        if ($mail) {
            return (string) $mail;
        }

        // Fallbacks for odd encodings: parse "Name <mail>" from ->full, then
        // any bare address from the stringified value.
        foreach ([$addr->full ?? null, method_exists($addr, '__toString') ? (string) $addr : null] as $candidate) {
            if (! $candidate) {
                continue;
            }
            if (preg_match('/<([^>]+@[^>]+)>/', (string) $candidate, $m)) {
                return $m[1];
            }
            if (preg_match('/[^\s<>]+@[^\s<>]+/', (string) $candidate, $m)) {
                return $m[0];
            }
        }

        return null;
    }
}
