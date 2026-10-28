<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSetting;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\Scopes\AgencyScope;
use App\Services\AttorneyPortal\AttorneyPortalScope;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Attorney portal overview (Deals menu -> Attorney Portal; .ai/specs/attorney-portal.md 16): is the portal on, how it works, and every
 * attorney contact with their access - who, firm, deals they can see, last sign-in, link status - with Send / Resend / Revoke.
 * Send and Revoke post to the existing AttorneyPortalAccessController routes (deals_v2.invite_attorney); this page only lists.
 * Agency-scoped by hand (the page reads across AgencyScope on purpose, filtering agency_id itself).
 */
class AttorneyPortalOverviewController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = ['name', 'firm', 'deals', 'login', 'status'];

    public function index(Request $request, AttorneyPortalScope $scope)
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $settings = AttorneyPortalSetting::forAgency($agencyId);

        $q      = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['live', 'expired', 'revoked', 'none'], true) ? (string) $request->query('status') : 'all';
        $sort   = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir    = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $rows = $this->rows($agencyId, $scope, $settings->isEnabled());
        $total = $rows->count();
        $liveCount = $rows->where('status', 'live')->count();

        $rows = $rows->filter(function (array $r) use ($q, $status) {
            if ($status !== 'all' && $r['status'] !== $status) {
                return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower($r['name'] . ' ' . $r['firm'] . ' ' . $r['email']);
                foreach (preg_split('/\s+/', mb_strtolower($q)) as $term) {
                    if ($term !== '' && ! str_contains($hay, $term)) {
                        return false;
                    }
                }
            }

            return true;
        });

        $key = match ($sort) {
            'firm'   => fn ($r) => mb_strtolower($r['firm']),
            'deals'  => fn ($r) => $r['deals'],
            'login'  => fn ($r) => $r['last_login']?->timestamp ?? 0,
            'status' => fn ($r) => $r['status_rank'],
            default  => fn ($r) => mb_strtolower($r['name']),
        };
        $rows = $rows->sortBy($key, SORT_REGULAR, $dir === 'desc')->values();

        $page  = max(1, (int) $request->query('page', 1));
        $slice = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page,
            ['path' => route('deals-v2.attorney-portal.index'), 'query' => $request->query()]);

        $user = $request->user();

        return view('dr2.attorney-portal-overview', [
            'settings'    => $settings,
            'enabled'     => $settings->isEnabled(),
            'rows'        => $slice,
            'hasAny'      => $total > 0,
            'q'           => $q,
            'status'      => $status,
            'sort'        => $sort,
            'dir'         => $dir,
            'canInvite'   => (bool) $user->hasPermission('deals_v2.invite_attorney'),
            'canSettings' => (bool) $user->hasPermission('attorney_portal.manage_settings'),
            'live'        => $liveCount,
        ]);
    }

    /**
     * One row per attorney CONTACT person: those with an access (any state) plus the contacts of attorney firms not yet invited.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function rows(int $agencyId, AttorneyPortalScope $scope, bool $enabled): Collection
    {
        $accesses = AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->get()->keyBy('service_provider_contact_id');

        $attorneyFirmIds = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->anyAttorney()->pluck('id');

        $contacts = AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereIn('service_provider_id', $attorneyFirmIds)->orWhereIn('id', $accesses->keys()))
            ->get();
        $firms = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->whereIn('id', $contacts->pluck('service_provider_id'))->pluck('name', 'id');

        return $contacts->map(function (AgencyServiceProviderContact $c) use ($accesses, $firms, $scope, $agencyId, $enabled) {
            $access = $accesses->get($c->id);
            // an unsaved access carries the same two ids the scope needs, so "deals they would see" works before any invite
            $probe  = $access ?: new AttorneyPortalAccess(['agency_id' => $agencyId, 'service_provider_id' => $c->service_provider_id, 'service_provider_contact_id' => $c->id]);
            $status = $access ? $access->status() : 'none';

            return [
                'contact_id' => (int) $c->id,
                'access_id'  => $access?->id,
                'name'       => trim((string) ($c->attorney_name ?: $c->contact_person)) ?: 'Unnamed contact',
                'firm'       => (string) ($firms[$c->service_provider_id] ?? ''),
                'email'      => (string) ($c->email ?? ''),
                'deals'      => $scope->visibleDeals($probe)->count(),
                'last_login' => $access?->last_login_at,
                'invited_at' => $access?->invited_at,
                'expires_at' => $access?->expires_at,
                'status'     => $status,
                'status_rank' => ['live' => 0, 'expired' => 1, 'revoked' => 2, 'none' => 3][$status],
                'can_send'   => $enabled && (bool) $c->is_active && trim((string) $c->email) !== '',
                'why_not'    => ! $enabled ? 'Switch the attorney portal on first.' : (! $c->is_active ? 'This contact is not active.' : (trim((string) $c->email) === '' ? 'This contact has no email address yet.' : null)),
            ];
        })->values();
    }
}
