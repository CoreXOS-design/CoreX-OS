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
     * iterating that folder); pass its path as $folderPath so the rehydrated
     * Message can be given a folder path even when the client's active_folder is
     * null -- see the note at the Message::make() call below.
     */
    public static function peek($client, int $uid, ?string $folderPath = null): ?Message
    {
        return self::fetchAndRehydrate($client, $uid, $folderPath, 'BODY.PEEK[TEXT]', 'BODY[TEXT]');
    }

    /**
     * 2026-09-08 (headers-first, item 2) — the SAME non-destructive peek, but
     * without ever requesting the message TEXT. Every header-derived accessor
     * (getFrom/getSubject/getDate/getMessageId) works exactly as on a full
     * peek(); getTextBody()/getHTMLBody()/getAttachments() return empty,
     * because the body was never asked for.
     *
     * Still a two-item fetch (BODY.PEEK[HEADER] + FLAGS, not header alone) —
     * see the class docblock: a single-item BODY.PEEK[...] fetch throws in
     * webklex's response parser, verified against the live server. FLAGS is
     * already the cheapest possible second item and — bonus — means the
     * separate flags() round trip peek() makes isn't needed here either.
     */
    public static function peekHeader($client, int $uid, ?string $folderPath = null): ?Message
    {
        return self::fetchAndRehydrate($client, $uid, $folderPath, 'FLAGS', null);
    }

    private static function fetchAndRehydrate($client, int $uid, ?string $folderPath, string $secondItem, ?string $bodyKey): ?Message
    {
        $conn = $client->getConnection();

        $data = $conn->fetch(['BODY.PEEK[HEADER]', $secondItem], [$uid], null, IMAP::ST_UID)
            ->validatedData();

        $row = $data[$uid] ?? null;
        if (!is_array($row) || !array_key_exists('BODY[HEADER]', $row)) {
            return null;
        }

        $header = (string) ($row['BODY[HEADER]'] ?? '');
        $body   = $bodyKey !== null ? (string) ($row[$bodyKey] ?? '') : '';

        // Flags: either already in $row (the header+FLAGS fetch above) or via a
        // separate FETCH FLAGS (peek()'s header+TEXT fetch doesn't return them).
        // Neither path sets \Seen.
        if ($bodyKey === null) {
            $flags = is_array($row['FLAGS'] ?? null) ? $row['FLAGS'] : [];
        } else {
            $flagsData = $conn->flags([$uid], IMAP::ST_UID)->validatedData();
            $flags = (is_array($flagsData[$uid] ?? null)) ? $flagsData[$uid] : [];
        }

        // Message::make() does `setFolderPath($client->getFolderPath())`, and that
        // getter returns the client's active_folder, which is nullable and is NOT
        // set by the raw-connection fetch above (we deliberately bypass the
        // openFolder() path webklex normally routes through). When it happens to
        // be null, webklex's own typed `string $folder_path` property rejects it
        // with a TypeError that escapes the poller's per-message guard and fails
        // the WHOLE PollMailboxJob -- 83 aborted polls between 2026-08-18 and
        // 2026-08-27. The caller knows which folder it is iterating, so supply it.
        // The previous value is restored so webklex's own select-state machine is
        // left exactly as it was found.
        //
        // 2026-09-09 (Johan, poller-reliability incident) — the guard above only
        // ever covered `$previousFolder === null`, an INCOMPLETE fix for the
        // TypeError it claims to close: on a multi-folder poll (Inbox then Sent
        // in the same connected session), active_folder can be STALE — set to
        // the PREVIOUS folder's path, not null — when this method runs for the
        // new folder. `folder->query()` re-selects the folder via openFolder(),
        // which is the normal path and keeps active_folder correct; but this
        // method's own raw fetch does not go through that, and if this is
        // called before the first query()-driven select for a folder resolves,
        // or in any other ordering where active_folder does not yet match
        // $folderPath, the old `=== null` check silently did nothing and the
        // SAME TypeError reproduced — confirmed live against the real server:
        // reproduced twice in six real polls, both on the no-cursor (cold
        // start / UIDVALIDITY-reset) path where a fresh multi-folder session
        // is most likely to hit this exact ordering.
        //
        // Fixed to the real invariant: active_folder must equal $folderPath
        // before Message::make() reads it, full stop — not "must not be
        // null". Correct regardless of whether it started null or wrong.
        $previousFolder = $client->getFolderPath();
        $needsFix = $folderPath !== null && $folderPath !== '' && $previousFolder !== $folderPath;
        if ($needsFix) {
            $client->setActiveFolder($folderPath);
        }

        try {
            // FT_PEEK so the constructed message never triggers a seen-setting refetch.
            return Message::make($uid, null, $client, $header, $body, $flags, IMAP::FT_PEEK, IMAP::ST_UID);
        } finally {
            if ($needsFix) {
                $client->setActiveFolder($previousFolder);
            }
        }
    }
}
