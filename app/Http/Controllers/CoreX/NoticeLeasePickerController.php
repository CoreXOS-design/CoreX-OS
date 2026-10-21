<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-495 - the lease picker behind the Notices tabs' "Record a received notice", "Send notice" and "Issue breach notice" buttons.
 * GET /api/v1/rentals/notices/leases?purpose=received|sent|breach&q=...&template=<id>
 *
 * A notice is always about one lease, so each button first asks WHICH lease (search by tenant name, property address or lease number), then
 * opens the existing screen for that lease: the lease screen's received-notice dialog, the Send notice form, or the breach notice form.
 * The leases offered are exactly the ones this user may see (Lease::visibleTo: own / branch / agency per Role Manager, on top of the hard
 * agency boundary) - another agency's lease, or a colleague's under an "own" scope, is never returned. Registered under /api/v1 so it is in
 * the Admin -> API catalogue (non-negotiable #7).
 */
class NoticeLeasePickerController extends Controller
{
    /** purpose => the permission that purpose needs (the screens the picker leads to also need leases.view) */
    private const PURPOSE_PERMISSION = [
        'received' => 'rental_notices.review',
        'sent' => 'rental_notices.create',
        'breach' => 'rental_breaches.manage',
    ];

    private const LIMIT = 15;

    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $purpose = (string) $request->query('purpose', '');
        abort_unless(isset(self::PURPOSE_PERMISSION[$purpose]), 422, 'Unknown purpose.');
        abort_unless($user->hasPermission(self::PURPOSE_PERMISSION[$purpose]) && $user->hasPermission('leases.view'), 403, 'You don\'t have access to this resource.');

        $term = trim((string) $request->query('q', ''));
        $query = Lease::query()->visibleTo($user)->with(['property', 'tenants.contact']);

        if ($term !== '') {
            $tokens = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function ($w) use ($term, $tokens) {
                $w->whereHas('property', fn ($p) => $p->searchAddress($term))
                    // every word typed must be part of a tenant's first or last name ("tina tenant")
                    ->orWhereHas('tenants.contact', function ($c) use ($tokens) {
                        foreach ($tokens as $t) {
                            $c->where(fn ($cc) => $cc->where('first_name', 'like', "%{$t}%")->orWhere('last_name', 'like', "%{$t}%"));
                        }
                    });
                // a lease number: "12", "#12" or "lease 12"
                if (preg_match('/^(?:lease\s*)?#?\s*(\d+)$/i', $term, $m)) {
                    $w->orWhere('leases.id', (int) $m[1]);
                }
            });
        }

        $template = (int) $request->query('template');

        $leases = $query->orderByDesc('leases.id')->limit(self::LIMIT)->get()
            ->map(fn (Lease $l) => $this->row($l, $purpose, $template))->values();

        return response()->json(['leases' => $leases]);
    }

    /** One lease as the picker shows it, with the screens it may lead to (empty + a note when this lease cannot take that notice). */
    private function row(Lease $lease, string $purpose, int $template): array
    {
        $note = null;
        $actions = [];

        if ($purpose === 'received') {
            if ($lease->status !== Lease::STATUS_ACTIVE) {
                $note = 'Only a running lease can take a notice.';
            } elseif ($lease->hasActiveNotice()) {
                $note = 'This lease already has an accepted notice - reverse it first.';
            } else {
                $actions = [
                    ['label' => 'Tenant gave notice', 'url' => route('corex.leases.show', [$lease, 'action' => 'tenant-notice'])],
                    ['label' => 'Landlord not renewing', 'url' => route('corex.leases.show', [$lease, 'action' => 'landlord-notice'])],
                ];
                $waiting = LeaseNotice::withoutGlobalScopes()->whereNull('deleted_at')->where('lease_id', $lease->id)->where('status', LeaseNotice::STATUS_RECEIVED)->exists();
                if ($waiting) {
                    $note = 'A notice is already waiting for review on this lease.';
                }
            }
        } elseif ($purpose === 'sent') {
            $actions = [['label' => 'Send notice', 'url' => route('corex.leases.notices.create', array_filter(['lease' => $lease->id, 'template' => $template ?: null]))]];
        } else {
            if ($lease->status !== Lease::STATUS_ACTIVE) {
                $note = 'Only a running lease can take a breach notice.';
            } else {
                $actions = [['label' => 'Issue breach notice', 'url' => route('corex.leases.breaches.create', array_filter(['lease' => $lease->id, 'template' => $template ?: null]))]];
            }
        }

        return [
            'id' => $lease->id,
            'number' => 'Lease #' . $lease->id,
            'property' => $lease->property?->buildDisplayAddress() ?: ('Lease #' . $lease->id),
            'tenant' => $lease->tenantNames(),
            'status' => ucfirst((string) $lease->status),
            'note' => $note,
            'actions' => $actions,
        ];
    }
}
