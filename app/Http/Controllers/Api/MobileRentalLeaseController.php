<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FormatsMobileRentalResponses;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\Rentals\RentalDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-508 P3 - the staff mobile lease list and lease file (.ai/specs/at-508-mobile-rentals.md section 4 P3). The web lease screens
 * (CoreX\LeaseController index / show) are the reference; the lease file is built from the same LeaseHubService (lifecycle, next step, open items).
 * Read-only: the actions the app offers on a lease (renewal, month-to-month, notices) are the EXISTING /api/v1/leases/{lease}/renewal/* routes.
 *
 * Scoping: the global AgencyScope 404s another agency's id; guardRentalRecordScope() (key 'leases') 403s a same-agency lease outside the
 * user's own / branch ceiling; the list uses Lease::scopeVisibleTo(), so it never holds a lease the file would refuse.
 * Money (rent, deposit) is deliberately NOT in the payload - the spec lists dates, parties, status, documents, signing and renewal/notice state.
 */
class MobileRentalLeaseController extends Controller
{
    use AuthorizesRentalRecordScope;
    use FormatsMobileRentalResponses;

    private const PER_PAGE = 25;

    /** the list's status filter, exactly the web list's: the two halves of the draft status are split as each lease's own page reads it */
    private const STATUS_FILTERS = ['draft', 'signed_awaiting_start', Lease::STATUS_ACTIVE, Lease::STATUS_EXPIRED, Lease::STATUS_CANCELLED];

    /**
     * Search `q`: property address, tenant name, owner (landlord) name, lease reference ("L-12" or "12"). Sort: end_date (default, soonest first) |
     * start_date | property | status, `direction` asc (default) | desc. Filters: status, expiring_within_days (an active lease ending in the next N days),
     * date_from / date_to (on the END date, as the web list does), page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['end_date', 'start_date', 'property', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'expiring_within_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Lease::query()->visibleTo($user)->with(['property.contacts', 'tenants.contact', 'ownerAgent', 'tenantAgent']);
        RentalDataScope::pinOwnerToWorkingAgency($query, $user, 'leases.agency_id');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = self::likeTerm($term);
            $query->where(function ($q) use ($term, $like) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($term))
                    ->orWhereHas('tenants.contact', fn ($c) => $c->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]))
                    // the owner: the same strict landlord role set as Lease::landlordContacts()
                    ->orWhereHas('property.contacts', fn ($c) => $c->whereIn('contact_property.role', ['landlord', 'lessor', 'seller', 'owner'])
                        ->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]))
                    ->orWhere('leases.external_id', 'like', $like);
                if (preg_match('/^(?:L-?)?(\d+)$/i', $term, $m)) {
                    $q->orWhere('leases.id', (int) $m[1]);
                }
            });
        }
        if (! empty($data['status'])) {
            match ($data['status']) {
                'draft' => $query->draftNotSignedRenewal(),
                'signed_awaiting_start' => $query->signedRenewalAwaitingStart(),
                default => $query->where('leases.status', $data['status']),
            };
        }
        if (! empty($data['expiring_within_days'])) {
            $query->where('leases.status', Lease::STATUS_ACTIVE)
                ->whereBetween('leases.end_date', [now()->toDateString(), now()->addDays((int) $data['expiring_within_days'])->toDateString()]);
        }
        if (! empty($data['date_from'])) {
            $query->where('leases.end_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->where('leases.end_date', '<=', $data['date_to']);
        }

        $dir = ($data['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($data['sort'] ?? 'end_date') {
            'start_date' => $query->orderBy('leases.start_date', $dir),
            'status' => $query->orderBy('leases.status', $dir)->orderBy('leases.end_date'),
            'property' => $query->leftJoin('properties', 'properties.id', '=', 'leases.property_id')->select('leases.*')->orderBy('properties.title', $dir),
            default => $query->orderBy('leases.end_date', $dir),
        };
        $query->orderBy('leases.id');

        $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $this->pageNumber($request));

        return $this->listResponse(
            $page->getCollection()->map(fn (Lease $l) => $this->summary($l))->values()->all(),
            $page,
            'No leases match. Clear the search or filters to see more.'
        );
    }

    /** The lease file: dates, parties, status, documents, signing state, renewal / notice state. */
    public function show(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // the web screen's safety net: bring the lease in step with its e-sign envelope before showing it (a no-op unless the agreement is in flight)
        $lease->reconcileSigning();
        $lease->load(['property.contacts', 'tenants.contact', 'ownerAgent', 'tenantAgent', 'previousLease', 'renewedLease']);

        $user = $request->user();
        $hub = app(LeaseHubService::class);
        $next = $hub->nextStep($lease, $user);

        return response()->json($this->summary($lease) + [
            'lease_type' => $lease->lease_type,
            'rollover' => (bool) $lease->rollover,
            'owner_agent' => $lease->ownerAgent ? ['id' => $lease->ownerAgent->id, 'name' => $lease->ownerAgent->name] : null,
            'tenant_agent' => $lease->tenantAgent ? ['id' => $lease->tenantAgent->id, 'name' => $lease->tenantAgent->name] : null,
            'signing' => [
                'status' => $lease->signing_status,
                'label' => $lease->signingStatusLabel(),
                'signed_at' => $lease->signed_at?->toIso8601String(),
                'locked' => $lease->isLockedForSigning(),
                'signers' => in_array($lease->signing_status, [Lease::SIGNING_NOT_SENT, null], true) ? [] : app(LeaseSigningLauncher::class)->signersSummary($lease),
            ],
            'documents' => $this->documents($lease),
            'renewal' => [
                'previous_lease_id' => $lease->previous_lease_id,
                'renewed_lease_id' => $lease->renewed_lease_id,
                'has_renewal_in_progress' => $lease->hasPendingRenewalDraft(),
                'renewal_signed_awaiting_start' => $lease->isSignedRenewalAwaitingStart(),
                'expiry_notice_sent_at' => $lease->expiry_notice_sent_at?->toIso8601String(),
            ],
            'notice' => [
                'active' => $lease->hasActiveNotice(),
                'given_by' => $lease->notice_given_by,
                'notice_date' => $lease->notice_date?->toDateString(),
                'move_out_date' => $lease->move_out_date?->toDateString(),
                'outcome' => $lease->notice_outcome,
                'note' => $lease->notice_note,
                'notices_to_review' => LeaseNotice::query()->where('lease_id', $lease->id)->where('status', LeaseNotice::STATUS_RECEIVED)->count(),
            ],
            'lifecycle' => $hub->lifecycle($lease),
            // the one next action the web hub offers, as a sentence; the app opens the matching screen (route names are web-only, so none are returned)
            'next_step' => $next ? ['label' => $next['label']] : null,
            'open_items' => $hub->openItemCounts($lease),
            // so the app knows which existing /api/v1/leases/{lease}/renewal/* actions it may offer
            'can' => [
                'renew' => $user->hasPermission('leases.renew'),
                'review_notices' => $user->hasPermission('rental_notices.review'),
            ],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function summary(Lease $l): array
    {
        $daysLeft = $l->end_date && ! $l->is_month_to_month ? (int) now()->startOfDay()->diffInDays($l->end_date->copy()->startOfDay(), false) : null;

        return [
            'id' => $l->id,
            'reference' => 'L-' . $l->id,
            'status' => $l->status,
            'status_label' => $l->displayStatus(),
            'agreement_label' => $l->signingStatusLabel(),
            'property' => $l->property ? ['id' => $l->property->id, 'address' => $l->property->buildDisplayAddress()] : null,
            'start_date' => $l->start_date?->toDateString(),
            'end_date' => $l->end_date?->toDateString(),
            'is_month_to_month' => (bool) $l->is_month_to_month,
            'days_left' => $daysLeft,
            'tenants' => $l->tenants->map(fn ($t) => $t->contact ? $this->party($t->contact) + ['is_primary' => (bool) $t->is_primary] : null)->filter()->values()->all(),
            'owners' => $l->landlordContacts()->map(fn (Contact $c) => $this->party($c))->values()->all(),
            'has_notice' => $l->hasActiveNotice(),
            'move_out_date' => $l->move_out_date?->toDateString(),
        ];
    }

    /** @return array{contact_id:int,name:string,phone:?string,email:?string} */
    private function party(Contact $c): array
    {
        return ['contact_id' => $c->id, 'name' => $c->full_name, 'phone' => $c->phone, 'email' => $c->email];
    }

    /**
     * The lease's filed documents (metadata only - the signed agreement from e-sign and any signed paper copy). The files themselves are not served by this API.
     *
     * @return list<array<string,mixed>>
     */
    private function documents(Lease $lease): array
    {
        $signedId = $lease->signedDocument()?->id;
        $rows = Document::query()->where(function ($q) use ($lease) {
            $q->where(fn ($w) => $w->where('source_type', 'lease')->where('source_id', $lease->id));
            if ($lease->signature_template_id) {
                $q->orWhere(fn ($w) => $w->where('source_type', 'esign')->where('source_id', $lease->signature_template_id));
            }
        })->orderByDesc('id')->get();

        return $rows->map(fn (Document $d) => [
            'id' => $d->id, 'name' => $d->original_name, 'mime' => $d->mime_type, 'size' => $d->size,
            'kind' => $d->id === $signedId ? 'signed_agreement' : 'document',
            'added_at' => $d->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
