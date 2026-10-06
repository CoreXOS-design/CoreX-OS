<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-work-orders.md §14.23 — the ONE place a Job Cards list query is
 * built. The list rows, the status tiles, the Own/Branch/All counts and the Print
 * list all come from here, so what the screen counts is what the screen lists is
 * what gets printed.
 *
 * Scoping is the first thing every query does: RentalJobCard::visibleTo() (global
 * AgencyScope + scopeVisibleTo(), clamped to the user's role ceiling). Every filter
 * below only narrows that; none can widen it.
 */
class RentalJobCardListQuery
{
    public const SORTS = ['property', 'title', 'tenant', 'crew', 'status', 'due_at', 'created_at', 'total'];
    public const DEFAULT_SORT = 'due_at';
    public const PER_PAGE = 25;

    /** Workflow order, so sorting by status groups cards the way the job actually moves. */
    private const STATUS_ORDER = ['draft', 'quoted', 'approved', 'scheduled', 'in_progress', 'disputed', 'completed', 'cancelled'];

    /** @var array<int, bool> agency id => capture-prices setting, memoised per query object */
    private array $pricesOn = [];

    private string $sort;
    private string $direction;

    /**
     * @param array{
     *   q?: ?string, status?: ?string, overdue?: bool, needs_pricing?: bool, rental_crew_id?: mixed, property_id?: mixed,
     *   from?: ?Carbon, to?: ?Carbon, archived?: bool, sort?: ?string, direction?: ?string
     * } $filters  from/to are already-resolved instants (the controller owns agency-timezone parsing).
     */
    public function __construct(
        private readonly User $user,
        private readonly ?string $requestedScope,
        private readonly array $filters = [],
    ) {
        $sort = $filters['sort'] ?? null;
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
        $this->direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    /** The scopes this user may switch between — never wider than their role ceiling. */
    public static function scopeOptionsFor(User $user): array
    {
        return match (self::ceilingFor($user)) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };
    }

    public function resolvedScope(): string
    {
        return PermissionService::clampScope($this->requestedScope, self::ceilingFor($this->user));
    }

    private static function ceilingFor(User $user): string
    {
        return PermissionService::getDataScope($user, 'rental_job_cards') ?? 'own';
    }

    /** Scoped + every filter EXCEPT status/overdue — what the tiles and scope counts count. */
    public function filtered(?string $scope = null): Builder
    {
        $query = RentalJobCard::query()->visibleTo($this->user, $scope ?? $this->requestedScope);

        $this->applySearch($query, trim((string) ($this->filters['q'] ?? '')));

        if (! empty($this->filters['rental_crew_id'])) {
            $query->where('rental_job_cards.rental_crew_id', $this->filters['rental_crew_id']);
        }
        if (! empty($this->filters['property_id'])) {
            $query->where('rental_job_cards.property_id', $this->filters['property_id']);
        }
        if (($this->filters['from'] ?? null) instanceof Carbon) {
            $query->where('rental_job_cards.due_at', '>=', $this->filters['from']);
        }
        if (($this->filters['to'] ?? null) instanceof Carbon) {
            $query->where('rental_job_cards.due_at', '<=', $this->filters['to']);
        }
        if (! empty($this->filters['archived'])) {
            $query->onlyTrashed();
        }

        return $query;
    }

    /** filtered() plus the status/overdue tile choice — exactly the rows the list shows. */
    public function rows(): Builder
    {
        $query = $this->filtered();

        // §17.5.5 — "Needs pricing": an open price request, or crew lines the office has not yet accepted or rejected.
        if (! empty($this->filters['needs_pricing'])) {
            $query->needsPricing();
        }

        if (! empty($this->filters['overdue'])) {
            $query->overdue();
        } elseif (! empty($this->filters['status'])) {
            $query->where('rental_job_cards.status', $this->filters['status']);
        }

        return $query;
    }

    public function paginate(int $page = 1, int $perPage = self::PER_PAGE): PaginatorContract
    {
        if ($this->sort === 'total') {
            return $this->paginateByTotal($page, $perPage);
        }

        return $this->withListRelations($this->sorted($this->rows()))
            ->paginate($perPage, ['rental_job_cards.*'], 'page', $page);
    }

    /** Every matching row, sorted — the Print list. */
    public function all(): Collection
    {
        if ($this->sort === 'total') {
            return $this->sortedByTotal($this->withListRelations($this->rows())->get());
        }

        return $this->withListRelations($this->sorted($this->rows()))->get();
    }

    /** @return array<string, int> */
    public function tileCounts(): array
    {
        $counts = ['total' => $this->filtered()->count()];
        foreach (self::STATUS_ORDER as $status) {
            $counts[$status] = $this->filtered()->where('rental_job_cards.status', $status)->count();
        }
        $counts['overdue'] = $this->filtered()->overdue()->count();
        $counts['needs_pricing'] = $this->filtered()->needsPricing()->count();

        return $counts;
    }

    /** @return array<string, int> scope => count under the current non-scope filters */
    public function scopeCounts(): array
    {
        $counts = [];
        foreach (self::scopeOptionsFor($this->user) as $scope) {
            $counts[$scope] = $this->filtered($scope)->count();
        }

        return $counts;
    }

    /** True when the user has at least one job card in scope, regardless of filters. */
    public function hasAny(): bool
    {
        return RentalJobCard::query()->visibleTo($this->user, $this->requestedScope)->exists();
    }

    /** VAT-inclusive total as shown, or null when the agency captures no prices on job cards. */
    public function inclusiveTotal(RentalJobCard $card): ?float
    {
        $pricesOn = $this->pricesOn[$card->agency_id] ??= RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id);

        return $pricesOn ? app(RentalJobCardVatService::class)->inclusiveTotal($card) : null;
    }

    /** The quote currently out with the owner, or null — read off the already-loaded revisions. */
    public static function currentQuoteOf(RentalJobCard $card)
    {
        return $card->quoteRevisions->first(fn ($q) => $q->superseded_at === null);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = "%{$search}%";
        // "123", "#123" and "JC-123" all mean the card whose number is 123.
        $number = preg_match('/^(?:jc-?|#)?\s*(\d+)$/i', $search, $m) ? (int) $m[1] : null;

        $query->where(function (Builder $q) use ($search, $like, $number) {
            $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                ->orWhereHas('lease.tenants.contact', function ($c) use ($like) {
                    $c->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]);
                })
                ->orWhereHas('crew', fn ($c) => $c->where('name', 'like', $like))
                ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'like', $like)) // legacy pre-crew rows
                ->orWhere('rental_job_cards.title', 'like', $like);
            if ($number !== null) {
                $q->orWhere('rental_job_cards.id', $number);
            }
        });
    }

    private function withListRelations(Builder $query): Builder
    {
        return $query->with(['property', 'lease.tenants.contact', 'crew', 'assignedUser', 'quoteRevisions', 'lines', 'agency']);
    }

    /** SQL sorts. Rows with nothing to sort on (no due date, no crew…) always go last, either direction. */
    private function sorted(Builder $query): Builder
    {
        $dir = $this->direction;
        $query->select('rental_job_cards.*');

        switch ($this->sort) {
            case 'property':
                $query->leftJoin('properties as sort_p', 'sort_p.id', '=', 'rental_job_cards.property_id')
                    ->orderByRaw("COALESCE(NULLIF(CONCAT_WS(' ', sort_p.complex_name, sort_p.street_name, sort_p.street_number), ''), sort_p.address, sort_p.title) {$dir}");
                break;
            case 'tenant':
                $query->leftJoinSub(
                    DB::table('lease_tenants as lt')->join('contacts as c', 'c.id', '=', 'lt.contact_id')
                        ->groupBy('lt.lease_id')
                        ->selectRaw("lt.lease_id, MIN(CONCAT_WS(' ', c.first_name, c.last_name)) as tenant_name"),
                    'sort_t', 'sort_t.lease_id', '=', 'rental_job_cards.lease_id'
                )->orderByRaw('sort_t.tenant_name IS NULL')->orderBy('sort_t.tenant_name', $dir);
                break;
            case 'crew':
                $query->leftJoin('rental_crews as sort_c', 'sort_c.id', '=', 'rental_job_cards.rental_crew_id')
                    ->orderByRaw('sort_c.name IS NULL')->orderBy('sort_c.name', $dir);
                break;
            case 'status':
                $query->orderByRaw('FIELD(rental_job_cards.status, ' . implode(',', array_map(fn ($s) => "'{$s}'", self::STATUS_ORDER)) . ') ' . $dir);
                break;
            case 'title':
                $query->orderBy('rental_job_cards.title', $dir);
                break;
            case 'created_at':
                $query->orderBy('rental_job_cards.created_at', $dir);
                break;
            default: // due_at
                $query->orderByRaw('rental_job_cards.due_at IS NULL')->orderBy('rental_job_cards.due_at', $dir);
        }

        return $query->orderByDesc('rental_job_cards.id');
    }

    /** The computed inclusive figure depends on VAT registration and per-line VAT types, so this one sort runs in memory. */
    private function sortedByTotal(Collection $cards): Collection
    {
        $withTotal = $cards->map(fn (RentalJobCard $c) => [$c, $this->inclusiveTotal($c)]);
        $priced = $withTotal->filter(fn ($pair) => $pair[1] !== null);
        $unpriced = $withTotal->filter(fn ($pair) => $pair[1] === null);

        $priced = $this->direction === 'desc'
            ? $priced->sortByDesc(fn ($pair) => $pair[1])
            : $priced->sortBy(fn ($pair) => $pair[1]);

        return $priced->merge($unpriced)->map(fn ($pair) => $pair[0])->values();
    }

    private function paginateByTotal(int $page, int $perPage): PaginatorContract
    {
        $sorted = $this->sortedByTotal($this->withListRelations($this->rows())->get());

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }
}
