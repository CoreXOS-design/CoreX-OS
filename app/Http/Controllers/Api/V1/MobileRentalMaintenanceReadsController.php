<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceProvider;
use App\Services\Rentals\RentalJobCardListQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile parity, two reads the web screens have and the phone had no way to open.
 * .ai/specs/at-508-mobile-parity-addendum.md §5.
 *
 *   GET /mobile/rental-job-cards      the web job-card list (search, status, crew, property, dates, tiles, own / branch / all)
 *   GET /mobile/rental-suppliers      the contractors an agent picks from to assign a work order (agency_service_providers)
 *
 * No rule is decided here. The job-card list IS RentalJobCardListQuery (the object the web list and its print-out share), so scoping, search,
 * sort and tiles cannot differ from the web. Money is not in the rows; the job card's own screen already gates it behind view_costs.
 * Lists follow the app's list contract: ?q, ?sort, filters, ?page, 25 per page, {data, meta: {page, per_page, total, last_page, empty_reason}}.
 */
class MobileRentalMaintenanceReadsController extends Controller
{
    private const PER_PAGE = 25;

    public function jobCards(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'rental_crew_id' => ['nullable', 'integer'],
            'property_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
            'needs_pricing' => ['nullable', 'boolean'],
            'awaiting_owner' => ['nullable', 'boolean'],
            'variation_pending' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'scope' => ['nullable', Rule::in(['own', 'branch', 'all'])],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $list = new RentalJobCardListQuery($user, $v['scope'] ?? null, [
            'q' => $v['q'] ?? null,
            'status' => $v['status'] ?? null,
            'overdue' => $request->boolean('overdue'),
            'needs_pricing' => $request->boolean('needs_pricing'),
            'awaiting_owner' => $request->boolean('awaiting_owner'),
            'variation_pending' => $request->boolean('variation_pending'),
            'rental_crew_id' => $v['rental_crew_id'] ?? null,
            'property_id' => $v['property_id'] ?? null,
            'from' => ! empty($v['date_from']) ? Carbon::parse($v['date_from'])->startOfDay() : null,
            'to' => ! empty($v['date_to']) ? Carbon::parse($v['date_to'])->endOfDay() : null,
            'archived' => $request->boolean('archived'),
            'sort' => $v['sort'] ?? null,
            'direction' => $v['direction'] ?? null,
        ]);

        $page = $list->paginate((int) ($v['page'] ?? 1), self::PER_PAGE);

        $rows = collect($page->items())->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'status' => $c->status,
            'property_address' => $c->property?->buildDisplayAddress(),
            'tenant_names' => $c->lease?->tenantNames(),
            'crew' => $c->crew ? ['id' => $c->crew->id, 'name' => $c->crew->name] : null,
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'archived' => $c->trashed(),
        ])->values();

        return response()->json([
            'data' => $rows,
            'tiles' => $list->tileCounts(),
            'scope' => $list->resolvedScope(),
            'scope_options' => RentalJobCardListQuery::scopeOptionsFor($user),
            'meta' => [
                'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'empty_reason' => $rows->isEmpty()
                    ? ($list->hasAny() ? 'No job cards match those filters.' : 'There are no job cards yet. They are created from a work order.')
                    : null,
            ],
        ]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // BelongsToAgency scopes this to the caller's agency; inactive suppliers are never offered for a new assignment.
        // maintenance contractors only: attorneys and bond originators are never offered for a work order (the web's own rule, which quotes and invoices also enforce)
        $query = AgencyServiceProvider::query()->where('is_active', true)->maintenanceContractors()->matching($v['q'] ?? null);
        if (! empty($v['kind'])) {
            $query->where('kind', $v['kind']);
        }
        $page = $query->orderByDesc('is_preferred')->orderBy('company')->orderBy('name')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($v['page'] ?? 1));

        $rows = collect($page->items())->map(fn (AgencyServiceProvider $s) => [
            'id' => $s->id,
            'name' => $s->displayName(),
            'company' => $s->company,
            'kind' => (string) ($s->kind ?: AgencyServiceProvider::KIND_CONTRACTOR),
            'kind_label' => AgencyServiceProvider::kindLabel($s->kind),
            'specialty' => $s->specialty,
            'phone' => $s->phone,
            'email' => $s->email,
            'is_preferred' => (bool) $s->is_preferred,
            'callout_fee_mode' => $s->callout_fee_mode,
        ])->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'empty_reason' => $rows->isEmpty() ? 'No contractors match. They are added under Service Providers on the web.' : null,
            ],
        ]);
    }
}
