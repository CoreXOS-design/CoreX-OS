<?php

namespace App\Services\Campaigns;

use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignSend;
use App\Models\Campaigns\CampaignSender;
use App\Services\Communications\MailFailureClassifier;
use App\Support\OutboundMailGuard;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

/**
 * AT-534 — replies, STOP replies and bounces (.ai/specs/campaigns.md §6.6). Reads each in-use sender's INBOX read-only (BODY.PEEK —
 * nothing is marked read, moved or deleted). classify()/process() work on the raw message text so they are testable without IMAP;
 * every update they make is idempotent, so re-reading a message changes nothing.
 */
class CampaignInboxChecker
{
    private const OUR_ID = '/cx-campaign-(\d+)-[a-z0-9]+@[A-Za-z0-9.\-]+/';

    /** Reason recorded when this environment may not open a real IMAP connection (§42 hard rule, ImapSentFolderAppender precedent). */
    public const OFF_HERE = 'off_on_this_server';

    public static function friendly(?string $reason): string
    {
        return $reason === self::OFF_HERE
            ? 'inbox checks only run on the live and Staging servers.'
            : (new MailFailureClassifier())->friendlyForConnect((string) $reason);
    }

    public function __construct(private CampaignSendService $sends, private MailFailureClassifier $classifier = new MailFailureClassifier())
    {
    }

    /** @return array<int, array{checked:int, error:?string}> sender id => outcome */
    public function checkAll(): array
    {
        // A campaign flips to 'done' the moment its last email is out, but replies / STOP / bounces to that last email arrive after — so
        // a recently finished campaign's sender is still watched for a while (campaigns.inbox_watch_after_done_days).
        $watchSince = now()->subDays((int) config('campaigns.inbox_watch_after_done_days'));
        $senderIds = Campaign::where(fn ($q) => $q
            ->whereIn('status', [Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED])
            ->orWhere(fn ($done) => $done->where('status', Campaign::STATUS_DONE)->where('done_at', '>=', $watchSince)))
            ->pluck('campaign_sender_id')->unique();
        $out = [];
        foreach (CampaignSender::whereIn('id', $senderIds)->get() as $sender) {
            $out[$sender->id] = $this->checkSender($sender);
        }

        return $out;
    }

    /** @return array{checked:int, error:?string} */
    public function checkSender(CampaignSender $sender): array
    {
        if (! OutboundMailGuard::isSendingConfirmed()) {
            $sender->forceFill(['last_inbox_error' => self::OFF_HERE])->save();

            return ['checked' => 0, 'error' => self::OFF_HERE];
        }

        $startedAt = now();
        $since = ($sender->last_inbox_check_at ?? now()->subDays(7))->copy()->subDay();
        $checked = 0;

        try {
            $client = $this->connect($sender);
            $folder = $client->getFolder('INBOX');
            if (! $folder) {
                throw new \RuntimeException('no INBOX folder');
            }
            $messages = $folder->query()->since($since)->leaveUnread()->setFetchBody(true)->limit(500)->get();
            foreach ($messages as $message) {
                $headers = (string) ($message->getHeader()?->raw ?? '');
                $raw = $headers . "\r\n\r\n" . (string) $message->getRawBody();
                $this->process($sender, $headers, $raw, (string) $message->getTextBody());
                $checked++;
            }
            $client->disconnect();
        } catch (\Throwable $e) {
            $reason = $this->classifier->classifyConnect($e->getMessage());
            $sender->forceFill(['last_inbox_error' => $reason])->save();
            Log::warning('Campaigns: inbox check failed', ['sender_id' => $sender->id, 'reason' => $reason]);

            return ['checked' => $checked, 'error' => $reason];
        }

        $sender->forceFill(['last_inbox_check_at' => $startedAt, 'last_inbox_error' => null])->save();

        return ['checked' => $checked, 'error' => null];
    }

    /**
     * Classify one inbound message and apply it. Returns what it was: 'bounce', 'soft_bounce', 'stop', 'reply', 'auto_reply' or
     * 'ignored' (not about any campaign send).
     */
    public function process(CampaignSender $sender, string $rawHeaders, string $rawMessage, string $textBody): string
    {
        $h = $this->parseHeaders($rawHeaders);
        $from = $this->addressOf($h['from'] ?? '');
        if ($from === CampaignLead::normaliseEmail($sender->email)) {
            return 'ignored'; // our own copy
        }

        if ($this->isBounce($h, $from)) {
            $send = $this->sendFromIds($rawMessage) ?? $this->sendFromFinalRecipient($rawMessage);
            if (! $send) {
                return 'ignored';
            }
            if ($this->isHardBounce($rawMessage)) {
                $this->sends->recordHardBounce($send);

                return 'bounce';
            }

            return 'soft_bounce';
        }

        if ($this->isAutoReply($h)) {
            return 'auto_reply';
        }

        $send = $this->sendFromIds(($h['in-reply-to'] ?? '') . ' ' . ($h['references'] ?? '')) ?? $this->sendFromSenderAddress($from);
        if (! $send) {
            return 'ignored';
        }

        // Only the plain-text part is read for STOP: the raw message also carries the quoted original, whose own footer says "STOP".
        if ($this->saysStop($textBody)) {
            $this->sends->recordStopReply($send);

            return 'stop';
        }
        $this->sends->recordReply($send);

        return 'reply';
    }

    /**
     * A permanent failure. The machine-readable "Status:" field decides (5.x.x hard, 4.x.x temporary). With no Status field, only a
     * real SMTP failure line counts (`550 5.1.1 …` or `Diagnostic-Code: smtp; 550 5.1.1 …`) — a "5.x.x"-looking number elsewhere in
     * the message (an IP address in a Received header, say) must never suppress a good address for good.
     */
    private function isHardBounce(string $rawMessage): bool
    {
        if (preg_match('/^\s*Status:\s*5\.\d{1,3}\.\d{1,3}/mi', $rawMessage)) {
            return true;
        }
        if (preg_match('/^\s*Status:\s*4\./mi', $rawMessage)) {
            return false;
        }

        return (bool) preg_match('/^\s*(?:Diagnostic-Code:\s*smtp;\s*)?5\d\d[ -]5\.\d{1,3}\.\d{1,3}\b/mi', $rawMessage);
    }

    public function saysStop(string $text): bool
    {
        $kept = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $t = trim($line);
            if (preg_match('/^(On .+wrote:|.*\swrote:$|-{2,}\s*Original Message\s*-{2,}|From:\s.+|Sent from my |_{5,})/i', $t)) {
                break; // everything after this is the quoted original
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $kept[] = $t;
        }

        return (bool) preg_match('/\b(STOP|UNSUBSCRIBE)\b/i', implode("\n", $kept));
    }

    /** @return array<string, string> lower-cased header name => unfolded value (first occurrence) */
    public function parseHeaders(string $raw): array
    {
        $raw = (string) preg_replace("/\r\n|\r/", "\n", $raw);
        $raw = (string) preg_replace("/\n[ \t]+/", ' ', $raw); // unfold
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                break;
            }
            if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] ??= trim($m[2]);
            }
        }

        return $out;
    }

    private function isBounce(array $h, string $from): bool
    {
        $local = strtolower(strstr($from, '@', true) ?: $from);
        if (in_array($local, ['mailer-daemon', 'postmaster'], true)) {
            return true;
        }

        return (bool) preg_match('/multipart\/report.*report-type\s*=\s*"?delivery-status/i', $h['content-type'] ?? '');
    }

    private function isAutoReply(array $h): bool
    {
        $auto = strtolower($h['auto-submitted'] ?? 'no');
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (isset($h['x-autoreply']) || isset($h['x-autorespond']) || isset($h['x-autoresponse'])) {
            return true;
        }
        if (preg_match('/^(auto[_-]?reply|auto_reply)$/i', $h['precedence'] ?? '')) {
            return true;
        }

        return (bool) preg_match('/^(automatic reply|auto(matic)?[ -]?reply|out of (the )?office|autoreply|auto:)/i', trim($h['subject'] ?? ''));
    }

    /** The send whose own Message-ID appears in $text (the stored id must match exactly — a guessed number is not enough). */
    private function sendFromIds(string $text): ?CampaignSend
    {
        if (! preg_match_all(self::OUR_ID, $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $match) {
            $send = CampaignSend::find((int) $match[1]);
            if ($send && strcasecmp((string) $send->message_id, $match[0]) === 0) {
                return $send;
            }
        }

        return null;
    }

    private function sendFromFinalRecipient(string $raw): ?CampaignSend
    {
        if (! preg_match('/^\s*(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^>\s]+)>?/mi', $raw, $m)) {
            return null;
        }

        return $this->sendFromSenderAddress(CampaignLead::normaliseEmail($m[1]));
    }

    /** Fallback: the latest sent email to this address within the reply window. */
    private function sendFromSenderAddress(string $email): ?CampaignSend
    {
        if ($email === '') {
            return null;
        }

        return CampaignSend::where('to_email', $email)
            ->where('status', CampaignSend::STATUS_SENT)
            ->where('sent_at', '>=', now()->subDays((int) config('campaigns.reply_fallback_days')))
            ->latest('sent_at')->first();
    }

    private function addressOf(string $header): string
    {
        if (preg_match('/<([^>]+)>/', $header, $m)) {
            return CampaignLead::normaliseEmail($m[1]);
        }

        return CampaignLead::normaliseEmail($header);
    }

    private function connect(CampaignSender $sender)
    {
        $timeout = max(1, (int) config('campaigns.imap_timeout_seconds'));
        $client = (new ClientManager([
            'default' => 'cmp',
            'accounts' => ['cmp' => [
                'host' => $sender->imapHost(),
                'port' => (int) $sender->imap_port,
                'protocol' => 'imap',
                'encryption' => $sender->imap_encryption === 'none' ? false : $sender->imap_encryption,
                'username' => $sender->username,
                'password' => $sender->encrypted_password, // decrypted by the model cast
                'validate_cert' => true,
                'timeout' => $timeout,
            ]],
        ]))->account();
        $client->connect();

        return $client;
    }

    /** Test connection, inbox leg only: log in and open INBOX. Null = OK, else the sanitised reason. */
    public function testInbox(CampaignSender $sender): ?string
    {
        if (! OutboundMailGuard::isSendingConfirmed()) {
            return self::OFF_HERE; // never a real IMAP login from a non-sending environment (§42)
        }

        try {
            $client = $this->connect($sender);
            $ok = (bool) $client->getFolder('INBOX');
            $client->disconnect();

            return $ok ? null : 'mailbox_not_found';
        } catch (\Throwable $e) {
            return $this->classifier->classifyConnect($e->getMessage());
        }
    }
}
