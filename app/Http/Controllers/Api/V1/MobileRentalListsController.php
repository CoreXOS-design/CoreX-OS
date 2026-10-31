<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RunsWebAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\LeaseBreachController;
use App\Http\Controllers\CoreX\RentalApplicationController;
use App\Http\Controllers\CoreX\RentalInspectionComparisonController;
use App\Http\Controllers\CoreX\RentalNoticeController;
use App\Models\Lease;
use App\Models\RentalCrew;
use App\Models\RentalInspection;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseAgentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mobile parity, reads: the web lists that are pages with no JSON twin, and the pickers a form needs. .ai/specs/at-508-mobile-parity-addendum.md §4.
 *
 * The lists RUN THE WEB ACTION (RunsWebAction) and turn the page's data into JSON (answerView), so the query - own / branch / agency scope,
 * search, filters, sort, tiles - is the web's own and cannot drift. The filter names are the web's (?q, ?status, ?kind, ?date_from, ?date_to,
 * ?archived, ?sort, ?dir, ?tile, ?scope, ?page ...). Rows carry what the web row shows; nothing else.
 * The pickers return only what the web's own dropdowns offer.
 */
class MobileRentalListsController extends Controller
{
    use RunsWebAction;

    public function breaches(Request $request): JsonResponse
    {
        return $this->runWebAction($request, LeaseBreachController::class, 'index');
    }

    public function sentNotices(Request $request): JsonResponse
    {
        return $this->runWebAction($request, RentalNoticeController::class, 'index');
    }

    public function applications(Request $request): JsonResponse
    {
        return $this->runWebAction($request, RentalApplicationController::class, 'index');
    }

    /** The Out inspection against the In: per item what changed, with the agent's finding. Read-only (findings are written by the finding route). */
    public function depositComparison(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        return $this->runWebAction($request, RentalInspectionComparisonController::class, 'show', ['rentalInspection' => $rentalInspection]);
    }

    // ── pickers ──────────────────────────────────────────────────────────────────────────

    /** Who can be booked as the inspector: active users of the caller's agency (the web Schedule form's own list). */
    public function inspectors(Request $request): JsonResponse
    {
        $users = User::where('agency_id', $request->user()->effectiveAgencyId())->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(), 'default_inspector_id' => $request->user()->id]);
    }

    /** The owner's agent / tenant's agent choices for a lease (the web Agents card's own list; the lease's own branch first). */
    public function leaseAgents(Request $request, Lease $lease): JsonResponse
    {
        return response()->json(['data' => app(LeaseAgentService::class)->selectableAgents((int) $lease->agency_id, $lease->branch_id)]);
    }

    /** The agency's notice templates (breach, notice to vacate, expiry ...), active ones, optionally one type. */
    public function noticeTemplates(Request $request): JsonResponse
    {
        $v = $request->validate(['type' => ['nullable', 'string', 'max:40']]);
        // explicit agency filter on top of the model scope: an owner-role login sees every agency through the scope, a picker must not
        $q = RentalNoticeTemplate::query()->where('agency_id', $request->user()->effectiveAgencyId())->where('is_active', true)->orderBy('name');
        if (! empty($v['type'])) {
            $q->where('notice_type', $v['type']);
        }

        return response()->json(['data' => $q->get()->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'type' => $t->notice_type, 'type_label' => RentalNoticeTemplate::labelFor($t->notice_type),
            'remedy_days' => $t->remedy_days, 'default_clause' => $t->default_clause,
        ])->values()]);
    }

    /** The agency's active crews (the web job-card list's crew filter and the Assign crew choice). */
    public function crews(Request $request): JsonResponse
    {
        return response()->json(['data' => RentalCrew::query()->where('agency_id', $request->user()->effectiveAgencyId())->active()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()]);
    }

    /** The user's own permission keys for the rentals areas, so the app shows only the buttons that will work. Echoes the Role Manager; decides nothing. */
    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $prefixes = ['rental_inspections.', 'rental_inventories.', 'rental_fault_reports.', 'rental_work_orders.', 'rental_job_cards.', 'rental_applications.', 'rental_notices.', 'rental_breaches.', 'leases.', 'lease_notice_terms.'];

        $keys = [];
        $walk = function ($node) use (&$walk, &$keys) {
            if (is_array($node)) {
                if (isset($node['key']) && is_string($node['key'])) {
                    $keys[] = $node['key'];
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk(config('corex-permissions'));

        $out = [];
        foreach (array_unique($keys) as $key) {
            foreach ($prefixes as $p) {
                if (str_starts_with($key, $p)) {
                    $out[$key] = $user->hasPermission($key);
                }
            }
        }
        ksort($out);

        return response()->json(['permissions' => $out]);
    }

    // ── the page data, as JSON ───────────────────────────────────────────────────────────

    protected function answerView(View $view): JsonResponse
    {
        $d = $view->getData();

        return match ($view->name()) {
            'corex.breaches.index' => $this->list($d['breaches'], fn ($b) => [
                'id' => $b->id, 'lease_id' => $b->lease_id, 'kind' => $b->kind, 'breach_party' => $b->breach_party, 'status' => $b->status,
                'tenant' => $b->lease?->tenantNames(), 'property' => $b->lease?->property?->buildDisplayAddress(),
                'amount' => $b->amount !== null ? (float) $b->amount : null, 'clause' => $b->clause, 'reason' => $b->reason,
                'issued_on' => $b->issued_on?->toDateString(), 'remedy_deadline' => $b->remedy_deadline?->toDateString(), 'archived' => $b->trashed(),
            ], ['tiles' => $d['tiles'] ?? [], 'tile_counts' => $d['tileCounts'] ?? [], 'tile' => $d['tile'] ?? '', 'sort' => $d['sort'] ?? null, 'dir' => $d['dir'] ?? null]),
            'corex.rental-notices.index' => $this->list($d['notices'], fn ($n) => [
                'id' => $n->id, 'lease_id' => $n->lease_id, 'notice_type' => $n->notice_type, 'delivery_status' => $n->delivery_status,
                'template' => $n->template?->name, 'tenant' => $n->lease?->tenantNames(), 'property' => $n->lease?->property?->buildDisplayAddress(),
                'sent_at' => $n->sent_at?->toIso8601String(), 'archived' => $n->trashed(),
            ], ['sort' => $d['sort'] ?? null, 'dir' => $d['dir'] ?? null]),
            'corex.rental-applications.index' => $this->list($d['applications'], fn ($a) => [
                'id' => $a->id, 'applicant' => $a->contact?->full_name ?? $a->full_name,
                'property' => $a->property?->buildDisplayAddress() ?? $a->property_address_override, 'status' => $a->status, 'status_label' => $a->displayStatusLabel(),
                'applicant_notified_at' => $a->applicant_notified_at?->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String(),
                'has_lease' => in_array($a->id, (array) ($d['leasedApplicationIds'] ?? []), true), 'archived' => method_exists($a, 'trashed') && $a->trashed(),
            ], ['tile' => $d['tile'] ?? null, 'counts' => $d['counts'] ?? [], 'scope' => $d['resolvedScope'] ?? null, 'scope_options' => $d['scopeOptions'] ?? []]),
            'corex.rental-inspections.partials.deposit-comparison-page' => response()->json([
                'ok' => true,
                'inspection_id' => $d['inspection']->id,
                'comparison' => json_decode((string) json_encode($d['comparison']), true),
                'filters' => $d['filters'] ?? [],
                'difference_labels' => $d['differenceLabels'] ?? [],
                'disposition_labels' => $d['dispositionLabels'] ?? [],
                'room_options' => $d['roomOptions'] ?? [],
                'inventory_id' => $d['inventory']?->id ?? null,
            ]),
            default => response()->json(['ok' => true]),
        };
    }

    /** @param callable(mixed): array $row */
    private function list(LengthAwarePaginator $page, callable $row, array $extra = []): JsonResponse
    {
        $rows = collect($page->items())->map($row)->values();

        return response()->json(['data' => $rows] + $extra + ['meta' => [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
            'empty_reason' => $rows->isEmpty() ? 'Nothing matches. Clear the search or filters.' : null,
        ]]);
    }
}
