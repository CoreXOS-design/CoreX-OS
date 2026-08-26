<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Builds an iCalendar (.ics) attachment.
 *
 * Spec: .ai/specs/webinar-registration.md §6.3
 *
 * RFC 5545 is unforgiving in a way that is invisible until a real client refuses the
 * file, so the four rules that actually break calendars are enforced here rather than
 * trusted to the caller:
 *
 *   1. CRLF line endings. Not "\n". Outlook rejects the file outright.
 *   2. TEXT values escape backslash, semicolon, comma and newline. A description
 *      containing ", " silently truncates at the comma otherwise.
 *   3. Lines fold at 75 OCTETS (not characters) with a leading space on the
 *      continuation. Folding mid-UTF-8-sequence produces mojibake, so the folder
 *      counts bytes and never splits a multi-byte character.
 *   4. UTC times with a trailing Z. Local times need a VTIMEZONE block to be
 *      unambiguous, and a webinar invite that lands an hour out in one attendee's
 *      calendar is worse than no invite.
 *
 * METHOD:PUBLISH, not REQUEST: we are handing someone an event for their own diary,
 * not inviting them to one we are tracking RSVPs for. REQUEST makes clients send
 * acceptance mail back to the From address, which nothing here reads.
 */
class IcsCalendarInvite
{
    private const CRLF = "\r\n";

    /**
     * @param  string  $uid       Stable per real-world event+person. A re-send with the
     *                            SAME uid updates the existing diary entry; a new one
     *                            duplicates it. This is the whole reason it is a
     *                            parameter and not generated internally.
     * @param  int     $sequence  Must INCREASE for a client to accept an update. A
     *                            resend that reuses the sequence is ignored, and the
     *                            attendee keeps the stale time.
     */
    public static function build(
        string $uid,
        string $summary,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?string $description = null,
        ?string $location = null,
        ?string $url = null,
        int $sequence = 0,
        ?string $organiserName = null,
        ?string $organiserEmail = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RR Technologies//CoreX OS//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::escapeText($uid),
            'DTSTAMP:' . self::utc(Carbon::now()),
            'DTSTART:' . self::utc($start),
            'DTEND:' . self::utc($end),
            'SEQUENCE:' . max(0, $sequence),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'SUMMARY:' . self::escapeText($summary),
        ];

        if ($description !== null && trim($description) !== '') {
            $lines[] = 'DESCRIPTION:' . self::escapeText($description);
        }

        if ($location !== null && trim($location) !== '') {
            $lines[] = 'LOCATION:' . self::escapeText($location);
        }

        if ($url !== null && trim($url) !== '') {
            $lines[] = 'URL:' . self::escapeText($url);
        }

        if ($organiserEmail !== null && trim($organiserEmail) !== '') {
            $lines[] = 'ORGANIZER;CN=' . self::escapeText($organiserName ?: 'CoreX OS')
                     . ':mailto:' . $organiserEmail;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map([self::class, 'fold'], $lines)) . self::CRLF;
    }

    /** RFC 5545 date-time, UTC. The Z is what makes it unambiguous. */
    private static function utc(DateTimeInterface $dt): string
    {
        return Carbon::instance($dt)->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value.
     *
     * ORDER MATTERS: backslash first. Escaping it after the others would go back over
     * the backslashes this function just inserted and double them.
     */
    private static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    /**
     * Fold to 75 octets per line, continuation lines prefixed with one space.
     *
     * Counts BYTES, because the RFC limit is octets — but never splits inside a
     * multi-byte character, which would corrupt it. So the cut point walks back off
     * any UTF-8 continuation byte (10xxxxxx) before slicing.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out       = '';
        $remaining = $line;
        $limit     = 75;

        while (strlen($remaining) > $limit) {
            $cut = $limit;

            // Walk back off a continuation byte so a character is never split.
            while ($cut > 1 && (ord($remaining[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $out      .= substr($remaining, 0, $cut) . self::CRLF . ' ';
            $remaining = substr($remaining, $cut);

            // Continuation lines carry a leading space, so they have one octet less.
            $limit = 74;
        }

        return $out . $remaining;
    }
}
