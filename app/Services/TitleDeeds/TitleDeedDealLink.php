<?php

namespace App\Services\TitleDeeds;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/title-deed-register.md section 14.3 / 14.8 - the optional link from a title deed to the DR2 deal it came from, and what a
 * picked deal can honestly pre-fill. NOTHING here guesses: a field the deal does not hold stays empty, a person the deal only
 * names in text is shown as text and never turned into a contact.
 *
 * The DR2 register's deal is `App\Models\Deal` (table `deals`, the screens under /deals-dr2). Fields read:
 *  - registered:        deals.accepted_status 'R' / 'Registered', or deals.registration_date set
 *  - deal number:       deals.deal_no (+ deals.file_no)
 *  - property:          deals.property_id (Deal::property(), the primary property)
 *  - branch:            deals.branch_id
 *  - transferring attorney: deals.attorney_provider_id -> agency_service_providers.company / name (the firm), else the typed
 *                       deals.attorney_name (also what DealSyncService derives from the conveyancer / transfer_attorney roles)
 *  - listing side / selling side: deal_user.side ('listing' | 'selling') through Deal::agents() - the LISTING agent works with
 *                       the seller, the SELLING agent works with the buyer
 *  - owners:            deal_contacts (Deal::buyers()) - after registration the deed belongs to the new owner, the buyer
 */
class TitleDeedDealLink
{
    /** Registered deals the user may see (the register's own scope), newest first. */
    public function registeredDeals(User $user)
    {
        return Deal::query()->visibleTo($user)->where(function ($q) {
            $q->whereIn('accepted_status', ['R', 'Registered'])->orWhereNotNull('registration_date');
        });
    }

    /** @return Collection<int,array{id:int,label:string}> */
    public function search(User $user, string $term, int $limit = 15): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 1) {
            return collect();
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return $this->registeredDeals($user)->where(function ($q) use ($like) {
            $q->where('deals.deal_no', 'like', $like)
                ->orWhere('deals.file_no', 'like', $like)
                ->orWhere('deals.property_address', 'like', $like)
                ->orWhere('deals.seller_name', 'like', $like)
                ->orWhere('deals.buyer_name', 'like', $like)
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->selectRaw('1')->from('deal_contacts as dc')->join('contacts as c', 'c.id', '=', 'dc.contact_id')
                        ->whereColumn('dc.deal_id', 'deals.id')
                        ->where(fn ($w) => $w->where('c.first_name', 'like', $like)->orWhere('c.last_name', 'like', $like)
                            ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) like ?", [$like]));
                })
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->selectRaw('1')->from('properties as p')->whereColumn('p.id', 'deals.property_id')
                        ->where(fn ($w) => $w->where('p.address', 'like', $like)->orWhere('p.title', 'like', $like)->orWhere('p.street_name', 'like', $like));
                });
        })->orderByDesc('deals.deal_no')->orderByDesc('deals.id')->limit($limit)->get()
            ->map(fn (Deal $d) => ['id' => $d->id, 'label' => $this->label($d)])->values();
    }

    /**
     * What picking this deal pre-fills (all visible and editable on the form).
     *
     * @return array<string,mixed>
     */
    public function prefill(User $user, Deal $deal): array
    {
        $property = $deal->property_id ? Property::query()->visibleTo($user)->find($deal->property_id) : null;

        $agents = $deal->agents()->get();   // pivot: side listing | selling
        $selling = $agents->filter(fn (User $a) => $a->pivot->side === 'selling')->unique('id')->values();
        $listing = $agents->filter(fn (User $a) => $a->pivot->side === 'listing')->unique('id')->values();

        $buyers = $deal->buyers()->get()->map(fn (Contact $c) => [
            'id' => $c->id,
            'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: 'Contact #' . $c->id,
            'detail' => '',
        ])->values();

        return [
            'deal' => ['id' => $deal->id, 'label' => $this->label($deal), 'url' => route('deals-dr2.pipeline.view', $deal->id)],
            'property' => $property ? ['id' => $property->id, 'address' => $property->buildDisplayAddress()] : null,
            // Not linked to a property record: the typed address is offered as the search text, never as a link.
            'property_text' => $property ? null : ($deal->property_address ?: null),
            'attorney_firm' => $this->attorneyFirm($deal),
            'branch_id' => $deal->branch_id ?: null,
            // The SELLING agent works with the buyer, who is the new owner: first selling agent = responsible; the rest are
            // additional agents to tell; the LISTING agent is shown for reference.
            'agent_id' => $selling->first()?->id,
            'agent_name' => $selling->first()?->name,
            'additional_agents' => $selling->slice(1)->map(fn (User $a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'listing_agent' => $listing->first() ? ['id' => $listing->first()->id, 'name' => $listing->first()->name] : null,
            'owners' => $buyers,
            'buyer_text' => $buyers->isEmpty() ? ($deal->buyer_name ?: null) : null,
        ];
    }

    /** "Deal 1785 - 23 Shelly Strand - V. J. Keys to A. & E. Van Buuren". */
    public function label(Deal $deal): string
    {
        $parts = [($deal->deal_no ? 'Deal ' . $deal->deal_no : 'Deal #' . $deal->id)];
        $addr = $deal->property_address ?: $deal->property?->buildDisplayAddress();
        if ($addr) {
            $parts[] = $addr;
        }
        $who = trim(($deal->seller_name ?: '') . ($deal->seller_name && $deal->buyer_name ? ' to ' : '') . ($deal->buyer_name ?: ''));
        if ($who !== '') {
            $parts[] = $who;
        }

        return implode(' - ', $parts);
    }

    private function attorneyFirm(Deal $deal): ?string
    {
        if ($deal->attorney_provider_id) {
            $p = AgencyServiceProvider::withoutGlobalScopes()->withTrashed()->find($deal->attorney_provider_id);
            $name = trim((string) ($p?->company ?: $p?->name));
            if ($name !== '') {
                return $name;
            }
        }
        $typed = trim((string) $deal->attorney_name);

        return $typed !== '' ? $typed : null;
    }
}
