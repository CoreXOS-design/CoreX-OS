<?php

namespace App\Services\Communications;

use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * AT-257 — true non-destructive IMAP fetch (BODY.PEEK[]).
 *
 * webklex's default "peek" is a FETCH-THEN-RESTORE: it fetches `RFC822.TEXT`
 * (which sets `\Seen` on the server) and then issues `STORE -FLAGS (\Seen)` to
 * put it back. If that restore step does not run — a body-parse error (swallowed
 * by the poller's safe() wrapper), the pcntl poll-budget firing mid-parse, a TLS
 * drop, or a worker kill between the two ops — the message is left marked READ.
 * That was proven (against the real Dovecot) to be the cause of AT-257's
 * intermittent "emails show read that I never opened".
 *
 * This fetches header + text via `BODY.PEEK[HEADER]` + `BODY.PEEK[TEXT]` in one
 * command — the peeking form NEVER sets `\Seen`, so there is no restore and no
 * interrupt window. The peeked raw is rehydrated into a normal webklex Message so
 * every downstream accessor (getTextBody / getHTMLBody / getAttachments / getFrom
 * / getMessageId) works unchanged.
 *
 * Note: webklex's single-item fetch parser cannot match a `BODY.PEEK[...]` request
 * against the server's `BODY[...]` response, so a single-item peek THROWS — the
 * two-item form is used deliberately (verified against the live server).
 */
class PeekingMessageFetcher
{
    /**
     * Fetch a message by UID with a true peek. Returns a rehydrated Message, or
     * null when the peek yields no usable content (caller skips + counts an error).
     * The message's folder must already be selected on $client (the caller is
     * iterating that folder).
     */
    public static function peek($client, int $uid): ?Message
    {
        $conn = $client->getConnection();

        $data = $conn->fetch(['BODY.PEEK[HEADER]', 'BODY.PEEK[TEXT]'], [$uid], null, IMAP::ST_UID)
            ->validatedData();

        $row = $data[$uid] ?? null;
        if (!is_array($row) || !array_key_exists('BODY[HEADER]', $row)) {
            return null;
        }

        $header = (string) ($row['BODY[HEADER]'] ?? '');
        $body   = (string) ($row['BODY[TEXT]'] ?? '');

        // Flags via a FETCH FLAGS (does not set \Seen), so the rehydrated message
        // reports its true server-side seen/unseen state.
        $flagsData = $conn->flags([$uid], IMAP::ST_UID)->validatedData();
        $flags = (is_array($flagsData[$uid] ?? null)) ? $flagsData[$uid] : [];

        // FT_PEEK so the constructed message never triggers a seen-setting refetch.
        return Message::make($uid, null, $client, $header, $body, $flags, IMAP::FT_PEEK, IMAP::ST_UID);
    }
}
