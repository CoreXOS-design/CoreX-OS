<?php

namespace App\Services\AttorneyPortal;

use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/attorney-portal.md §7.4 - the documents an attorney is offered on a deal.
 *
 * The TYPES come from the distribution matrix (DocumentDistributionMatrix::typesForParty - the single source the
 * agent's "email the attorney" button reads too); the portal adds no second list. They are narrowed to what is filed
 * on THIS deal:
 *   - anything filed directly on the deal;
 *   - property-level papers (mandate, rates, levy, ...) filed on the deal's property;
 *   - the parties' own papers (FICA, ID, proof of address) filed on the deal's buyer/seller contacts only - NOT every
 *     contact that ever touched the property.
 * Deal-specific types (OTP, sale agreement, bond grant...) count only when filed on the deal itself, so a failed earlier
 * offer's paper on the same property never leaks into this deal's attorney.
 */
class AttorneyPortalDocuments
{
    /** Types that belong to ONE deal's paperwork, never to the property at large. */
    public const DEAL_ONLY_SLUGS = ['otp', 'offer_to_purchase', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation', 'bond_grant', 'addendum', 'proforma_invoice'];

    public function __construct(private DocumentDistributionMatrix $matrix) {}

    /** @param string[] $roles the attorney's roles on this deal @return Collection<int,Document> newest first */
    public function forDeal(Deal $deal, array $roles): Collection
    {
        $agencyId = (int) $deal->agency_id;

        $typeIds = collect($roles)
            ->flatMap(fn (string $role) => $this->matrix->typesForParty($agencyId, $role)->pluck('id'))
            ->unique()->values();
        if ($typeIds->isEmpty()) {
            return collect();
        }

        $dealOnlyIds = DocumentType::whereIn('slug', self::DEAL_ONLY_SLUGS)->pluck('id');
        $sharedTypeIds = $typeIds->diff($dealOnlyIds)->values();

        $propertyId = $deal->property_id;
        $partyIds   = $this->partyContactIds($deal);

        return Document::withoutGlobalScopes()
            ->whereNull('documents.deleted_at')
            ->where('documents.agency_id', $agencyId)
            ->whereIn('documents.document_type_id', $typeIds)
            ->where(function ($q) use ($deal, $propertyId, $partyIds, $sharedTypeIds) {
                $q->where(function ($d) use ($deal) {
                    $d->where(fn ($s) => $s->where('documents.source_type', 'deal')->where('documents.source_id', $deal->id));
                    if ($deal->deal_v2_id) {
                        $d->orWhere('documents.deal_id', $deal->deal_v2_id);
                    }
                });
                if ($propertyId && $sharedTypeIds->isNotEmpty()) {
                    $q->orWhere(fn ($p) => $p->whereIn('documents.document_type_id', $sharedTypeIds)
                        ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('document_properties')
                            ->whereColumn('document_properties.document_id', 'documents.id')
                            ->where('document_properties.property_id', $propertyId)));
                }
                if (! empty($partyIds) && $sharedTypeIds->isNotEmpty()) {
                    $q->orWhere(fn ($c) => $c->whereIn('documents.document_type_id', $sharedTypeIds)
                        ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('document_contacts')
                            ->whereColumn('document_contacts.document_id', 'documents.id')
                            ->whereIn('document_contacts.contact_id', $partyIds)));
                }
            })
            ->with('documentType')
            ->orderByDesc('documents.created_at')->orderByDesc('documents.id')
            ->get()
            ->unique('id')->values();
    }

    /**
     * forDeal() plus a `filed_at` on each document: when it reached THIS deal's set - its own created time, or later if it was
     * linked to the deal's property / one of the deal's parties after it was made. Drives the "new documents" digest.
     */
    public function forDealWithFiledAt(Deal $deal, array $roles): Collection
    {
        $docs = $this->forDeal($deal, $roles);
        if ($docs->isEmpty()) {
            return $docs;
        }
        $ids   = $docs->pluck('id');
        $props = $deal->property_id
            ? DB::table('document_properties')->whereIn('document_id', $ids)->where('property_id', $deal->property_id)
                ->groupBy('document_id')->selectRaw('document_id, max(created_at) as at')->pluck('at', 'document_id')
            : collect();
        $party = $this->partyContactIds($deal);
        $cons  = $party
            ? DB::table('document_contacts')->whereIn('document_id', $ids)->whereIn('contact_id', $party)
                ->groupBy('document_id')->selectRaw('document_id, max(created_at) as at')->pluck('at', 'document_id')
            : collect();

        return $docs->each(function (Document $d) use ($props, $cons) {
            $times = array_filter([$d->created_at, $props[$d->id] ?? null, $cons[$d->id] ?? null]);
            $d->setAttribute('filed_at', collect($times)->map(fn ($t) => \Illuminate\Support\Carbon::parse($t))->max());
        });
    }

    /** One offered document, or null - so a guessed id answers 404 and learns nothing. */
    public function find(Deal $deal, array $roles, int $documentId): ?Document
    {
        return $this->forDeal($deal, $roles)->first(fn (Document $d) => (int) $d->id === $documentId);
    }

    /** The deal's own buyer/seller contacts (deal_contacts, authoritative once a deal records parties). */
    public function partyContactIds(Deal $deal): array
    {
        return DB::table('deal_contacts')->where('deal_id', $deal->id)
            ->whereIn('role', ['buyer', 'seller', 'co_buyer', 'co_seller'])
            ->pluck('contact_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }
}
