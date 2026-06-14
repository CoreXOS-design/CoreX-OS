<?php

namespace App\Services\Communications;

use App\Services\ContactDuplicateService;

/**
 * Canonicalises a communication identifier so flags/lookups match across
 * captures (AT-36). Email → lowercased/trimmed; everything else → the
 * last-9-digit phone form via ContactDuplicateService::normalizePhone(). A WA
 * jid ("27821234567@c.us") is reduced to its number first.
 */
class IdentifierNormalizer
{
    public static function normalize(?string $identifier): string
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return '';
        }

        // Email: must contain '@' AND a dot after it (jids like x@c.us also
        // contain '@', so treat anything that looks like a phone jid as phone).
        if (str_contains($identifier, '@') && ! preg_match('/@[sc]\.|@s\.whatsapp\.net|@g\.us/i', $identifier)) {
            return strtolower($identifier);
        }

        // WA jid → strip suffix to the number part, then phone-normalise.
        $number = str_contains($identifier, '@') ? substr($identifier, 0, strpos($identifier, '@')) : $identifier;
        $normalised = app(ContactDuplicateService::class)->normalizePhone($number);

        return $normalised ?? strtolower($identifier);
    }
}
