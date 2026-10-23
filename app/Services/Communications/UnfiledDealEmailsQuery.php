<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE definition of "an unfiled deal email", so the Unfiled deal emails screen and the count badge on its
 * menu entry can never disagree (AT-454, 23 Oct 2026).
 *
 * Until now the screen built this inline (Dr2\UnfiledEmailsController::index) and the sidebar badge carried
 * its own, much looser query: every email of the agency with no deal link at all. It ignored the four things
 * that make an email belong in the working queue: (1) a party on the email must be a buyer / seller / supplier
 * on a deal (the Unfiled Emails premise, CX-113 Phase A), (2) the viewer's own / branch / all visibility,
 * (3) emails an agent has removed as "not deal correspondence", (4) a link only counts as filed once a person
 * confirmed it. So the badge could read 99+ while the list held a handful.
 *
 * The screen's other states (Filed / All / Removed) and its search, agent picker and paging stay in the
 * controller; they are built from the same two sub-queries exposed here.
 */
class UnfiledDealEmailsQuery
{
    public function __construct(private Dr2DealPartyEmailResolver $dealParties)
    {
    }

    /** A person-confirmed, live link to a deal (the twin). Pass to whereExists / whereNotExists. */
    public static function hasActiveDealLink(): \Closure
    {
        return function ($q) {
            $q->selectRaw('1')->from('communication_links')
                ->whereColumn('communication_links.communication_id', 'communications.id')
                ->where('communication_links.linkable_type', DealV2::class)
                ->whereNull('communication_links.deleted_at')
                ->whereNotNull('communication_links.confirmed_at');
        };
    }

    /** An email an agent removed from the queue and nobody restored. */
    public static function hasActiveDismissal(): \Closure
    {
        return function ($q) {
            $q->selectRaw('1')->from('communication_dr2_dismissals')
                ->whereColumn('communication_dr2_dismissals.communication_id', 'communications.id')
                ->whereNull('communication_dr2_dismissals.restored_at');
        };
    }

    /**
     * Every unfiled email of the agency that is a candidate for filing: not removed, not confirmed to a deal, and
     * with a party matching a deal party. NOT yet limited to what the viewer may see — see forUser().
     */
    public function candidates(int $agencyId): Builder
    {
        return Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->whereNotExists(self::hasActiveDismissal())
            ->whereNotExists(self::hasActiveDealLink())
            ->matchingAnyEmail($this->dealParties->partyEmailsForAgency($agencyId));
    }

    /**
     * What the Unfiled deal emails screen lists by default for this user (state "unfiled", no search, no agent
     * picked, scope = the user's own ceiling) — and so exactly what the badge counts.
     */
    public function forUser(User $user): Builder
    {
        $agencyId = $user->effectiveAgencyId();
        $scope = PermissionService::clampScope(null, PermissionService::dr2UnfiledEmailsScope($user));

        return $this->candidates((int) $agencyId)->visibleTo($user, $scope);
    }

    public function countFor(User $user): int
    {
        if (! $user->effectiveAgencyId()) {
            return 0;
        }

        return (int) $this->forUser($user)->count();
    }
}
