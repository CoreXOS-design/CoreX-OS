<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealStepWorkOrder;
use App\Models\DealV2\DealV2;
use Illuminate\Support\Facades\DB;

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
        $subject      = (string) ($msg['subject'] ?? '');
        $text         = $subject . "\n" . (string) ($msg['body_text'] ?? '');
        $sender       = strtolower(trim((string) ($msg['counterpart'] ?? $msg['from'] ?? '')));
        $participants = array_map(fn ($p) => strtolower(trim((string) $p)), $msg['participants'] ?? []);
        $threadKey    = trim((string) ($msg['thread_key'] ?? ''));
        $providerId   = $attorney['provider']->id ?? null;

        // Every email address on the message (from ∪ to ∪ cc), the ladder's match set (Johan G1=A:
        // "any party present anywhere", not just the sender).
        $addrs = array_values(array_unique(array_filter(array_merge([$sender], $participants))));

        // The canonical ref learned on first approval so the rest of the correspondence auto-files:
        // our own [CX-D] token if present, else the normalised subject (Re:/Fwd:-STRIPPED, D3).
        [$canonType, $canonVal] = $this->canonicalSignal($text, $subject);

        // 1) LEARNED (verified) → silent auto-file. Subject-based refs additionally require an EMAIL-ADDRESS
        //    corroboration ($addrs): a subject alone never auto-files a deal without a party address on the
        //    message (Johan: the email address is the primary reliable signal; subject is secondary).
        if ($auto = $this->matchLearned($agencyId, $providerId, $text, $subject, $sender, $threadKey, $addrs)) {
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

        // 4) THE CONFIDENCE LADDER (Johan Phase 2) — score the agency's candidate deals by how many of
        //    their party emails (seller / buyer / transfer-attorney / supplier) appear on this email.
        //    Ranked, best deal pre-selected; carries the canonical ref so a first approval auto-files
        //    the rest of the correspondence. Replaces the thin single-active-deal strategy.
        if ($best = $this->scoreByParties($agencyId, $addrs)) {
            return $this->result($best['tier'], $best['deal_id'], $canonType, $canonVal);
        }

        // 5) SUBJECT-LINE NAME MATCH (tier 4 fallback) — no party address matched, so scan the subject
        //    for a name on one of the agency's deals. A single unambiguous hit → LOW suggestion.
        if ($dealId = $this->matchBySubjectNames($agencyId, $subject)) {
            return $this->result(self::TIER_LOW, $dealId, $canonType, $canonVal);
        }

        // 6) LOW — parked, no confident suggestion; agent links manually.
        return $this->result(self::TIER_LOW, null, $canonType, $canonVal);
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

    private function matchLearned(int $agencyId, ?int $providerId, string $text, string $subject, string $sender, string $threadKey, array $addrs = []): ?array
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
        $normSubject = $this->normalizeSubject($subject); // EXACT match — a mutated subject won't equal it.
        $lowerText  = strtolower($text);

        foreach ($rows as $r) {
            $v          = (string) $r->signal_value;
            $type       = $r->signal_type;
            $subjectSig = in_array($type, [CommunicationLearnedRef::SIGNAL_SUBJECT_EXACT, CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN], true);
            $hit = match ($type) {
                CommunicationLearnedRef::SIGNAL_CX_TOKEN     => $token !== null && $token === $v,
                CommunicationLearnedRef::SIGNAL_THREAD_KEY   => $normThread !== '' && $normThread === $v,
                CommunicationLearnedRef::SIGNAL_SENDER_EMAIL => $sender !== '' && $sender === $v,
                // Subject backup: normalised-subject equality (D3 strips Re:/Fwd: so a threaded reply matches).
                CommunicationLearnedRef::SIGNAL_SUBJECT_EXACT => $normSubject !== '' && $normSubject === $v,
                CommunicationLearnedRef::SIGNAL_EXTERNAL_REF,
                CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN => $v !== '' && str_contains($lowerText, $v),
                default => false,
            };
            if (! $hit || ! $this->dealValid((int) $r->deal_id, $agencyId)) {
                continue;
            }
            // D3 — a SUBJECT-based ref auto-files ONLY when corroborated by a party EMAIL ADDRESS of that
            // deal on this message. The subject is a thread signal; the address pins the deal. Without an
            // address match it does NOT auto-file — it falls through to the ladder (parks as a suggestion).
            // Token / thread_key / sender_email are reliable machine/address anchors and need no corroboration.
            if ($subjectSig && ! $this->dealHasPartyOnMessage((int) $r->deal_id, $addrs)) {
                continue;
            }

            return ['deal_id' => (int) $r->deal_id, 'signal_type' => $type, 'signal_value' => $v];
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
        // COMMS PHASE 1 — suppliers on this deal's work orders (COC / electrician / …) are deal
        // parties too, so a token-bearing supplier reply corroborates to HIGH (not just MEDIUM).
        $woProviderIds = \App\Models\DealV2\DealStepWorkOrder::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('dr1_deal_id', $dealId)
            ->whereNotNull('service_provider_id')
            ->pluck('service_provider_id')->unique()->values();
        if ($woProviderIds->isNotEmpty()) {
            foreach (AgencyServiceProvider::withoutGlobalScopes()->whereIn('id', $woProviderIds)->pluck('email') as $e) {
                $emails[] = $e;
            }
            foreach (AgencyServiceProviderContact::withoutGlobalScopes()->whereIn('service_provider_id', $woProviderIds)->pluck('email') as $e) {
                $emails[] = $e;
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

    /**
     * The canonical ref for the compounding win: our own [CX-D] token if present, else the EXACT
     * normalised subject. Learned on first approval so the rest of the correspondence auto-files.
     */
    private function canonicalSignal(string $text, string $subject): array
    {
        if ($token = $this->extractToken($text)) {
            return [CommunicationLearnedRef::SIGNAL_CX_TOKEN, $token];
        }
        $norm = $this->normalizeSubject($subject);
        return $norm !== '' ? [CommunicationLearnedRef::SIGNAL_SUBJECT_EXACT, $norm] : [null, null];
    }

    /**
     * The normalised subject key (D3): STRIP any stack of system reply/forward prefixes
     * (Re:/Fwd:/Fw:/Aw:/Wg:) then lowercase + whitespace-collapse — so a threaded reply
     * "Re: barnard / du toit" matches the core "barnard / du toit". This makes the subject match LOOSER on
     * purpose; it is safe because a subject-based auto-file additionally requires a party EMAIL ADDRESS on
     * the message (matchLearned → dealHasPartyOnMessage). Subject is the thread signal; the address pins it.
     */
    private function normalizeSubject(string $subject): string
    {
        $s = trim($subject);
        while (preg_match('/^\s*(re|fwd|fw|aw|wg)\s*:\s*/i', $s)) {
            $s = preg_replace('/^\s*(re|fwd|fw|aw|wg)\s*:\s*/i', '', $s, 1);
        }
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $s)));
    }

    /** Does any party EMAIL ADDRESS of $dealId appear on this message? The D3 corroboration for a
     *  subject-based auto-file — the address is the reliable signal that actually pins the deal. */
    private function dealHasPartyOnMessage(int $dealId, array $addrs): bool
    {
        if (empty($addrs)) {
            return false;
        }
        foreach ($this->partyEmailsByRole($dealId) as $emails) {
            foreach ($emails as $e) {
                if ($e !== '' && in_array($e, $addrs, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * THE LADDER. Score the agency's candidate deals by which party ROLES have an email on this message —
     * two principals (seller / buyer) + ONE supplier bucket that spans every other provider (attorney /
     * bond originator / COC / work-order suppliers), all treated identically (D1). Returns the best
     * {tier, deal_id} or null if no candidate deal has any party on the mail. Rank HIGH > MEDIUM > LOW,
     * tiebreak by #roles matched.
     */
    private function scoreByParties(int $agencyId, array $addrs): ?array
    {
        if (empty($addrs)) {
            return null;
        }
        $best = null;
        foreach ($this->candidateDeals($agencyId, $addrs) as $dealId) {
            $matched = [];
            foreach ($this->partyEmailsByRole($dealId) as $role => $emails) {
                foreach ($emails as $e) {
                    if ($e !== '' && in_array($e, $addrs, true)) { $matched[$role] = true; break; }
                }
            }
            $n = count($matched);
            if ($n === 0) {
                continue;
            }
            $has  = fn ($r) => isset($matched[$r]);
            $side = $has('seller') ? 'seller' : ($has('buyer') ? 'buyer' : null);

            if (($has('seller') && $has('buyer')) || ($n >= 3)) {
                $tier = self::TIER_HIGH;                             // T1 (all three) & T2 (buyer+seller)
            } elseif ($n === 2 && $side && $has('supplier')) {
                $tier = $this->partySideUnique($agencyId, $dealId, $side, $addrs) // T3: supplier + one side
                    ? self::TIER_HIGH : self::TIER_MEDIUM;           //     → MEDIUM, promote to HIGH if unique
            } elseif ($n === 1) {
                $tier = self::TIER_LOW;                              // T4 (single party)
            } else {
                $tier = self::TIER_MEDIUM;                           // defensive fallback
            }

            $rank = $tier === self::TIER_HIGH ? 3 : ($tier === self::TIER_MEDIUM ? 2 : 1);
            if ($best === null || [$rank, $n] > [$best['rank'], $best['roles']]) {
                $best = ['rank' => $rank, 'roles' => $n, 'tier' => $tier, 'deal_id' => (int) $dealId];
            }
        }

        return $best ? ['tier' => $best['tier'], 'deal_id' => $best['deal_id']] : null;
    }

    /** Deals that share any address with a party email — providers' deals (attorney/supplier via
     *  activeDealsForFirm) ∪ buyer/seller contacts' deals. Agency- + non-declined-scoped, capped. */
    private function candidateDeals(int $agencyId, array $addrs): array
    {
        if (empty($addrs)) {
            return [];
        }
        $dealIds = [];

        $providerIds = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)
            ->whereIn(DB::raw('LOWER(TRIM(email))'), $addrs)->pluck('id')
            ->merge(AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $agencyId)
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $addrs)->pluck('service_provider_id'))
            ->filter()->unique();
        foreach ($providerIds as $pid) {
            foreach ($this->resolver->activeDealsForFirm((int) $pid, $agencyId) as $d) {
                $dealIds[(int) $d->id] = true;
            }
        }

        $contactIds = Contact::withoutGlobalScopes()->where('agency_id', $agencyId)
            ->whereIn(DB::raw('LOWER(TRIM(email))'), $addrs)->pluck('id');
        if ($contactIds->isNotEmpty()) {
            // deal_contacts has no agency_id — scope via the joined deal below (candidateDeals filters
            // the resulting ids by Deal agency-scope anyway; the contact ids are already agency-scoped).
            foreach (DB::table('deal_contacts')
                ->whereIn('contact_id', $contactIds)->whereIn('role', ['seller', 'buyer'])
                ->distinct()->pluck('deal_id') as $id) {
                $dealIds[(int) $id] = true;
            }
        }

        $ids = array_slice(array_keys($dealIds), 0, 50);
        if (empty($ids)) {
            return [];
        }

        return Deal::withoutGlobalScopes()->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('accepted_status')->orWhere('accepted_status', '!=', 'D'))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Role → [lowercased emails] for a deal. Only TWO PRINCIPALS — seller, buyer (deal_contacts). EVERY
     * other party is a SUPPLIER, all treated identically (Johan D1): the supplier bucket spans the transfer
     * attorney, the bond originator, AND work-order suppliers (COC / electrician / entomologist / …). The
     * ladder's per-role signals (G2).
     */
    private function partyEmailsByRole(int $dealId): array
    {
        $deal = Deal::withoutGlobalScopes()->find($dealId);
        if (! $deal) {
            return ['seller' => [], 'buyer' => [], 'supplier' => []];
        }
        $agencyId = (int) $deal->agency_id;
        $norm = fn ($c) => collect($c)->map(fn ($e) => strtolower(trim((string) $e)))->filter()->unique()->values()->all();

        $side = function (string $role) use ($dealId, $norm) {
            $cids = DB::table('deal_contacts')->where('deal_id', $dealId)->where('role', $role)->pluck('contact_id');
            return $cids->isEmpty() ? [] : $norm(Contact::withoutGlobalScopes()->whereIn('id', $cids)->pluck('email'));
        };

        // SUPPLIER = every non-principal provider on the deal (D1): transfer attorney + bond originator
        // (deal columns) ∪ work-order suppliers (COC / electrician / …), firms AND their contacts.
        $supplier = [];
        foreach (['attorney_provider_id', 'bond_originator_provider_id'] as $col) {
            if ($deal->{$col}) $supplier[] = AgencyServiceProvider::withoutGlobalScopes()->where('id', $deal->{$col})->value('email');
        }
        foreach (['attorney_contact_id', 'bond_originator_contact_id'] as $col) {
            if ($deal->{$col}) $supplier[] = AgencyServiceProviderContact::withoutGlobalScopes()->where('id', $deal->{$col})->value('email');
        }
        $woIds = DealStepWorkOrder::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('dr1_deal_id', $dealId)->whereNotNull('service_provider_id')->pluck('service_provider_id')->unique()->values();
        if ($woIds->isNotEmpty()) {
            $supplier = array_merge($supplier,
                AgencyServiceProvider::withoutGlobalScopes()->whereIn('id', $woIds)->pluck('email')->all(),
                AgencyServiceProviderContact::withoutGlobalScopes()->whereIn('service_provider_id', $woIds)->pluck('email')->all()
            );
        }

        return [
            'seller'   => $side('seller'),
            'buyer'    => $side('buyer'),
            'supplier' => $norm($supplier),
        ];
    }

    /** Tier-3 promotion: is a matched side-party (seller|buyer) on this deal unique to a SINGLE deal? */
    private function partySideUnique(int $agencyId, int $dealId, string $side, array $addrs): bool
    {
        $cids = DB::table('deal_contacts')->where('deal_id', $dealId)->where('role', $side)->pluck('contact_id');
        $matched = Contact::withoutGlobalScopes()->whereIn('id', $cids)->get(['id', 'email'])
            ->filter(fn ($c) => in_array(strtolower(trim((string) $c->email)), $addrs, true));

        foreach ($matched as $c) {
            // deal_contacts has no agency_id — the joined deal enforces the tenant.
            $count = DB::table('deal_contacts')->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
                ->whereNull('deals.deleted_at')
                ->where('deals.agency_id', $agencyId)->where('deal_contacts.contact_id', $c->id)
                ->whereIn('deal_contacts.role', ['seller', 'buyer'])
                ->distinct()->count('deal_contacts.deal_id');
            if ($count === 1) {
                return true;
            }
        }

        return false;
    }

    /** Tier-4: scan the subject for a seller/buyer name on the agency's deals. Single unambiguous hit only. */
    private function matchBySubjectNames(int $agencyId, string $subject): ?int
    {
        $subj = strtolower(trim($subject));
        if ($subj === '') {
            return null;
        }
        $hits = [];
        $deals = Deal::withoutGlobalScopes()->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('accepted_status')->orWhere('accepted_status', '!=', 'D'))
            ->get(['id', 'seller_name', 'buyer_name']);
        foreach ($deals as $d) {
            foreach ([$d->seller_name, $d->buyer_name] as $name) {
                $n = strtolower(trim((string) $name));
                if (mb_strlen($n) >= 4 && str_contains($subj, $n)) { $hits[(int) $d->id] = true; break; }
            }
        }

        return count($hits) === 1 ? (int) array_key_first($hits) : null;
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
