<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Mail\TitleDeeds\TitleDeedSignLinkMail;
use App\Models\Agency;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSigningLink;
use App\Models\User;
use App\Services\Distribution\SignedDocumentDistributionService;
use App\Support\WhatsAppNumberFormatter;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/title-deed-register.md section 14.10 - sign by link, as a second way to sign. Sending the link (email / honest
 * WhatsApp / QR), what the public page records, and the status the staff screen polls.
 *
 * WHERE a link may go: an OWNER collector - only that owner's stored email / WhatsApp number. A REPRESENTATIVE - the email or
 * mobile captured at the counter for that person (allowed here because the right to collect was already established by the PIN
 * or the owners' consent before the link could be generated; the link only records the receipt).
 */
class TitleDeedSigningService
{
    public function __construct(private TitleDeedHandoverService $handover) {}

    /** Email the link. Owner: the stored address only. Representative: the address captured at the counter. */
    public function sendEmail(User $by, TitleDeed $deed, TitleDeedSigningLink $link): array
    {
        $link = $this->liveLink($deed, $link);
        $email = $this->emailFor($deed, $link);
        if (! $email) {
            $this->ledger($deed, $link, TitleDeedEvent::CHANNEL_EMAIL, null, TitleDeedEvent::STATUS_SKIPPED, $by, 'No email address for ' . $link->collector_name . '.');
            throw new TitleDeedException($link->collector_type === 'owner'
                ? 'No email address is stored for ' . $link->collector_name . '.'
                : 'Enter an email address for ' . $link->collector_name . ' first.');
        }

        $mail = new TitleDeedSignLinkMail(
            $link->collector_name, $deed->identified_as, $link->url() ?? '', $link->expires_at->format('d M Y H:i'), $this->agencyName($deed), $by->name,
        );
        try {
            $result = app(SignedDocumentDistributionService::class)->sendGenericMail($email, $mail, $by);
        } catch (\Throwable $e) {
            report($e);
            $result = ['status' => 'failed', 'error' => 'The email could not be sent.'];
        }
        $ok = ($result['status'] ?? '') === 'sent';
        $this->ledger($deed, $link, TitleDeedEvent::CHANNEL_EMAIL, TitleDeedDestinations::maskEmail($email), $ok ? TitleDeedEvent::STATUS_SENT : TitleDeedEvent::STATUS_FAILED, $by, $ok ? null : ($result['error'] ?? 'The email did not send.'));
        TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_SENT,
            'Signing link for ' . $link->collector_name . ($ok ? ' emailed to ' : ' could NOT be emailed to ') . TitleDeedDestinations::maskEmail($email) . '.', null, null, $by);

        return ['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : ($result['error'] ?? null)];
    }

    /**
     * Prepare the WhatsApp message: the honest pattern - a row `prepared`, then "Open WhatsApp and send" records the open, and only
     * then "Mark as sent" / "Not sent" (TitleDeedNotifier). Nothing is claimed as sent here.
     */
    public function prepareWhatsapp(User $by, TitleDeed $deed, TitleDeedSigningLink $link): TitleDeedEvent
    {
        $link = $this->liveLink($deed, $link);
        $digits = $this->whatsappDigitsFor($deed, $link);
        if (! $digits) {
            throw new TitleDeedException($link->collector_type === 'owner'
                ? 'No mobile number is stored for ' . $link->collector_name . '.'
                : 'Enter a mobile number for ' . $link->collector_name . ' first.');
        }

        $text = 'Hi ' . $this->firstName($link->collector_name) . ', please confirm you are collecting the title deed for ' . $deed->identified_as
            . ' at ' . $this->agencyName($deed) . ' by signing here: ' . $link->url() . ' (works once, until ' . $link->expires_at->format('H:i') . ').';

        $event = $this->ledger($deed, $link, TitleDeedEvent::CHANNEL_WHATSAPP, TitleDeedDestinations::maskPhone($digits), TitleDeedEvent::STATUS_PREPARED, $by, null,
            Crypt::encryptString(json_encode(['text' => $text, 'to' => $digits])));
        TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_SENT,
            'WhatsApp for the signing link to ' . $link->collector_name . ' (' . TitleDeedDestinations::maskPhone($digits) . ') prepared - waiting for a person to open and send it.', null, null, $by);

        return $event;
    }

    /** The link as a QR code (PNG data URI) for the collector to scan from the staff screen. Null when the link is not live. */
    public function qrDataUri(TitleDeedSigningLink $link, int $size = 360): ?string
    {
        $url = $link->url();
        if (! $url) {
            return null;
        }
        $qr = new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::High, size: $size, margin: 10);

        return (new PngWriter())->write($qr)->getDataUri();
    }

    /** Save the address / number captured at the counter for a representative (can be changed while the link is live). */
    public function setRepresentativeContact(TitleDeed $deed, TitleDeedSigningLink $link, ?string $email, ?string $mobile): TitleDeedSigningLink
    {
        $link = $this->liveLink($deed, $link);
        if ($link->collector_type !== 'representative') {
            throw new TitleDeedException('The link for an owner only ever goes to the email or number stored for that owner.');
        }
        $email = trim((string) $email);
        $mobile = trim((string) $mobile);
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new TitleDeedException('That email address does not look right.');
        }
        if ($mobile !== '' && strlen(WhatsAppNumberFormatter::forDeepLink($mobile, '+27')) < 10) {
            throw new TitleDeedException('That mobile number does not look right.');
        }
        $link->forceFill(['send_email' => $email !== '' ? $email : null, 'send_mobile' => $mobile !== '' ? $mobile : null])->save();

        return $link;
    }

    /* ═════════ The public page ═════════ */

    /** @return array{state:string, link:?TitleDeedSigningLink, deed:?TitleDeed} state: open | signed | used | expired | cancelled | invalid */
    public function resolve(string $token): array
    {
        $link = TitleDeedSigningLink::findByToken($token);
        $deed = $link ? TitleDeed::withoutGlobalScopes()->withTrashed()->find($link->title_deed_id) : null;
        if (! $link || ! $deed || $deed->trashed()) {
            return ['state' => 'invalid', 'link' => null, 'deed' => null];
        }
        if ($deed->isCollected()) {
            return ['state' => 'used', 'link' => $link, 'deed' => $deed];
        }

        $state = match ($link->effectiveStatus()) {
            TitleDeedSigningLink::STATUS_WAITING => 'open',
            TitleDeedSigningLink::STATUS_SIGNED => 'signed',
            TitleDeedSigningLink::STATUS_USED => 'used',
            TitleDeedSigningLink::STATUS_CANCELLED => 'cancelled',
            default => 'expired',
        };

        return ['state' => $state, 'link' => $link, 'deed' => $deed];
    }

    public function recordOpen(TitleDeedSigningLink $link): void
    {
        try {
            if (TitleDeedSigningLink::withoutGlobalScopes()->whereKey($link->id)->whereNull('first_opened_at')->update(['first_opened_at' => now()])) {
                $deed = TitleDeed::withoutGlobalScopes()->find($link->title_deed_id);
                if ($deed) {
                    TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_OPENED, $link->collector_name . ' opened the signing link.');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The collector confirms and signs. Single use (row lock; the first response wins), expiring, tied to this deed and this
     * named collector. Captures signature, typed name, time, IP and device. It does NOT collect the deed: staff press "Hand the
     * deed over" on their screen, which finishes from what is recorded here.
     *
     * @return array{state:string, message:string}
     */
    public function sign(string $token, string $typedName, string $signatureImage, bool $confirmed, ?string $ip, ?string $userAgent): array
    {
        $typedName = trim($typedName);
        if (! $confirmed) {
            return ['state' => 'error', 'message' => 'Please tick the box to confirm.'];
        }
        if (mb_strlen($typedName) < 2) {
            return ['state' => 'error', 'message' => 'Please type your full name.'];
        }
        if (trim($signatureImage) === '') {
            return ['state' => 'error', 'message' => 'Please draw your signature in the box.'];
        }

        $stored = [];
        try {
            return DB::transaction(function () use ($token, $typedName, $signatureImage, $ip, $userAgent, &$stored) {
                $found = TitleDeedSigningLink::findByToken($token);
                $link = $found ? TitleDeedSigningLink::withoutGlobalScopes()->lockForUpdate()->find($found->id) : null;
                $deed = $link ? TitleDeed::withoutGlobalScopes()->find($link->title_deed_id) : null;
                if (! $link || ! $deed || $deed->trashed()) {
                    return ['state' => 'invalid', 'message' => 'This link is not available.'];
                }
                if ($deed->isCollected() || $link->status === TitleDeedSigningLink::STATUS_USED) {
                    return ['state' => 'used', 'message' => 'This title deed has already been handed over. Thank you.'];
                }
                if ($link->status === TitleDeedSigningLink::STATUS_SIGNED) {
                    return ['state' => 'signed', 'message' => 'You have already signed - thank you. The staff member will finish the handover.'];
                }
                if ($link->status === TitleDeedSigningLink::STATUS_CANCELLED) {
                    return ['state' => 'cancelled', 'message' => 'This link was cancelled. Please ask the staff member for a new one.'];
                }
                if ($link->expires_at->isPast()) {
                    return ['state' => 'expired', 'message' => 'This link has run out. Please ask the staff member for a new one.'];
                }

                $path = $this->handover->storeSignatureImage($signatureImage, $deed->id, $stored);
                $link->forceFill([
                    'status' => TitleDeedSigningLink::STATUS_SIGNED,
                    'signed_at' => now(),
                    'signed_typed_name' => mb_substr($typedName, 0, 191),
                    'signature_path' => $path,
                    'signed_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                    'signed_user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                    'token_encrypted' => null,
                ])->save();
                TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_SIGNED,
                    $link->collector_name . ' signed on their own phone as "' . $typedName . '" (' . now()->format('H:i') . '). Waiting for staff to hand the deed over.',
                    null, ['ip' => $ip, 'device' => $userAgent ? mb_substr($userAgent, 0, 200) : null]);

                return ['state' => 'done', 'message' => 'Thank you - your signature is recorded. The staff member will finish the handover now.'];
            });
        } catch (TitleDeedException $e) {
            $this->handover->discardFiles($stored);

            return ['state' => 'error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->handover->discardFiles($stored);
            throw $e;
        }
    }

    /**
     * What the staff screen polls: the deed's latest signing link.
     *
     * @return array<string,mixed>
     */
    public function status(TitleDeed $deed): array
    {
        $link = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)->latest('id')->first();
        if (! $link) {
            return ['state' => 'none', 'link_id' => null, 'collector' => null, 'signed_name' => null, 'signed_at' => null, 'expires_at' => null];
        }

        return [
            'state' => $deed->isCollected() && $link->status === TitleDeedSigningLink::STATUS_USED ? 'used' : $link->effectiveStatus(),
            'link_id' => $link->id,
            'collector' => $link->collector_name,
            'signed_name' => $link->signed_typed_name,
            'signed_at' => $link->signed_at?->format('H:i'),
            'expires_at' => $link->expires_at?->toIso8601String(),
        ];
    }

    /* ── helpers ── */

    private function liveLink(TitleDeed $deed, TitleDeedSigningLink $link): TitleDeedSigningLink
    {
        $row = TitleDeedSigningLink::query()->find($link->id);
        if (! $row || (int) $row->title_deed_id !== (int) $deed->id || ! $row->isLive()) {
            throw new TitleDeedException('That signing link is no longer live. Generate a new one.');
        }

        return $row;
    }

    private function emailFor(TitleDeed $deed, TitleDeedSigningLink $link): ?string
    {
        if ($link->collector_type === 'owner') {
            $owner = TitleDeedOwner::query()->where('title_deed_id', $deed->id)->where('contact_id', $link->collector_contact_id)->first();

            return TitleDeedDestinations::emailFor($owner ? TitleDeedDestinations::liveContact($deed, $owner) : null);
        }
        $e = trim((string) $link->send_email);

        return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    private function whatsappDigitsFor(TitleDeed $deed, TitleDeedSigningLink $link): ?string
    {
        if ($link->collector_type === 'owner') {
            $owner = TitleDeedOwner::query()->where('title_deed_id', $deed->id)->where('contact_id', $link->collector_contact_id)->first();

            return TitleDeedDestinations::whatsappFor($owner ? TitleDeedDestinations::liveContact($deed, $owner) : null)['digits'] ?? null;
        }
        $m = trim((string) $link->send_mobile);
        $digits = $m === '' ? '' : WhatsAppNumberFormatter::forDeepLink($m, '+27');

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    private function ledger(TitleDeed $deed, TitleDeedSigningLink $link, string $channel, ?string $masked, string $status, ?User $by, ?string $error = null, ?string $payload = null): TitleDeedEvent
    {
        return TitleDeedEvent::create([
            'agency_id' => $deed->agency_id,
            'title_deed_id' => $deed->id,
            'contact_id' => $link->collector_type === 'owner' ? $link->collector_contact_id : null,
            'owner_name' => $link->collector_name . ($link->collector_type === 'representative' ? ' (representative)' : ''),
            'kind' => TitleDeedEvent::KIND_SIGNING_LINK,
            'channel' => $channel,
            'destination_masked' => $masked,
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'user_id' => $by?->id,
            'payload_encrypted' => $payload,
        ]);
    }

    private function agencyName(TitleDeed $deed): string
    {
        return Agency::withoutGlobalScopes()->find($deed->agency_id)?->name ?: 'our office';
    }

    private function firstName(string $full): string
    {
        return trim(explode(' ', trim($full))[0] ?? '');
    }
}
