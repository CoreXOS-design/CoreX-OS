<?php

namespace App\Services\AttorneyPortal;

use App\Models\AttorneyPortalAccess;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealStepInstance;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/attorney-portal.md §7 - what an attorney is shown about a deal, in plain words. Read-only; names and
 * dates only (no ID numbers, phone numbers or emails of the buyer or seller - those stay inside the released documents).
 */
class AttorneyPortalDealPresenter
{
    public function __construct(private AttorneyPortalScope $scope, private AttorneyPortalDocuments $documents) {}

    /** One row of "My deals". */
    public function row(Deal $deal, AttorneyPortalAccess $access): array
    {
        $roles = $this->scope->rolesOn($deal, $access);
        $docs  = $this->documents->forDealWithFiledAt($deal, $roles);
        $until = $this->scope->visibleUntil($deal);
        $agent = $this->agent($deal);
        $filed = $docs->max('filed_at');

        return [
            'id'              => (int) $deal->id,
            'number'          => $this->number($deal),
            'address'         => $this->address($deal),
            'suburb'          => $this->suburb($deal),
            'reference'       => $this->reference($deal),
            'buyer'           => $this->partyNames($deal, 'buyer'),
            'seller'          => $this->partyNames($deal, 'seller'),
            'agent'           => $agent['name'] ?? null,
            'agent_phone'     => $agent['phone'] ?? null,
            'stage'           => $this->stage($deal),
            'finished'        => $until !== null,
            'visible_until'   => $until,
            'latest_document' => $filed ? Carbon::parse($filed) : null,
            'document_count'  => $docs->count(),
            'files'           => $this->files($deal, $docs),
            'roles'           => $roles,
        ];
    }

    /** The full deal page. */
    public function detail(Deal $deal, AttorneyPortalAccess $access): array
    {
        $row  = $this->row($deal, $access);
        $twin = $this->scope->twin($deal);

        return $row + [
            'agent_card' => $this->agent($deal),
            'dates'      => array_values(array_filter([
                $this->date('Offer accepted', $deal->deal_date),
                $this->date('Bond granted', $deal->granted_at),
                $this->date('Lodged at the Deeds Office', $this->lodgedOn($deal)),
                $this->date('Expected registration', $this->scope->isRegistered($deal) ? null : $twin?->expected_registration),
                $this->date('Registered', $deal->registration_date),
            ])),
            'other_attorney' => $this->otherAttorney($deal, $access),
            'documents'      => $this->documents->forDeal($deal, $row['roles']),
        ];
    }

    public function address(Deal $deal): string
    {
        $address = trim((string) $deal->property_address);
        if ($address === '' && $deal->property_id) {
            $property = Property::withoutGlobalScopes()->find($deal->property_id);
            $address  = $property ? trim((string) $property->buildDisplayAddress()) : '';
        }

        return $address !== '' ? $address : 'Address not captured yet';
    }

    /** The agency's own deal number, as the deal register shows it ("#123"); the file number when a deal has no number. */
    private function number(Deal $deal): string
    {
        if (! empty($deal->deal_no)) {
            return '#' . $deal->deal_no;
        }

        return trim((string) $deal->file_no) !== '' ? trim((string) $deal->file_no) : '—';
    }

    private function suburb(Deal $deal): string
    {
        $property = $deal->property_id ? Property::withoutGlobalScopes()->find($deal->property_id) : null;

        return trim((string) ($property?->suburb ?? ''));
    }

    private function reference(Deal $deal): ?string
    {
        $ref = trim((string) ($deal->file_no ?: ''));

        return $ref !== '' ? $ref : ($deal->deal_no ? 'Deal ' . $deal->deal_no : null);
    }

    /** "Where it is", in plain words. */
    public function stage(Deal $deal): string
    {
        $twin = $this->scope->twin($deal);
        if ($this->scope->isCancelled($deal, $twin)) {
            return 'Cancelled';
        }
        if ($this->scope->isRegistered($deal)) {
            return 'Registered';
        }
        if ($this->lodgedOn($deal)) {
            return 'Lodged at the Deeds Office';
        }
        if (! empty($deal->granted_at) || $deal->accepted_status === 'G') {
            return $deal->deal_type === 'bond' ? 'Bond granted' : 'Conditions met';
        }

        return 'Offer accepted';
    }

    private function lodgedOn(Deal $deal): ?Carbon
    {
        $step = DealStepInstance::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('agency_id', $deal->agency_id)
            ->where(function ($q) use ($deal) {
                $q->where('dr1_deal_id', $deal->id);
                if ($deal->deal_v2_id) {
                    $q->orWhere('deal_id', $deal->deal_v2_id);
                }
            })
            ->where('name', 'like', '%Lodg%')->where('status', 'completed')
            ->orderBy('id')->first();
        $when = $step?->actual_date ?: $step?->completed_at;

        return $when ? Carbon::parse($when) : null;
    }

    private function date(string $label, $value): ?array
    {
        return $value ? ['label' => $label, 'date' => Carbon::parse($value)] : null;
    }

    /** Buyer/seller names: the deal's own parties first, the captured name text as the fallback. */
    /**
     * The document lines of one deal: type, name, date, whether it can open in the browser, and WHOSE it is.
     * "Belongs to" reads the document's own contact link (document_contacts, written when it was filed to the deal's
     * parties) but only for people who are seller or buyer on THIS deal - never another contact the file touches.
     * A document tied to no party of the deal is "Deal". Grouped seller side, buyer side, then deal; newest first inside.
     */
    private function files(Deal $deal, $docs): array
    {
        $side = [];   // contact id => 'seller' | 'buyer'
        foreach (DB::table('deal_contacts')->where('deal_id', $deal->id)->whereIn('role', ['seller', 'co_seller', 'buyer', 'co_buyer'])->get(['contact_id', 'role']) as $r) {
            $side[(int) $r->contact_id] ??= str_contains($r->role, 'seller') ? 'seller' : 'buyer';
        }
        $names = $side === [] ? collect() : Contact::withoutGlobalScopes()->where('agency_id', $deal->agency_id)->whereIn('id', array_keys($side))->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => trim((string) ($c->full_name ?: ($c->first_name . ' ' . $c->last_name)))]);
        $links = $side === [] || $docs->isEmpty() ? collect() : DB::table('document_contacts')
            ->whereIn('document_id', $docs->pluck('id'))->whereIn('contact_id', array_keys($side))->get(['document_id', 'contact_id'])->groupBy('document_id');

        return $docs->map(function ($d) use ($side, $names, $links) {
            $who = collect($links->get($d->id, []))->map(fn ($l) => ['side' => $side[(int) $l->contact_id], 'name' => $names->get((int) $l->contact_id, '')])
                ->filter(fn ($w) => $w['name'] !== '')->sortBy(fn ($w) => $w['side'] === 'seller' ? 0 : 1)->values();
            $rank = $who->isEmpty() ? 2 : ($who->contains(fn ($w) => $w['side'] === 'seller') ? 0 : 1);

            return [
                'id'    => (int) $d->id,
                'type'  => $d->documentType?->label ?: 'Document',
                'name'  => (string) $d->original_name,
                'filed' => $d->filed_at ? Carbon::parse($d->filed_at) : null,
                'view'  => $d->inlineMimeType() !== null,   // PDF / image: can open in the browser; anything else is download only
                'whose' => $who->isEmpty() ? 'Deal' : $who->map(fn ($w) => ($w['side'] === 'seller' ? 'Seller' : 'Buyer') . ' · ' . $w['name'])->implode(', '),
                'rank'  => $rank,
            ];
        })->sort(fn ($a, $b) => [$a['rank'], -($b['filed']?->timestamp ?? 0)] <=> [$b['rank'], -($a['filed']?->timestamp ?? 0)])->values()->all();
    }

    private function partyNames(Deal $deal, string $role): string
    {
        $roles = $role === 'buyer' ? ['buyer', 'co_buyer'] : ['seller', 'co_seller'];
        $ids   = DB::table('deal_contacts')->where('deal_id', $deal->id)->whereIn('role', $roles)->pluck('contact_id');
        $names = $ids->isEmpty() ? collect() : Contact::withoutGlobalScopes()->where('agency_id', $deal->agency_id)->whereIn('id', $ids)->get()
            ->map(fn ($c) => trim((string) ($c->full_name ?: ($c->first_name . ' ' . $c->last_name))))->filter();
        if ($names->isNotEmpty()) {
            return $names->implode(' and ');
        }
        $fallback = trim((string) ($role === 'buyer' ? $deal->buyer_name : $deal->seller_name));

        return $fallback !== '' ? $fallback : '—';
    }

    /** The agency's agent on the deal: the attorney's person to phone. */
    private function agent(Deal $deal): array
    {
        $user = $deal->agents()->withoutGlobalScopes()->orderByRaw("CASE deal_user.side WHEN 'listing' THEN 0 ELSE 1 END")->first();
        if (! $user instanceof User) {
            return [];
        }

        return [
            'name'  => trim((string) $user->name),
            'phone' => trim((string) ($user->cell ?: $user->phone)) ?: null,
            'email' => trim((string) ($user->outward_email ?: $user->email)) ?: null,
        ];
    }

    /** The other attorney on the deal (the bond attorney for the transferring attorney, and vice versa). */
    private function otherAttorney(Deal $deal, AttorneyPortalAccess $access): ?array
    {
        $roles = $this->scope->rolesOn($deal, $access);
        $pairs = [];
        if (! in_array(AttorneyPortalScope::ROLE_TRANSFER, $roles, true) && $deal->attorney_provider_id) {
            $pairs[] = ['Transferring attorney', $deal->attorney_provider_id, $deal->attorney_contact_id];
        }
        if (! in_array(AttorneyPortalScope::ROLE_BOND, $roles, true) && $deal->bond_attorney_provider_id) {
            $pairs[] = ['Bond attorney', $deal->bond_attorney_provider_id, $deal->bond_attorney_contact_id];
        }
        foreach ($pairs as [$label, $firmId, $contactId]) {
            $firm    = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $deal->agency_id)->find($firmId);
            $contact = $contactId ? AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $deal->agency_id)->find($contactId) : null;
            if ($firm || $contact) {
                return ['label' => $label, 'firm' => $firm?->name, 'person' => trim((string) ($contact?->attorney_name ?: $contact?->contact_person)) ?: null];
            }
        }

        return null;
    }
}
