<?php

namespace App\Services\DealV2;

use App\Models\AgencyDealSyncSettings;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\Document;
use Illuminate\Support\Collection;

/**
 * AT-228 — the party-first compose resolver: given a DR2 deal, work out WHO the parties are
 * (property-link roles for seller/buyer; the appointed supplier for attorney/bond originator),
 * WHICH documents each should receive (from the AT-227 matrix, resolved to docs actually filed
 * on the deal), and the DEFAULT delivery method + channel. The matrix does the thinking; the
 * agent authorises. Robust to messy party data — a party with no recipient/email is reported,
 * never a crash.
 */
class Dr2DistributionComposer
{
    /** The deal parties AT-228 can send to, in order. */
    public const PARTY_LABELS = [
        'seller'            => 'Seller',
        'buyer'             => 'Buyer',
        'transfer_attorney' => 'Transferring Attorney',
        'bond_originator'   => 'Bond Originator',
    ];

    public function __construct(private DocumentDistributionMatrix $matrix) {}

    /**
     * Party-first plan for the whole deal.
     * @return array<int,array{role:string,label:string,recipients:array,default_documents:\Illuminate\Support\Collection,delivery_mode:string,channel:string,sendable:bool,note:?string}>
     */
    public function parties(Deal $deal): array
    {
        $out = [];
        foreach (self::PARTY_LABELS as $role => $label) {
            $recipients = $this->recipientsFor($deal, $role);
            $docs       = $this->defaultDocumentsForParty($deal, $role);
            [$mode, $channel] = $this->defaultDeliveryFor($deal, $role, $recipients);

            $hasEmail = collect($recipients)->contains(fn ($r) => ! empty($r['email']));
            $note = null;
            if (empty($recipients)) {
                $note = 'No ' . strtolower($label) . ' linked on this deal yet.';
            } elseif (! $hasEmail && $channel === DealDocumentDistribution::CHANNEL_EMAIL) {
                $note = 'No email on file — add one or switch to WhatsApp.';
            }

            $out[] = [
                'role'              => $role,
                'label'             => $label,
                'recipients'        => $recipients,
                'default_documents' => $docs,
                'delivery_mode'     => $mode,
                'channel'           => $channel,
                'sendable'          => ! empty($recipients) && ($hasEmail || $channel === DealDocumentDistribution::CHANNEL_WHATSAPP),
                'note'              => $note,
            ];
        }
        return $out;
    }

    /** Resolve the recipients for a party role. Each: [type, id, name, email, phone]. */
    public function recipientsFor(Deal $deal, string $role): array
    {
        return match ($role) {
            'seller'            => $this->contactRecipients($deal, 'seller_owner'),
            'buyer'             => $this->contactRecipients($deal, 'buyer'),
            'transfer_attorney' => $this->providerRecipient($deal->attorney_provider_id, $deal->attorney_contact_id),
            'bond_originator'   => $this->providerRecipient($deal->bond_originator_provider_id, $deal->bond_originator_contact_id),
            default             => [],
        };
    }

    private function contactRecipients(Deal $deal, string $contactRole): array
    {
        // AT-334 — the DEAL owns its transaction parties (AT-243, deal_contacts), synced on
        // every save (syncDealParties add/remove). Resolve buyers/sellers from the DEAL, NOT
        // the property: a property accumulates buyers across offers (and syncPartyLinks is
        // append-only), so reading the property showed unselected/removed buyers on Email
        // Parties. Reading deal_contacts means Email Parties reflects EXACTLY this deal's
        // current parties — an unselected buyer never appears, a removed buyer drops off.
        $dealRole = $contactRole === 'seller_owner' ? 'seller' : $contactRole;

        // A "party-managed" deal owns its parties on deal_contacts — a DR2 deal (has a
        // twin or a deal_type) or any deal that has ever recorded a party. For these,
        // deal_contacts is AUTHORITATIVE: an empty role = no recipient (so a buyer removed
        // on edit truly drops off — never a property fallback that resurrects it). Only a
        // genuinely-legacy DR1 deal (no twin, no deal_type, no recorded parties) falls back
        // to the property, where its parties historically lived.
        $hasDealParties = \Illuminate\Support\Facades\DB::table('deal_contacts')
            ->where('deal_id', $deal->id)->exists();
        $partyManaged = $hasDealParties || $deal->deal_v2_id !== null || $deal->deal_type !== null;

        if ($partyManaged) {
            $ids = \Illuminate\Support\Facades\DB::table('deal_contacts')
                ->where('deal_id', $deal->id)->where('role', $dealRole)->pluck('contact_id');
            $contacts = $ids->isEmpty()
                ? collect()
                : \App\Models\Contact::withoutGlobalScopes()->whereIn('id', $ids)->get();
        } else {
            // Legacy pre-AT-243 DR1 deal: parties were recorded only on the property.
            $property = $deal->property;
            $contacts = $property ? $property->contactsForRole($contactRole) : collect();
        }

        return $contacts
            ->map(fn ($c) => [
                'type'  => 'contact',
                'id'    => $c->id,
                'name'  => trim((string) ($c->full_name ?? ($c->first_name . ' ' . $c->last_name))) ?: 'Contact',
                'email' => $this->cleanEmail($c->primaryEmail?->email ?? $c->email),
                'phone' => $c->primaryPhone?->phone ?? $c->phone,
            ])
            ->filter(fn ($r) => $r['email'] || $r['phone'])   // a recipient with neither is unaddressable
            ->values()->all();
    }

    private function providerRecipient(?int $providerId, ?int $contactId): array
    {
        if (! $providerId) {
            return [];
        }
        $firm    = AgencyServiceProvider::withoutGlobalScopes()->find($providerId);
        $contact = $contactId ? AgencyServiceProviderContact::withoutGlobalScopes()->find($contactId) : null;
        if (! $firm && ! $contact) {
            return [];
        }
        $email = $this->cleanEmail($contact?->email ?: $firm?->email);
        $phone = $contact?->phone ?: $firm?->phone;
        $name  = trim((string) ($contact?->attorney_name ?: $contact?->contact_person ?: $firm?->name ?: 'Provider'));
        if (! $email && ! $phone) {
            return [];
        }
        return [[
            'type'        => 'provider',
            'id'          => $providerId,
            'contact_id'  => $contactId,
            'name'        => $name ?: 'Provider',
            'email'       => $email,
            'phone'       => $phone,
        ]];
    }

    /**
     * The documents a party gets by default = the matrix's types for that party, resolved to
     * documents actually filed on the deal (deal + property + contacts corpus). Matrix = default;
     * the agent unticks/adds in compose.
     * @return Collection<int,Document>
     */
    public function defaultDocumentsForParty(Deal $deal, string $role): Collection
    {
        $typeIds = $this->matrix->typesForParty((int) $deal->agency_id, $role)->pluck('id')->all();
        if (empty($typeIds)) {
            return collect();
        }
        return $this->documentCorpus($deal)
            ->filter(fn (Document $d) => in_array((int) $d->document_type_id, array_map('intval', $typeIds), true))
            ->values();
    }

    /**
     * ALL documents reachable from the deal + its property + its linked contacts (for the
     * "add documents" search). Relations already exist (AT-225 filing) — this is the union read.
     * @return Collection<int,Document>
     */
    public function documentCorpus(Deal $deal): Collection
    {
        $propertyId = $deal->property_id;
        $contactIds = $deal->property?->contacts()->pluck('contacts.id')->all() ?? [];

        return Document::query()
            ->where(function ($q) use ($deal, $propertyId, $contactIds) {
                $q->where(fn ($d) => $d->where('source_type', 'deal')->where('source_id', $deal->id));
                if ($deal->deal_v2_id) {
                    $q->orWhere('deal_id', $deal->deal_v2_id);
                }
                if ($propertyId) {
                    $q->orWhereHas('properties', fn ($p) => $p->where('properties.id', $propertyId));
                }
                if (! empty($contactIds)) {
                    $q->orWhereHas('contacts', fn ($c) => $c->whereIn('contacts.id', $contactIds));
                }
            })
            ->with('documentType')
            ->latest()->get()
            ->unique('id')->values();
    }

    /**
     * The default delivery mode + channel for a party send:
     *   per-contact preference (supplier) → the party's matrix rule → agency defaults.
     * @return array{0:string,1:string}
     */
    public function defaultDeliveryFor(Deal $deal, string $role, array $recipients): array
    {
        $mode    = DealDocumentDistribution::MODE_SECURE_LINK;
        $channel = DealDocumentDistribution::CHANNEL_EMAIL;

        // Per-contact supplier preference wins.
        $contactId = $recipients[0]['contact_id'] ?? null;
        if ($contactId) {
            $spc = AgencyServiceProviderContact::withoutGlobalScopes()->find($contactId);
            if ($spc?->default_delivery_mode) {
                $mode = $spc->default_delivery_mode;
            }
            if ($spc?->default_channel) {
                $channel = $spc->default_channel;
            }
            return [$mode, $channel];
        }

        // Else the matrix rule's delivery for this party (first active rule).
        $rule = $this->matrix->rulesForParty((int) $deal->agency_id, $role)->first();
        if ($rule?->delivery_mode) {
            $mode = $rule->delivery_mode;
        }
        return [$mode, $channel];
    }

    /** The agency's email size limit in bytes (agency-configurable; nothing hardcoded). */
    public function sizeLimitBytes(int $agencyId): int
    {
        $mb = (int) (AgencyDealSyncSettings::forAgency($agencyId)->max_email_attachment_mb ?: 20);
        return max(1, $mb) * 1024 * 1024;
    }

    private function cleanEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        return $email !== '' ? $email : null;
    }
}
