<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedConsentLink;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * .ai/specs/title-deed-register.md §8.3 (c)/(d) — a representative without the PIN needs the OWNERS' consent: a tokenised
 * link to every owner (the first answer wins), or - only where the agency allows it - a phone call staff record.
 *
 * Consent is for ONE named representative only (name + ID number captured at the request, copied onto the handover — never
 * re-typed), and only for the agency's consent validity (minutes).
 */
class TitleDeedConsentService
{
    public function __construct(private TitleDeedNotifier $notifier) {}

    /**
     * Ask every owner. Returns the consent and the send summary (email result + WhatsApp links ready to open).
     *
     * @param array{rep_name:string, rep_id_number:string, rep_relationship:string} $rep
     * @return array{consent:TitleDeedConsent, summary:array}
     */
    public function request(User $by, TitleDeed $deed, array $rep): array
    {
        $this->assertRepresentativeRouteOpen($deed);
        $rep = $this->cleanRep($rep);

        $tokens = [];
        $consent = DB::transaction(function () use ($by, $deed, $rep, &$tokens) {
            $locked = $this->lockOpen($deed);
            $validity = TitleDeedSetting::consentValidityMinutesFor($locked->agency_id);

            // One live request at a time: asking again replaces the earlier one.
            TitleDeedConsent::query()->where('title_deed_id', $locked->id)
                ->whereIn('status', [TitleDeedConsent::STATUS_WAITING, TitleDeedConsent::STATUS_APPROVED])
                ->update(['status' => TitleDeedConsent::STATUS_CANCELLED]);

            $owners = TitleDeedOwner::query()->where('title_deed_id', $locked->id)->get();
            $reachable = $owners->filter(fn ($o) => TitleDeedDestinations::liveContact($locked, $o) !== null);
            if ($reachable->isEmpty()) {
                throw new TitleDeedException('None of the owners on this record can be reached (their contacts are archived), so no one can be asked to confirm.');
            }

            $consent = TitleDeedConsent::create([
                'agency_id' => $locked->agency_id,
                'title_deed_id' => $locked->id,
                'channel' => TitleDeedConsent::CHANNEL_LINK,
                'status' => TitleDeedConsent::STATUS_WAITING,
                'rep_name' => $rep['rep_name'],
                'rep_id_number' => $rep['rep_id_number'],
                'rep_relationship' => $rep['rep_relationship'],
                'requested_by_user_id' => $by->id,
                'expires_at' => now()->addMinutes($validity),
            ]);

            foreach ($reachable as $owner) {
                $token = Str::random(48);
                $tokens[$owner->contact_id] = $token;
                TitleDeedConsentLink::create([
                    'agency_id' => $locked->agency_id,
                    'consent_id' => $consent->id,
                    'contact_id' => $owner->contact_id,
                    'token_hash' => TitleDeedConsentLink::hashToken($token),
                    'expires_at' => $consent->expires_at,
                ]);
            }

            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_CONSENT_REQUESTED,
                'Owners asked to confirm ' . $rep['rep_name'] . ' (' . $rep['rep_relationship'] . ') collecting the deed.', null,
                ['representative' => $rep['rep_name'], 'owners_asked' => $reachable->count()], $by);

            return $consent;
        });

        $summary = $this->notifier->sendConsentRequests($by, $deed, $consent, $tokens);

        return ['consent' => $consent, 'summary' => $summary];
    }

    /**
     * Record a phone consent (only when the agency allows it). The number is PICKED from the numbers stored on the owner's
     * contact - it is never typed.
     *
     * @param array{rep_name:string, rep_id_number:string, rep_relationship:string} $rep
     */
    public function recordPhoneConsent(User $by, TitleDeed $deed, array $rep, int $ownerContactId, int $phoneId, string $spokeTo, ?string $calledAt): TitleDeedConsent
    {
        $this->assertRepresentativeRouteOpen($deed);
        if (! TitleDeedPolicy::for($deed->agency_id)->phoneConsent) {
            throw new TitleDeedException('Phone-call consent is not switched on for this agency.');
        }
        $rep = $this->cleanRep($rep);
        $spokeTo = trim($spokeTo);
        if (mb_strlen($spokeTo) < 2) {
            throw new TitleDeedException('Say who you spoke to.');
        }

        return DB::transaction(function () use ($by, $deed, $rep, $ownerContactId, $phoneId, $spokeTo, $calledAt) {
            $locked = $this->lockOpen($deed);

            $owner = TitleDeedOwner::query()->where('title_deed_id', $locked->id)->where('contact_id', $ownerContactId)->first();
            $contact = $owner ? TitleDeedDestinations::liveContact($locked, $owner) : null;
            $numbers = TitleDeedDestinations::storedNumbers($contact);
            if (! $owner || ! $contact || ! array_key_exists($phoneId, $numbers)) {
                throw new TitleDeedException('Pick the owner and one of the numbers stored for them.');
            }

            $at = $calledAt ? \Carbon\Carbon::parse($calledAt) : now();
            if ($at->isFuture()) {
                throw new TitleDeedException('The call time cannot be in the future.');
            }

            TitleDeedConsent::query()->where('title_deed_id', $locked->id)
                ->whereIn('status', [TitleDeedConsent::STATUS_WAITING, TitleDeedConsent::STATUS_APPROVED])
                ->update(['status' => TitleDeedConsent::STATUS_CANCELLED]);

            $validity = TitleDeedSetting::consentValidityMinutesFor($locked->agency_id);
            $consent = TitleDeedConsent::create([
                'agency_id' => $locked->agency_id,
                'title_deed_id' => $locked->id,
                'channel' => TitleDeedConsent::CHANNEL_PHONE,
                'status' => TitleDeedConsent::STATUS_APPROVED,
                'rep_name' => $rep['rep_name'],
                'rep_id_number' => $rep['rep_id_number'],
                'rep_relationship' => $rep['rep_relationship'],
                'requested_by_user_id' => $by->id,
                'expires_at' => now()->addMinutes($validity),
                'responded_at' => now(),
                'responded_contact_id' => $contact->id,
                'responded_name' => $owner->display_name,
                'approved_until' => now()->addMinutes($validity),
                'called_contact_id' => $contact->id,
                'called_number_masked' => $numbers[$phoneId],
                'spoke_to' => mb_substr($spokeTo, 0, 191),
                'called_at' => $at,
            ]);

            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_PHONE_CONSENT,
                'Phone consent for ' . $rep['rep_name'] . ': called ' . $owner->display_name . ' on ' . $numbers[$phoneId] . ', spoke to ' . $spokeTo . '.',
                null, ['called_at' => $at->toIso8601String()], $by);

            return $consent;
        });
    }

    public function cancel(User $by, TitleDeed $deed, TitleDeedConsent $consent): void
    {
        DB::transaction(function () use ($by, $deed, $consent) {
            $row = TitleDeedConsent::query()->lockForUpdate()->find($consent->id);
            if (! $row || (int) $row->title_deed_id !== (int) $deed->id || ! in_array($row->status, [TitleDeedConsent::STATUS_WAITING, TitleDeedConsent::STATUS_APPROVED], true)) {
                return;
            }
            $row->update(['status' => TitleDeedConsent::STATUS_CANCELLED]);
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_CONSENT_DECLINED, 'The request to confirm ' . $row->rep_name . ' was withdrawn by staff.', null, null, $by);
        });
    }

    /**
     * An owner answers from their personal link. FIRST RESPONSE WINS (row lock). Never throws to the caller: the page needs
     * a state to show, not a stack.
     *
     * @return array{state:string, message:string, consent:?TitleDeedConsent}  state: approved|declined|already|expired|invalid
     */
    public function respond(string $token, string $decision, ?string $ip, ?string $userAgent): array
    {
        $link = TitleDeedConsentLink::findLiveByToken($token);
        if (! $link) {
            return $this->result('invalid', null);
        }

        return DB::transaction(function () use ($link, $decision, $ip, $userAgent) {
            $consent = TitleDeedConsent::withoutGlobalScopes()->lockForUpdate()->find($link->consent_id);
            $row = TitleDeedConsentLink::withoutGlobalScopes()->lockForUpdate()->find($link->id);
            if (! $consent || ! $row) {
                return $this->result('invalid', null);
            }

            $deed = TitleDeed::withoutGlobalScopes()->find($consent->title_deed_id);
            if (! $deed || $deed->trashed() || $deed->isCollected()) {
                return $this->result('expired', $consent);
            }
            if ($row->used_at !== null || $consent->status === TitleDeedConsent::STATUS_APPROVED || $consent->status === TitleDeedConsent::STATUS_DECLINED
                || $consent->status === TitleDeedConsent::STATUS_USED) {
                return $this->result('already', $consent);
            }
            if ($consent->status !== TitleDeedConsent::STATUS_WAITING || $row->expires_at->isPast() || $consent->expires_at->isPast()) {
                return $this->result('expired', $consent);
            }

            $owner = TitleDeedOwner::withoutGlobalScopes()->where('title_deed_id', $deed->id)->where('contact_id', $row->contact_id)->first();
            $ownerName = $owner?->name_snapshot ?: 'An owner';
            $approve = $decision === 'approve';

            $consent->forceFill([
                'status' => $approve ? TitleDeedConsent::STATUS_APPROVED : TitleDeedConsent::STATUS_DECLINED,
                'responded_at' => now(),
                'responded_contact_id' => $row->contact_id,
                'responded_name' => $ownerName,
                'responded_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'responded_user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'approved_until' => $approve ? now()->addMinutes(TitleDeedSetting::consentValidityMinutesFor($deed->agency_id)) : null,
            ])->save();

            // Single use: every owner's link for this request stops working the moment one owner answers.
            TitleDeedConsentLink::withoutGlobalScopes()->where('consent_id', $consent->id)->whereNull('used_at')->update(['used_at' => now()]);

            TitleDeedAuditLog::record($deed, $approve ? TitleDeedAuditLog::EVENT_CONSENT_APPROVED : TitleDeedAuditLog::EVENT_CONSENT_DECLINED,
                $ownerName . ($approve ? ' approved ' : ' declined ') . $consent->rep_name . ' collecting the deed.', null,
                ['ip' => $ip, 'device' => $userAgent ? mb_substr($userAgent, 0, 200) : null], null);

            return $this->result($approve ? 'approved' : 'declined', $consent);
        });
    }

    /** First time the link is opened (shown on the page, harmless if it fails). */
    public function recordOpen(string $token): void
    {
        try {
            TitleDeedConsentLink::withoutGlobalScopes()->where('token_hash', TitleDeedConsentLink::hashToken($token))->whereNull('first_opened_at')->update(['first_opened_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What the handover screen shows and polls: the latest consent for the deed.
     *
     * @return array{state:string, rep_name:?string, rep_id_last4:?string, by:?string, until:?string, consent_id:?int, channel:?string}
     */
    public function status(TitleDeed $deed): array
    {
        $consent = TitleDeedConsent::query()->where('title_deed_id', $deed->id)->latest('id')->first();
        if (! $consent) {
            return ['state' => 'none', 'rep_name' => null, 'rep_id_last4' => null, 'by' => null, 'until' => null, 'consent_id' => null, 'channel' => null];
        }

        return [
            'state' => $consent->effectiveStatus(),
            'rep_name' => $consent->rep_name,
            'rep_id_last4' => $consent->repIdLast4(),
            'by' => $consent->responded_name,
            'until' => $consent->approved_until?->toIso8601String(),
            'consent_id' => $consent->id,
            'channel' => $consent->channel,
        ];
    }

    /* ── helpers ── */

    private function result(string $state, ?TitleDeedConsent $consent): array
    {
        $messages = [
            'approved' => 'Thank you - you have approved this. The person can now collect the title deed.',
            'declined' => 'Thank you - you have declined. The title deed will not be released to them.',
            'already' => 'This has already been answered, so there is nothing more to do. Thank you.',
            'expired' => 'This request is no longer open. If it still matters, please contact the office.',
            'invalid' => 'This link is not available.',
        ];

        return ['state' => $state, 'message' => $messages[$state], 'consent' => $consent];
    }

    /** @return array{rep_name:string, rep_id_number:string, rep_relationship:string} */
    private function cleanRep(array $rep): array
    {
        $out = [
            'rep_name' => trim((string) ($rep['rep_name'] ?? '')),
            'rep_id_number' => trim((string) ($rep['rep_id_number'] ?? '')),
            'rep_relationship' => trim((string) ($rep['rep_relationship'] ?? '')),
        ];
        if (mb_strlen($out['rep_name']) < 2 || mb_strlen($out['rep_id_number']) < 5 || mb_strlen($out['rep_relationship']) < 2) {
            throw new TitleDeedException("Enter the representative's full name, ID number and relationship to the owner.");
        }
        foreach ($out as $k => $v) {
            $out[$k] = mb_substr($v, 0, $k === 'rep_name' ? 191 : ($k === 'rep_id_number' ? 40 : 100));
        }

        return $out;
    }

    private function assertRepresentativeRouteOpen(TitleDeed $deed): void
    {
        $policy = TitleDeedPolicy::for($deed->agency_id);
        if (! $policy->repsAllowed) {
            throw new TitleDeedException('Representatives are not allowed to collect for this agency.');
        }
        if (! $policy->repWithoutPinOffered()) {
            throw new TitleDeedException($policy->pinRequired()
                ? "At this agency the owner's PIN is required, so a representative must have it."
                : 'A representative cannot collect without the PIN at this agency.');
        }
    }

    private function lockOpen(TitleDeed $deed): TitleDeed
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
}
