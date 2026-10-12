<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Mail\TitleDeeds\TitleDeedCollectedMail;
use App\Mail\TitleDeeds\TitleDeedConsentMail;
use App\Mail\TitleDeeds\TitleDeedNoticeMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\User;
use App\Services\Distribution\SignedDocumentDistributionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/title-deed-register.md §8.1 / §8.4 / §8.5 — every message the register sends to an owner, and the ledger row
 * that proves it.
 *
 * Destinations are ONLY what is stored on the contact (TitleDeedDestinations); no method here accepts an address or number.
 * Email goes out through the existing agency path (SignedDocumentDistributionService::sendGenericMail — the agent's own
 * mailbox, and the non-production redirect / suppress guard). WhatsApp is the click-to-WhatsApp pattern: CoreX cannot send
 * it, so a WhatsApp row is `prepared` and only becomes `sent` when the staff member confirms they sent it.
 */
class TitleDeedNotifier
{
    public function __construct(private TitleDeedPinService $pins) {}

    /* ═════════ Owner notice, PIN resend, new PIN ═════════ */

    /** @return array{sent:int, failed:int, skipped:int, prepared:int, lines:array<int,string>} */
    public function notifyOwners(User $by, TitleDeed $deed): array
    {
        return $this->withNoticeLock($deed, function () use ($by, $deed) {
            $pin = DB::transaction(function () use ($by, $deed) {
                $locked = $this->lockOpenDeed($deed);
                $code = null;
                if (TitleDeedSetting::pinRequiredFor($locked->agency_id)) {
                    $this->assertNotLocked($locked);
                    if ($this->pins->hasActivePin($locked) && ($code = $this->pins->currentCode($locked))) {
                        // an earlier notice already issued it
                    } else {
                        $code = $this->pins->issue($locked);
                        TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_PIN_ISSUED, 'Collection PIN issued.', null, null, $by);
                    }
                }

                return $code;
            });

            $summary = $this->deliver($by, $deed->refresh(), TitleDeedEvent::KIND_OWNER_NOTICE, $pin, false);
            if ($summary['sent'] > 0) {
                $this->markNotified($deed);
            }
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_NOTICE_SENT, $this->summaryLine('Owner notice', $summary), null, null, $by);

            return $summary;
        });
    }

    /** Send the SAME PIN again to the stored email / WhatsApp of every owner. */
    public function resendPin(User $by, TitleDeed $deed): array
    {
        return $this->withNoticeLock($deed, function () use ($by, $deed) {
            $code = DB::transaction(function () use ($deed) {
                $locked = $this->lockOpenDeed($deed);
                if (! TitleDeedSetting::pinRequiredFor($locked->agency_id)) {
                    throw new TitleDeedException('This agency does not use a collection PIN.');
                }
                $this->assertNotLocked($locked);
                $code = $this->pins->hasActivePin($locked) ? $this->pins->currentCode($locked) : null;
                if (! $code) {
                    throw new TitleDeedException('There is no PIN to resend yet - notify the owners first, or issue a new PIN.');
                }

                return $code;
            });

            $summary = $this->deliver($by, $deed->refresh(), TitleDeedEvent::KIND_PIN_RESEND, $code, false);
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_PIN_RESENT, $this->summaryLine('PIN resent', $summary), null, null, $by);

            return $summary;
        });
    }

    /** Cancel the old PIN, issue a new one, and send it to every owner. Clears a lock. */
    public function issueNewPin(User $by, TitleDeed $deed): array
    {
        return $this->withNoticeLock($deed, function () use ($by, $deed) {
            $code = DB::transaction(function () use ($by, $deed) {
                $locked = $this->lockOpenDeed($deed);
                if (! TitleDeedSetting::pinRequiredFor($locked->agency_id)) {
                    throw new TitleDeedException('This agency does not use a collection PIN.');
                }
                $had = $locked->pin_version > 0;
                $code = $this->pins->issue($locked);
                TitleDeedAuditLog::record($locked, $had ? TitleDeedAuditLog::EVENT_PIN_REPLACED : TitleDeedAuditLog::EVENT_PIN_ISSUED,
                    $had ? 'A new PIN was issued; the old one no longer works.' : 'Collection PIN issued.', null, null, $by);

                return $code;
            });

            $summary = $this->deliver($by, $deed->refresh(), TitleDeedEvent::KIND_PIN_NEW, $code, false);
            if ($summary['sent'] > 0) {
                $this->markNotified($deed);
            }

            return $summary;
        });
    }

    /** The staff member confirms they sent a prepared WhatsApp. */
    public function confirmWhatsapp(User $by, TitleDeed $deed, TitleDeedEvent $event): void
    {
        DB::transaction(function () use ($by, $deed, $event) {
            $row = TitleDeedEvent::query()->lockForUpdate()->find($event->id);
            if (! $row || (int) $row->title_deed_id !== (int) $deed->id || $row->channel !== TitleDeedEvent::CHANNEL_WHATSAPP) {
                throw new TitleDeedException('That WhatsApp message could not be found.');
            }
            if ($row->status === TitleDeedEvent::STATUS_SENT) {
                return;   // double click: absorbed
            }
            if ($row->status !== TitleDeedEvent::STATUS_PREPARED) {
                throw new TitleDeedException('That WhatsApp message was not prepared for sending.');
            }
            $row->forceFill(['status' => TitleDeedEvent::STATUS_SENT, 'confirmed_at' => now(), 'user_id' => $by->id, 'payload_encrypted' => null])->save();
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_WHATSAPP_CONFIRMED,
                'WhatsApp to ' . ($row->owner_name ?: 'owner') . ' (' . $row->destination_masked . ') confirmed as sent.', null, ['kind' => $row->kind], $by);
            if ($row->kind === TitleDeedEvent::KIND_OWNER_NOTICE) {
                $this->markNotified($deed);
            }
        });
    }

    /** The wa.me link for a prepared message, built NOW from what is stored (PIN decrypted here, never shown on a page). */
    public function whatsappLink(TitleDeed $deed, TitleDeedEvent $event): ?string
    {
        if ((int) $event->title_deed_id !== (int) $deed->id || $event->channel !== TitleDeedEvent::CHANNEL_WHATSAPP
            || ! in_array($event->status, [TitleDeedEvent::STATUS_PREPARED, TitleDeedEvent::STATUS_SENT], true)) {
            return null;
        }

        $owner = TitleDeedOwner::query()->where('title_deed_id', $deed->id)->where('contact_id', $event->contact_id)->first();
        $contact = $owner ? TitleDeedDestinations::liveContact($deed, $owner) : null;
        $wa = TitleDeedDestinations::whatsappFor($contact);
        if (! $wa) {
            return null;
        }

        $agency = Agency::withoutGlobalScopes()->find($deed->agency_id);
        $agencyName = $agency?->name ?: 'our office';
        $first = $this->firstName($owner?->display_name ?? '');

        if ($event->kind === TitleDeedEvent::KIND_CONSENT_REQUEST) {
            $payload = $event->payload_encrypted ? $this->decrypt($event->payload_encrypted) : null;
            if (! $payload) {
                return null;
            }
            $text = $payload;
        } else {
            $pin = TitleDeedSetting::pinRequiredFor($deed->agency_id) ? $this->pins->currentCode($deed) : null;
            $text = $this->noticeText($first, $deed->property_display, $agencyName, $pin, $event->kind === TitleDeedEvent::KIND_REMINDER);
        }

        return 'https://wa.me/' . $wa['digits'] . '?text=' . rawurlencode($text);
    }

    /* ═════════ Reminders (email only - CoreX cannot send WhatsApp itself) ═════════ */

    /** @return array{sent:int, failed:int, skipped:int, prepared:int, lines:array<int,string>} */
    public function sendReminder(TitleDeed $deed): array
    {
        $pin = null;
        if (TitleDeedSetting::pinRequiredFor($deed->agency_id) && $this->pins->hasActivePin($deed) && ! $this->pins->isLocked($deed)) {
            $pin = $this->pins->currentCode($deed);
        }

        return $this->deliver(null, $deed, TitleDeedEvent::KIND_REMINDER, $pin, true, emailOnly: true);
    }

    /* ═════════ After collection ═════════ */

    public function sendCollectedNotices(TitleDeed $deed, TitleDeedReceipt $receipt): void
    {
        $agent = $this->senderFor(null, $deed);
        $methodLabel = $receipt->methodLabel();
        $when = ($deed->collected_at ?? now())->format('d M Y H:i');
        $sentTo = [];

        foreach ($this->recipients($deed) as $r) {
            if (! $r['contact'] || ! $r['email']) {
                $this->log($deed, TitleDeedEvent::KIND_COLLECTED_NOTICE, TitleDeedEvent::CHANNEL_EMAIL, $r['owner'], null,
                    TitleDeedEvent::STATUS_SKIPPED, $r['contact'] ? 'No email address stored for this owner.' : 'This contact is archived.', null);
                continue;
            }
            if (isset($sentTo[mb_strtolower($r['email'])])) {
                $this->log($deed, TitleDeedEvent::KIND_COLLECTED_NOTICE, TitleDeedEvent::CHANNEL_EMAIL, $r['owner'], $r['email'],
                    TitleDeedEvent::STATUS_SKIPPED, 'Same address as ' . $sentTo[mb_strtolower($r['email'])] . ' - one email covers both.', null);
                continue;
            }
            $mail = new TitleDeedCollectedMail($r['owner']->display_name, $deed->property_display, $receipt->collector_name, $when, $methodLabel, $agent?->name);
            $result = $this->send($r['email'], $mail, $agent);
            $sentTo[mb_strtolower($r['email'])] = $r['owner']->display_name;
            $this->log($deed, TitleDeedEvent::KIND_COLLECTED_NOTICE, TitleDeedEvent::CHANNEL_EMAIL, $r['owner'], $r['email'],
                $result['status'] === 'sent' ? TitleDeedEvent::STATUS_SENT : TitleDeedEvent::STATUS_FAILED, $result['error'], null);
        }
    }

    /* ═════════ Consent requests ═════════ */

    /**
     * Send each owner THEIR link. $tokens maps contact_id => the raw token (shown here once, stored nowhere in the clear).
     *
     * @param  array<int,string>  $tokens
     * @return array{sent:int, failed:int, skipped:int, prepared:int, lines:array<int,string>}
     */
    public function sendConsentRequests(User $by, TitleDeed $deed, TitleDeedConsent $consent, array $tokens): array
    {
        $summary = $this->blankSummary();
        $agency = Agency::withoutGlobalScopes()->find($deed->agency_id);
        $agencyName = $agency?->name ?: 'our office';
        $expiresOn = $consent->expires_at->format('d M Y H:i');

        foreach ($this->recipients($deed) as $r) {
            $owner = $r['owner'];
            $token = $r['contact'] ? ($tokens[$r['contact']->id] ?? null) : null;
            if (! $token) {
                $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ': contact is archived - no link sent.');
                $this->log($deed, TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::CHANNEL_EMAIL, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'This contact is archived.', $by, $consent->id);
                continue;
            }

            $url = route('title-deeds.consent.show', ['token' => $token]);
            $line = $consent->rep_name . ', ID ending ' . $consent->repIdLast4() . ', is at ' . $agencyName . ' to collect your title deed for ' . $deed->property_display . '.';

            if ($r['email']) {
                $mail = new TitleDeedConsentMail($owner->display_name, $line, $url, $expiresOn, $by->name);
                $result = $this->send($r['email'], $mail, $by);
                $status = $result['status'] === 'sent' ? TitleDeedEvent::STATUS_SENT : TitleDeedEvent::STATUS_FAILED;
                $this->log($deed, TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::CHANNEL_EMAIL, $owner, $r['email'], $status, $result['error'], $by, $consent->id);
                $this->count($summary, $status, $owner->display_name . ' (email): ' . $status);
            } else {
                $this->log($deed, TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::CHANNEL_EMAIL, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'No email address stored for this owner.', $by, $consent->id);
                $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ' (email): no email stored');
            }

            if ($r['wa']) {
                $text = $line . ' Please tell us if you agree: ' . $url;
                $this->log($deed, TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, $r['wa']['masked'], TitleDeedEvent::STATUS_PREPARED, null, $by, $consent->id, Crypt::encryptString($text));
                $this->count($summary, TitleDeedEvent::STATUS_PREPARED, $owner->display_name . ' (WhatsApp): ready to send');
            } else {
                $this->log($deed, TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'No mobile number stored for this owner.', $by, $consent->id);
                $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ' (WhatsApp): no number stored');
            }
        }

        return $summary;
    }

    /* ═════════ Recipients and the shared sender ═════════ */

    /**
     * Every active owner, with where they can be reached.
     *
     * @return Collection<int,array{owner:TitleDeedOwner, contact:?Contact, email:?string, wa:?array}>
     */
    public function recipients(TitleDeed $deed): Collection
    {
        return TitleDeedOwner::query()->where('title_deed_id', $deed->id)->orderBy('id')->get()->map(function (TitleDeedOwner $owner) use ($deed) {
            $contact = TitleDeedDestinations::liveContact($deed, $owner);

            return [
                'owner' => $owner,
                'contact' => $contact,
                'email' => TitleDeedDestinations::emailFor($contact),
                'wa' => TitleDeedDestinations::whatsappFor($contact),
            ];
        });
    }

    /** @return array{sent:int, failed:int, skipped:int, prepared:int, lines:array<int,string>} */
    private function deliver(?User $by, TitleDeed $deed, string $kind, ?string $pin, bool $isReminder, bool $emailOnly = false): array
    {
        $summary = $this->blankSummary();
        $recipients = $this->recipients($deed);
        if ($recipients->isEmpty()) {
            throw new TitleDeedException('This deed has no owners to notify. Edit the record and add an owner first.');
        }

        $agent = $this->senderFor($by, $deed);
        $agency = Agency::withoutGlobalScopes()->find($deed->agency_id);
        $address = $deed->property_display;

        // EMAIL - one email per distinct address (two owners sharing an address get one email that greets both).
        $byEmail = $recipients->filter(fn ($r) => $r['contact'] && $r['email'])->groupBy(fn ($r) => mb_strtolower($r['email']));
        foreach ($recipients as $r) {
            $owner = $r['owner'];
            if (! $r['contact']) {
                $this->log($deed, $kind, TitleDeedEvent::CHANNEL_EMAIL, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'This contact is archived.', $by);
                $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ' (email): contact is archived');
            } elseif (! $r['email']) {
                $this->log($deed, $kind, TitleDeedEvent::CHANNEL_EMAIL, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'No email address stored for this owner.', $by);
                $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ' (email): no email stored');
            }
        }
        foreach ($byEmail as $group) {
            $first = $group->first();
            $names = $group->map(fn ($r) => $r['owner']->display_name)->unique()->values();
            $greeting = $names->count() > 1 ? $names->slice(0, -1)->implode(', ') . ' and ' . $names->last() : $names->first();

            $mail = new TitleDeedNoticeMail($greeting, $address, $pin, $isReminder, $agent?->name);
            $result = $this->send($first['email'], $mail, $agent);
            $status = $result['status'] === 'sent' ? TitleDeedEvent::STATUS_SENT : TitleDeedEvent::STATUS_FAILED;

            $this->log($deed, $kind, TitleDeedEvent::CHANNEL_EMAIL, $first['owner'], $first['email'], $status, $result['error'], $by);
            $this->count($summary, $status, $greeting . ' (email): ' . $status . ($result['error'] ? ' - ' . $result['error'] : ''));
            foreach ($group->slice(1) as $other) {
                $this->log($deed, $kind, TitleDeedEvent::CHANNEL_EMAIL, $other['owner'], $other['email'], TitleDeedEvent::STATUS_SKIPPED,
                    'Same address as ' . $first['owner']->display_name . ' - one email covers both.', $by);
            }
        }

        // WHATSAPP - prepared, never claimed as sent until a person confirms.
        if (! $emailOnly) {
            $seen = [];
            foreach ($recipients as $r) {
                $owner = $r['owner'];
                if (! $r['contact']) {
                    $this->log($deed, $kind, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'This contact is archived.', $by);
                    continue;
                }
                if (! $r['wa']) {
                    $this->log($deed, $kind, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, null, TitleDeedEvent::STATUS_SKIPPED, 'No mobile number stored for this owner.', $by);
                    $this->count($summary, TitleDeedEvent::STATUS_SKIPPED, $owner->display_name . ' (WhatsApp): no number stored');
                    continue;
                }
                $digits = $r['wa']['digits'];
                if (isset($seen[$digits])) {
                    $this->log($deed, $kind, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, $r['wa']['masked'], TitleDeedEvent::STATUS_SKIPPED,
                        'Same number as ' . $seen[$digits] . ' - one message covers both.', $by);
                    continue;
                }
                $seen[$digits] = $owner->display_name;
                $this->log($deed, $kind, TitleDeedEvent::CHANNEL_WHATSAPP, $owner, $r['wa']['masked'], TitleDeedEvent::STATUS_PREPARED, null, $by);
                $this->count($summary, TitleDeedEvent::STATUS_PREPARED, $owner->display_name . ' (WhatsApp): ready to send');
            }
        }

        return $summary;
    }

    /** One email through the existing agency path. Never throws: a failure is a result the caller logs. */
    private function send(string $email, $mail, ?User $agent): array
    {
        try {
            $result = app(SignedDocumentDistributionService::class)->sendGenericMail($email, $mail, $agent);

            return ['status' => $result['status'] ?? 'failed', 'error' => $result['error'] ?? null];
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'failed', 'error' => 'The email could not be sent.'];
        }
    }

    private function senderFor(?User $by, TitleDeed $deed): ?User
    {
        return $by ?? User::withoutGlobalScopes()->find($deed->agent_id);
    }

    private function log(TitleDeed $deed, string $kind, string $channel, ?TitleDeedOwner $owner, ?string $destination, string $status, ?string $error, ?User $by, ?int $consentId = null, ?string $payload = null): TitleDeedEvent
    {
        return TitleDeedEvent::create([
            'agency_id' => $deed->agency_id,
            'title_deed_id' => $deed->id,
            'contact_id' => $owner?->contact_id,
            'owner_name' => $owner?->display_name,
            'kind' => $kind,
            'channel' => $channel,
            'destination_masked' => $destination === null ? null : ($channel === TitleDeedEvent::CHANNEL_EMAIL && str_contains($destination, '@') ? TitleDeedDestinations::maskEmail($destination) : $destination),
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'user_id' => $by?->id,
            'consent_id' => $consentId,
            'payload_encrypted' => $payload,
        ]);
    }

    private function markNotified(TitleDeed $deed): void
    {
        DB::transaction(function () use ($deed) {
            $locked = TitleDeed::query()->lockForUpdate()->find($deed->id);
            if ($locked && $locked->status === TitleDeed::STATUS_RECEIVED) {
                $locked->forceFill(['status' => TitleDeed::STATUS_NOTIFIED, 'notified_at' => now()])->save();
            }
        });
    }

    private function lockOpenDeed(TitleDeed $deed): TitleDeed
    {
        $locked = TitleDeed::query()->lockForUpdate()->find($deed->id);
        if (! $locked) {
            throw new TitleDeedException('This title deed is archived. Restore it first.');
        }
        if ($locked->isCollected()) {
            throw new TitleDeedException('This title deed has already been collected.', TitleDeedException::ALREADY_COLLECTED);
        }

        return $locked;
    }

    private function assertNotLocked(TitleDeed $deed): void
    {
        if ($this->pins->isLocked($deed)) {
            throw new TitleDeedException('The PIN is locked after too many wrong attempts. Issue a new PIN instead.', TitleDeedException::LOCKED);
        }
    }

    /** Two staff members (or a double click) must not both send the same notice at once. */
    private function withNoticeLock(TitleDeed $deed, \Closure $fn): mixed
    {
        $lock = Cache::lock('title-deed-notice:' . $deed->id, 120);
        if (! $lock->get()) {
            throw new TitleDeedException('A notice for this deed is already being sent - give it a moment and check the history below.');
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    private function blankSummary(): array
    {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'prepared' => 0, 'lines' => []];
    }

    private function count(array &$summary, string $status, string $line): void
    {
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
        $summary['lines'][] = $line;
    }

    private function summaryLine(string $what, array $s): string
    {
        return $what . ': ' . $s['sent'] . ' emailed, ' . $s['prepared'] . ' WhatsApp ready, ' . $s['failed'] . ' failed, ' . $s['skipped'] . ' skipped.';
    }

    private function noticeText(string $firstName, string $address, string $agencyName, ?string $pin, bool $isReminder): string
    {
        $hi = 'Hi' . ($firstName !== '' ? ' ' . $firstName : '') . ', ';
        $body = ($isReminder ? 'a reminder that your' : 'your') . ' title deed for ' . $address . ' is at ' . $agencyName . ' and ready for you to collect.';
        $tail = $pin
            ? ' Your collection PIN is ' . $pin . '. The deed is only released on presentation of the PIN, so please keep it private and bring it with you.'
            : ' Please bring your ID when you collect it.';

        return $hi . $body . $tail;
    }

    private function firstName(string $full): string
    {
        return trim(explode(' ', trim($full))[0] ?? '');
    }

    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
