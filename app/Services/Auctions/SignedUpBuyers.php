<?php

namespace App\Services\Auctions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Signed up" (.ai/specs/auctions.md §19.1): the buyers who signed up on, or logged into, one agency's public auction page. One definition,
 * shared by the Bidders -> Signed up tab and the start-of-auction email, so the two can never disagree about who "signed up" means.
 */
class SignedUpBuyers
{
    public const EVENTS = ['auction_signup', 'auction_login'];

    /** One row per buyer: client_user_id and the first time they appeared on this agency's auction page (signed_up_at). */
    public static function firstSeen(int $agencyId): Builder
    {
        return DB::table('client_access_logs')->where('agency_id', $agencyId)->whereIn('event', self::EVENTS)
            ->whereNull('deleted_at')->groupBy('client_user_id')->selectRaw('client_user_id, MIN(created_at) as signed_up_at');
    }

    /** @return \Illuminate\Support\Collection<int, object{email: string, name: ?string}> deliverable addresses, one per person */
    public function recipients(int $agencyId)
    {
        $fake = '@' . strtolower((string) config('clientauth.fake_email_domain', 'corexclient.co.za'));

        return DB::table('client_users')->joinSub(self::firstSeen($agencyId), 'su', 'su.client_user_id', '=', 'client_users.id')
            ->whereNull('client_users.deleted_at')
            ->selectRaw('client_users.id, client_users.email, ('
                . "SELECT TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) FROM contacts c WHERE c.client_user_id = client_users.id AND c.agency_id = ? AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1"
                . ') as name', [$agencyId])
            ->get()
            ->filter(fn ($r) => filter_var($r->email, FILTER_VALIDATE_EMAIL) && ! str_ends_with(strtolower($r->email), $fake))
            ->unique(fn ($r) => strtolower($r->email))
            ->values();
    }
}
