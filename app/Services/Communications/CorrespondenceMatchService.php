<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealV2;

/**
 * AT-231 P2 — Match-or-Create for CORRESPONDENCE. Given a parsed inbound email
 * from a known attorney firm, resolve it to a deal and a confidence tier.
 *
 * Strategy order (Johan's ruling): verified learned-ref (silent auto) →
 * [CX-D] token → thread_key → known-attorney single-active-deal (medium) → low.
 * See .ai/specs/at231-inbound-attorney-comms-filing.md §3.3.
 *
 * Pure resolution: reads only. The write side (park/verify/file/learn/reassign)
 * lives in CorrespondenceFilingService.
 */
class CorrespondenceMatchService
{
    // Tiers. 'auto' = a verified learned-ref → file silently, no suspense.
    const TIER_AUTO   = 'auto';
    const TIER_HIGH   = 'high';
    const TIER_MEDIUM = 'medium';
    const TIER_LOW    = 'low';

    public function __construct(private AttorneyCorrespondenceResolver $resolver)
    {
    }

    /**
     * @param array $msg      keys: subject, body_text, counterpart|from, participants[], thread_key
     * @param array $attorney {provider: AgencyServiceProvider, contact: ?AgencyServiceProviderContact}
     * @return array{tier:string, deal_id:?int, signal_type:?string, signal_value:?string}
     */
    public function resolve(int $agencyId, array $msg, array $attorney): array
    {
        $text         = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
        $sender       = strtolower(trim((string) ($msg['counterpart'] ?? $msg['from'] ?? '')));
        $participants = array_map(fn ($p) => strtolower(trim((string) $p)), $msg['participants'] ?? []);
        $threadKey    = trim((string) ($msg['thread_key'] ?? ''));
        $providerId   = $attorney['provider']->id ?? null;

        // 1) LEARNED (verified) → silent auto-file.
        if ($auto = $this->matchLearned($agencyId, $providerId, $text, $sender, $threadKey)) {
            return $this->result(self::TIER_AUTO, $auto['deal_id'], $auto['signal_type'], $auto['signal_value']);
        }

        // 2) [CX-D] TOKEN — strongest un-learned anchor.
        if ($token = $this->extractToken($text)) {
            $dealId = $this->dealIdFromToken($token, $agencyId);
            if ($dealId) {
                $tier = $this->senderIsDealParty($dealId, $sender, $participants)
                    ? self::TIER_HIGH : self::TIER_MEDIUM;
                return $this->result($tier, $dealId, CommunicationLearnedRef::SIGNAL_CX_TOKEN, $token);
            }
        }

        // 3) THREAD KEY — our own outbound Message-ID a reply threaded on.
        if ($threadKey !== '') {
            $dealId = $this->dealIdFromThreadKey($threadKey, $agencyId);
            if ($dealId) {
                return $this->result(
                    self::TIER_HIGH, $dealId,
                    CommunicationLearnedRef::SIGNAL_THREAD_KEY,
                    CommunicationLearnedRef::normalizeValue($threadKey)
                );
            }
        }

        // 4) SINGLE ACTIVE DEAL for the firm — medium suggestion (learnable by sender).
        if ($providerId) {
            $deals = $this->resolver->activeDealsForFirm((int) $providerId, $agencyId);
            if ($deals->count() === 1) {
                return $this->result(
                    self::TIER_MEDIUM, (int) $deals->first()->id,
                    CommunicationLearnedRef::SIGNAL_SENDER_EMAIL, $sender
                );
            }
        }

        // 5) LOW — parked, no confident suggestion; agent links manually (difficult route).
        return $this->result(self::TIER_LOW, null, null, null);
    }

    /** "cx-d123" (lowercased) or null. */
    public function extractToken(string $text): ?string
    {
        return preg_match('/\[CX-D(\d+)\]/i', $text, $m) ? 'cx-d' . $m[1] : null;
    }

    private function dealIdFromToken(string $token, int $agencyId): ?int
    {
        $id = (int) preg_replace('/\D/', '', $token);
        if ($id <= 0) {
            return null;
        }
        $deal = Deal::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $agencyId)->where('id', $id)->first();

        return $deal ? (int) $deal->id : null;
    }

    private function dealIdFromThreadKey(string $threadKey, int $agencyId): ?int
    {
        $comm = Communication::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('thread_key', $threadKey)
            ->where('direction', Communication::DIRECTION_OUTBOUND)
            ->latest('id')->first();
        if (! $comm) {
            return null;
        }

        $dealV2Id = CommunicationLink::query()->withoutGlobalScopes()
            ->where('communication_id', $comm->id)
            ->where('linkable_type', (new DealV2())->getMorphClass())
            ->value('linkable_id');
        if (! $dealV2Id) {
            return null;
        }

        $id = Deal::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->where('deal_v2_id', $dealV2Id)->value('id');

        return $id ? (int) $id : null;
    }

    private function matchLearned(int $agencyId, ?int $providerId, string $text, string $sender, string $threadKey): ?array
    {
        $rows = CommunicationLearnedRef::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->where('is_verified', true)
            ->when($providerId, fn ($q) => $q->where(function ($w) use ($providerId) {
                $w->whereNull('attorney_provider_id')->orWhere('attorney_provider_id', $providerId);
            }))
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $token      = $this->extractToken($text);
        $normThread = CommunicationLearnedRef::normalizeValue($threadKey);
        $lowerText  = strtolower($text);

        foreach ($rows as $r) {
            $v   = (string) $r->signal_value;
            $hit = match ($r->signal_type) {
                CommunicationLearnedRef::SIGNAL_CX_TOKEN     => $token !== null && $token === $v,
                CommunicationLearnedRef::SIGNAL_THREAD_KEY   => $normThread !== '' && $normThread === $v,
                CommunicationLearnedRef::SIGNAL_SENDER_EMAIL => $sender !== '' && $sender === $v,
                CommunicationLearnedRef::SIGNAL_EXTERNAL_REF,
                CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN => $v !== '' && str_contains($lowerText, $v),
                default => false,
            };
            if ($hit && $this->dealValid((int) $r->deal_id, $agencyId)) {
                return ['deal_id' => (int) $r->deal_id, 'signal_type' => $r->signal_type, 'signal_value' => $v];
            }
        }

        return null;
    }

    private function dealValid(int $dealId, int $agencyId): bool
    {
        return Deal::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $agencyId)->where('id', $dealId)->exists();
    }

    private function senderIsDealParty(int $dealId, string $sender, array $participants): bool
    {
        $party = $this->dealPartyEmails($dealId);
        if (empty($party)) {
            return false;
        }
        if ($sender !== '' && in_array($sender, $party, true)) {
            return true;
        }
        foreach ($participants as $p) {
            if ($p !== '' && in_array($p, $party, true)) {
                return true;
            }
        }

        return false;
    }

    /** attorney/originator emails + the property's linked contact emails (seller/buyer), lowercased. */
    private function dealPartyEmails(int $dealId): array
    {
        $deal = Deal::query()->withoutGlobalScopes()->with('property')->find($dealId);
        if (! $deal) {
            return [];
        }

        $emails = [];
        foreach (['attorney_provider_id', 'bond_originator_provider_id'] as $col) {
            if ($deal->{$col}) {
                $emails[] = AgencyServiceProvider::withoutGlobalScopes()->where('id', $deal->{$col})->value('email');
            }
        }
        foreach (['attorney_contact_id', 'bond_originator_contact_id'] as $col) {
            if ($deal->{$col}) {
                $emails[] = AgencyServiceProviderContact::withoutGlobalScopes()->where('id', $deal->{$col})->value('email');
            }
        }
        if ($deal->property) {
            foreach ($deal->property->contacts as $c) {
                $emails[] = $c->email;
            }
        }

        return array_values(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $emails
        )));
    }

    private function result(string $tier, ?int $dealId, ?string $signalType, ?string $signalValue): array
    {
        return [
            'tier'         => $tier,
            'deal_id'      => $dealId,
            'signal_type'  => $signalType,
            'signal_value' => $signalValue,
        ];
    }
}
