<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FormatsMobileRentalResponses;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Services\Rentals\RentalDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-508 P3 - notices to review on the phone (.ai/specs/at-508-mobile-rentals.md section 4 P3): the notices a tenant or landlord has given, waiting
 * for the agent. The web notices hub (CoreX\LeaseNoticeController index, .ai/specs/lease-notices.md) is the reference and it is read-only here -
 * accepting / rejecting a notice stays on the web and on the existing /api/v1/leases/{lease}/renewal/* routes.
 *
 * Scoping is the lease's: a notice is exactly as visible as its lease (Lease::scopeVisibleTo, own / branch / agency), at the query layer.
 */
class MobileRentalNoticeController extends Controller
{
    use FormatsMobileRentalResponses;

    private const PER_PAGE = 25;

    /**
     * Search `q`: property address, tenant name, the name of whoever gave the notice, the reason. Default: notices still to review (status=received),
     * oldest first. Sort: date (the notice date; default, `direction` asc) | status. Filters: status (received | accepted | rejected | withdrawn | all),
     * date_from / date_to (on the notice date), page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['date', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in(array_merge(LeaseNotice::STATUSES, ['all']))],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = LeaseNotice::query()
            ->with(['lease.property', 'lease.tenants.contact', 'givenByContact'])
            ->whereIn('lease_id', Lease::query()->withTrashed()->visibleTo($user)->select('leases.id'));
        RentalDataScope::pinOwnerToWorkingAgency($query, $user, 'lease_notices.agency_id');

        // default = what needs the agent: notices waiting for review
        $status = $data['status'] ?? LeaseNotice::STATUS_RECEIVED;
        if ($status !== 'all') {
            $query->where('lease_notices.status', $status);
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('lease_notices.notice_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('lease_notices.notice_date', '<=', $data['date_to']);
        }
        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = self::likeTerm($term);
            $query->where(function ($q) use ($term, $like) {
                $q->where('lease_notices.reason', 'like', $like)
                    ->orWhereHas('lease.property', fn ($p) => $p->withTrashed()->searchAddress($term))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]))
                    ->orWhereHas('givenByContact', fn ($c) => $c->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]));
            });
        }

        $dir = ($data['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        if (($data['sort'] ?? 'date') === 'status') {
            $query->orderBy('lease_notices.status', $dir)->orderBy('lease_notices.notice_date');
        } else {
            $query->orderBy('lease_notices.notice_date', $dir);
        }
        $query->orderBy('lease_notices.id');

        $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $this->pageNumber($request));

        return $this->listResponse(
            $page->getCollection()->map(fn (LeaseNotice $n) => $this->row($n))->values()->all(),
            $page,
            $status === LeaseNotice::STATUS_RECEIVED && ! $request->hasAny(['q', 'date_from', 'date_to'])
                ? 'No notices are waiting for your review.'
                : 'No notices match. Clear the search or filters to see more.'
        );
    }

    /** @return array<string,mixed> */
    private function row(LeaseNotice $n): array
    {
        $lease = $n->lease;
        $age = $n->isReceived() && $n->notice_date ? (int) $n->notice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false) : null;

        return [
            'id' => $n->id,
            'status' => $n->status,
            'status_label' => $n->statusLabel(),
            'given_by' => $n->given_by,
            'given_by_label' => $n->givenByLabel(),
            'given_by_name' => $n->givenByContact?->full_name,
            'via' => $n->via,
            'notice_date' => $n->notice_date?->toDateString(),
            'intended_end_date' => $n->intended_end_date?->toDateString(),
            'accepted_end_date' => $n->accepted_end_date?->toDateString(),
            'reason' => $n->reason,
            'has_attachment' => $n->hasAttachment(),
            'decided_at' => $n->decided_at?->toIso8601String(),
            'age_days' => $age,
            'lease_id' => $n->lease_id,
            'property' => $lease?->property ? ['id' => $lease->property->id, 'address' => $lease->property->buildDisplayAddress()] : null,
            'tenant_names' => $lease?->tenantNames(),
        ];
    }
}
