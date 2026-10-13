<?php

namespace App\Services\RoadToSold;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * AT-454 - what the Road to Sold page shows. Spec: .ai/specs/road-to-sold.md section 10.5.
 *
 * Four views: today's list and "no owner" read rts_list_items for a day (lane 2's table);
 * "no answer" and "wrong number" read the agent's CURRENT outcomes across all days, so a property
 * can be found fast when the owner phones back. Every read goes through the models' visibleTo()
 * scope. The filtered set is capped at MAX_ROWS, sorted in memory (price and days on market live on
 * the listing, not the list row) and then paged.
 */
class CallListService
{
    public const VIEWS = ['today', 'no_answer', 'wrong_number', 'no_owner'];
    public const STATUSES = ['open', 'worked', 'missed', 'all'];
    public const SORTS = ['order', 'address', 'price', 'dom', 'last_outcome'];
    public const MAX_ROWS = 500;
    public const PER_PAGE = 50;

    public function __construct(private readonly OutcomeService $outcomes)
    {
    }

    /**
     * The agents $viewer may look at (own: themselves only).
     *
     * @return Collection<int, User>
     */
    public function viewableAgents(User $viewer): Collection
    {
        $level = Scope::level($viewer);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $self = (int) $viewer->ownershipUserId();

        if ($level === 'own' || $agencyId <= 0) {
            return User::query()->whereIn('id', [$self])->get(['id', 'name', 'branch_id']);
        }

        // An assistant is never a row of their own (spec 11.1): their calls are the agent's, so they would double the target.
        $q = User::query()->agencyMembers()->where('agency_id', $agencyId)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', false));
        if ($level === 'branch') {
            $branchId = $viewer->effectiveBranchId();
            $branchId ? $q->where('branch_id', $branchId) : $q->whereIn('id', [$self]);
        }

        return $q->orderBy('name')->limit(300)->get(['id', 'name', 'branch_id']);
    }

    /**
     * @param  array{view?:string,status?:string,selection?:?string,q?:?string,sort?:string,dir?:string,date?:?string,page?:int}  $f
     * @return array{rows:LengthAwarePaginator, selections:array<string,string>, counts:array<string,int>, capped:bool}
     */
    public function page(User $viewer, int $agentId, array $f, string $track = 'sales'): array
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $view = (string) ($f['view'] ?? 'today');
        $view = in_array($view, self::VIEWS, true) ? $view : 'today';
        $status = (string) ($f['status'] ?? 'open');
        $status = in_array($status, self::STATUSES, true) ? $status : 'open';
        $sort = (string) ($f['sort'] ?? 'order');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'order';
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $search = trim((string) ($f['q'] ?? ''));
        $selection = trim((string) ($f['selection'] ?? '')) ?: null;
        $date = $this->date($f['date'] ?? null);
        $page = max(1, (int) ($f['page'] ?? 1));

        $rows = collect();
        $capped = false;

        if (in_array($view, ['today', 'no_owner'], true)) {
            $q = RtsListItem::query()->visibleTo($viewer)->forDay($agentId, $date, $track)
                ->with(['trackedProperty.ownerContact.primaryPhone', 'trackedProperty.owners.contact.primaryPhone',
                    'trackedProperty.prospectingListings', 'prospectingListing', 'property']);
            if ($status !== 'all') {
                $q->where('rts_list_items.status', $status);
            }
            if ($selection) {
                $q->where('rts_list_items.selection', $selection);
            }
            if ($view === 'no_owner') {
                $q->whereHas('trackedProperty', fn ($t) => $t->whereNull('owner_contact_id')
                    ->whereDoesntHave('owners', fn ($o) => $o->whereNotNull('contact_id')));
            }
            $this->search($q, $search);
            $items = $q->orderBy('rts_list_items.position')->orderBy('rts_list_items.id')->limit(self::MAX_ROWS + 1)->get();
            $capped = $items->count() > self::MAX_ROWS;
            $items = $items->take(self::MAX_ROWS);
            $current = $this->outcomes->currentFor($agencyId, $items->pluck('tracked_property_id')->filter()->all(), $track);
            $rows = $items->map(fn (RtsListItem $i) => $this->rowFromItem($i, $current->get($i->tracked_property_id), $agencyId));
        } else {
            $q = RtsContactOutcome::query()->visibleTo($viewer)->where('user_id', $agentId)
                ->where('track', $track)->where('is_current', true)
                ->with(['agent:id,name', 'trackedProperty.ownerContact.primaryPhone', 'trackedProperty.owners.contact.primaryPhone',
                    'trackedProperty.prospectingListings']);
            $view === 'no_answer'
                ? $q->where('no_answer_streak', '>', 0)
                : $q->whereNotNull('list_tag');
            $this->search($q, $search);
            $items = $q->orderByDesc('occurred_at')->limit(self::MAX_ROWS + 1)->get();
            $capped = $items->count() > self::MAX_ROWS;
            $rows = $items->take(self::MAX_ROWS)->map(fn (RtsContactOutcome $o) => $this->rowFromOutcome($o, $agencyId));
        }

        $rows = $this->sort($rows->values(), $sort, $dir);
        $total = $rows->count();
        $paged = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $total, self::PER_PAGE, $page, [
            'path' => request()->url(), 'query' => request()->query(),
        ]);

        return [
            'rows'       => $paged,
            'selections' => $this->selectionLabels($agentId, $date, $track, $viewer),
            'counts'     => $this->dayCounts($viewer, $agentId, $date, $track),
            'capped'     => $capped,
        ];
    }

    /** Worked / counting toward the target / added on top, for the day. */
    public function dayCounts(User $viewer, int $agentId, Carbon $date, string $track = 'sales'): array
    {
        $base = fn () => RtsListItem::query()->visibleTo($viewer)->forDay($agentId, $date, $track);
        $counting = $base()->countingTowardTarget();

        return [
            'target_items'  => (clone $counting)->count(),
            'worked'        => (clone $counting)->where('rts_list_items.status', RtsListItem::STATUS_WORKED)->count(),
            'added'         => $base()->where('rts_list_items.origin', RtsListItem::ORIGIN_ADDED)->count(),
            'added_worked'  => $base()->where('rts_list_items.origin', RtsListItem::ORIGIN_ADDED)
                ->where('rts_list_items.status', RtsListItem::STATUS_WORKED)->count(),
            'any'           => $base()->count(),
        ];
    }

    /** @return array<string,string> selection key => label, for the filter. */
    private function selectionLabels(int $agentId, Carbon $date, string $track, User $viewer): array
    {
        $keys = RtsListItem::query()->visibleTo($viewer)->forDay($agentId, $date, $track)
            ->distinct()->pluck('rts_list_items.selection')->all();

        $labels = [];
        foreach ($keys as $k) {
            $labels[$k] = $this->selectionLabel($k);
        }
        asort($labels);

        return $labels;
    }

    public function selectionLabel(?string $key): string
    {
        return match ($key) {
            'my_buyers_sourcing'     => 'My buyers need stock',
            'agency_buyers_sourcing' => 'Agency buyers need stock',
            'hot_pockets'            => 'Hottest sales pockets',
            'price_drops'            => 'Price drops',
            'new_on_market'          => 'New on market',
            'long_on_market'         => 'Long on market',
            'owner_known'            => 'Owner already known',
            'my_expired_stock'       => 'My expired stock',
            'my_stock_not_selling'   => 'My stock that is not selling',
            'follow_up'              => 'Follow-up due',
            null, ''                 => '',
            default                  => ucfirst(str_replace('_', ' ', (string) $key)),
        };
    }

    // ── Rows ────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function rowFromItem(RtsListItem $i, ?RtsContactOutcome $current, int $agencyId): array
    {
        $tp = $i->trackedProperty;
        $listing = $i->prospectingListing ?: $this->listingOf($tp);

        return $this->row($tp, $listing, $i->property, $current, [
            'list_item_id'       => $i->id,
            'tracked_property_id' => $i->tracked_property_id,
            'prospecting_listing_id' => $i->prospecting_listing_id,
            'property_id'        => $i->property_id,
            'selection'          => $i->selection,
            'why'                => $i->reason ?: $this->selectionLabel($i->selection),
            'status'             => $i->status,
            'origin'             => $i->origin,
            'position'           => $i->position,
        ], $agencyId);
    }

    /** @return array<string,mixed> */
    private function rowFromOutcome(RtsContactOutcome $o, int $agencyId): array
    {
        $tp = $o->trackedProperty;
        $why = $o->dropped
            ? 'Dropped after ' . $o->no_answer_streak . ' no-answers in a row'
            : ($o->list_tag ? 'On the list "' . $o->list_tag . '"' : 'Called ' . $o->occurred_at->format('j M') . ': ' . $o->outcome_label);

        return $this->row($tp, $this->listingOf($tp), null, $o, [
            'list_item_id'       => null,
            'tracked_property_id' => $o->tracked_property_id,
            'prospecting_listing_id' => $o->prospecting_listing_id,
            'property_id'        => $o->property_id,
            'selection'          => null,
            'why'                => $why,
            'status'             => $o->dropped ? 'dropped' : 'open',
            'origin'             => null,
            'position'           => 0,
        ], $agencyId);
    }

    private function listingOf(?TrackedProperty $tp): ?ProspectingListing
    {
        if (! $tp) {
            return null;
        }
        $listings = $tp->relationLoaded('prospectingListings') ? $tp->prospectingListings : $tp->prospectingListings()->get();

        return $listings->sortByDesc(fn ($l) => [(int) $l->is_active, $l->last_seen_at?->timestamp ?? 0])->first();
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function row(?TrackedProperty $tp, ?ProspectingListing $listing, $property, ?RtsContactOutcome $current, array $base, int $agencyId): array
    {
        $owner = $this->ownerOf($tp);
        $price = $listing?->price ?: ($property?->price ?: $tp?->last_known_asking_price);
        $dom = $listing ? $listing->daysOnMarket() : ($property?->listed_date ? (int) Carbon::parse($property->listed_date)->diffInDays(now()->startOfDay()) : null);

        return $base + [
            'address'     => $tp ? $tp->displayAddress() : ($listing?->address ?: '(address not available)'),
            'street'      => $tp ? trim(($tp->streetLine() ?: '')) : '',
            'suburb'      => $tp?->suburb,
            'price'       => $price ? (int) $price : null,
            'dom'         => $dom,
            'agency_name' => $property && ! $listing ? 'Your agency' : ($listing?->agency_name ?: null),
            'owner_name'  => $owner?->full_name,
            'owner_phone' => $owner?->primaryPhone?->phone ?: $owner?->phone,
            'owner_contact_id' => $owner?->id,
            'find_owner_url'   => $tp && ! $owner ? route('seller-outreach.entry.from-tracked-property', $tp->id) : null,
            'last_outcome' => $current ? [
                'label'   => $current->outcome_label,
                'kind'    => $current->kind,
                'when'    => $current->occurred_at,
                'by'      => optional($current->agent)->name,
                'note'    => $current->note,
                'streak'  => $current->no_answer_streak,
                'dropped' => $current->dropped,
                'next_at' => $current->next_action_at,
                'tag'     => $current->list_tag,
            ] : null,
            'last_at'     => $current?->occurred_at?->timestamp,
        ];
    }

    private function ownerOf(?TrackedProperty $tp): ?Contact
    {
        if (! $tp) {
            return null;
        }
        if ($tp->ownerContact) {
            return $tp->ownerContact;
        }
        $owners = $tp->relationLoaded('owners') ? $tp->owners : $tp->owners()->with('contact.primaryPhone')->get();

        return $owners->first(fn ($o) => $o->contact)?->contact;
    }

    // ── Filtering helpers ───────────────────────────────────────────────────────────────────

    private function search($query, string $q): void
    {
        if ($q === '') {
            return;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $query->whereHas('trackedProperty', function ($t) use ($like) {
            $t->where(function ($w) use ($like) {
                $w->where('street_name', 'like', $like)
                    ->orWhere('street_number', 'like', $like)
                    ->orWhere('suburb', 'like', $like)
                    ->orWhere('town', 'like', $like)
                    ->orWhere('complex_name', 'like', $like)
                    ->orWhereHas('ownerContact', fn ($c) => $c->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)->orWhere('entity_name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('owners', fn ($o) => $o->where('name', 'like', $like)
                        ->orWhereHas('contact', fn ($c) => $c->where('phone', 'like', $like)));
            });
        });
    }

    /** @param  Collection<int, array<string,mixed>>  $rows */
    private function sort(Collection $rows, string $sort, string $dir): Collection
    {
        if ($sort === 'order') {
            return $dir === 'desc' ? $rows->reverse()->values() : $rows;
        }
        $key = match ($sort) {
            'address'      => fn ($r) => mb_strtolower((string) $r['address']),
            'price'        => fn ($r) => $r['price'] ?? PHP_INT_MAX,
            'dom'          => fn ($r) => $r['dom'] ?? PHP_INT_MAX,
            default        => fn ($r) => $r['last_at'] ?? 0,
        };

        return $rows->sortBy($key, SORT_REGULAR, $dir === 'desc')->values();
    }

    private function date(?string $value): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    public function resolveDate(?string $value): Carbon
    {
        return $this->date($value);
    }
}
