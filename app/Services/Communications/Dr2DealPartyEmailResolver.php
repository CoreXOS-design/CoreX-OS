<?php

namespace App\Services\Communications;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * CX-113 Phase A (Johan, 2026-08-21, correction to the original all-emails premise):
 * "we cannot simply pull all emails into unfiled. the emails needs to match on buyer
 * or seller or supplier that are involved in a dr2 deal." An email belongs on the
 * Unfiled Emails screen ONLY if a party on it (To/From/CC) is ALSO a party on a
 * DR2-twinned deal — as buyer, seller, or supplier (attorney, bond originator, bond
 * attorney, or a COC/work-order supplier). Everything else is contact-level
 * correspondence and stays exactly where it already is (untouched).
 *
 * Reuses the SAME two data sources AttorneyCorrespondenceResolver::activeDealsForFirm()
 * already established and ships against (AT-231): deals.attorney_provider_id /
 * bond_originator_provider_id + deal_step_work_orders.service_provider_id — extended
 * here with bond_attorney_provider_id (the column exists, holds the same shape, AT-231
 * just never needed it since it was 0-populated at the time). Buyer/seller comes from
 * deal_contacts (DR1's own party pivot; deal_v2_contacts is unused — 0 rows on live as
 * of this investigation, so it is deliberately NOT read from).
 *
 * "Supplier" person-level email resolution: a provider firm's own `.email` column is
 * routinely null in real data — the actual emailable person lives one join deeper at
 * agency_service_provider_contacts.email (confirmed against live: e.g. "Van Dyk &
 * Swart Inc" the firm has no email, "Linda" the contact person does). Both are read,
 * firm email as a fallback, contact-person email as the primary source.
 *
 * CX-113 Phase B (Johan, 2026-08-21) — search must also reach "property address,
 * reference number, seller, buyer, attorney" even when none of that text appears in
 * the email itself. deals.property_address/seller_name/buyer_name/attorney_name are
 * denormalised directly on the deal row (no join needed), so a search term is matched
 * against those fields, and only the resolved party emails of the MATCHING deals are
 * returned — narrower than the full agency set, used to widen the WHERE, never to
 * replace the existing subject/body/from_identifier text search. Reference numbers are
 * deliberately NOT indexed separately — they live in free-text subject/body, already
 * covered by that same text search.
 */
class Dr2DealPartyEmailResolver
{
    /**
     * Every distinct, normalised (lower/trim) email address belonging to a buyer,
     * seller, or supplier on any DR2-twinned deal (deals.deal_v2_id not null) in
     * this agency. Cheap at current data volume (tens of rows) — revisit with
     * caching if the deal-party tables grow into the thousands.
     *
     * @return array<int, string>
     */
    public function partyEmailsForAgency(int $agencyId): array
    {
        return $this->resolveEmails($agencyId);
    }

    /**
     * Party emails, narrowed to deals whose property_address, seller_name, buyer_name,
     * or attorney_name contains $term (case-insensitive substring — matches the same
     * "type a few letters" idiom as the rest of CoreX's search boxes).
     *
     * @return array<int, string>
     */
    public function partyEmailsMatchingDealFields(int $agencyId, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return $this->resolveEmails($agencyId, function (Builder $q) use ($term) {
            // Qualified with deals. — agency_service_provider_contacts (joined in some
            // branches below) ALSO has its own attorney_name column; unqualified this is
            // ambiguous the moment both tables are in the same query.
            $q->where(function (Builder $w) use ($term) {
                $w->where('deals.property_address', 'like', "%{$term}%")
                    ->orWhere('deals.seller_name', 'like', "%{$term}%")
                    ->orWhere('deals.buyer_name', 'like', "%{$term}%")
                    ->orWhere('deals.attorney_name', 'like', "%{$term}%");
            });
        });
    }

    /**
     * CX-113 Phase E (Johan, 2026-08-21) — "how do we enhance this so agents don't file
     * to the wrong deal." Every party email on ONE deal, grouped by role, so the inline
     * row picker can answer "does the sender/a recipient match a NAMED role on this
     * candidate deal" — the near-conclusive signal. Same tables/columns as
     * resolveEmails() above, just scoped to a single deal_id and keeping the role
     * label instead of flattening it away.
     *
     * @return array<string, array<int, string>> e.g. ['buyer' => [...], 'seller' =>
     *         [...], 'attorney' => [...], 'bond_originator' => [...], 'bond_attorney'
     *         => [...], 'coc_supplier' => [...]]
     */
    public function partyEmailsByRoleForDeal(int $dealId): array
    {
        $roles = [];

        $contactRows = DB::table('deal_contacts')
            ->join('contacts', 'contacts.id', '=', 'deal_contacts.contact_id')
            ->where('deal_contacts.deal_id', $dealId)
            ->whereNotNull('contacts.email')
            ->get(['deal_contacts.role', 'contacts.email']);
        foreach ($contactRows as $row) {
            $role = in_array($row->role, ['buyer', 'seller'], true) ? $row->role : 'other_party';
            $email = strtolower(trim((string) $row->email));
            if ($email !== '' && str_contains($email, '@')) {
                $roles[$role][] = $email;
            }
        }

        $providerColumns = [
            'attorney_provider_id'        => 'attorney',
            'bond_originator_provider_id' => 'bond_originator',
            'bond_attorney_provider_id'   => 'bond_attorney',
        ];
        $deal = DB::table('deals')->where('id', $dealId)->first(array_keys($providerColumns));
        foreach ($providerColumns as $column => $roleName) {
            $providerId = $deal->$column ?? null;
            if (! $providerId) {
                continue;
            }
            $personEmails = DB::table('agency_service_provider_contacts')
                ->where('service_provider_id', $providerId)->whereNull('deleted_at')->pluck('email');
            $firmEmail = DB::table('agency_service_providers')
                ->where('id', $providerId)->whereNull('deleted_at')->value('email');
            foreach ($personEmails->push($firmEmail) as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '' && str_contains($e, '@')) {
                    $roles[$roleName][] = $e;
                }
            }
        }

        $workOrders = DB::table('deal_step_work_orders')
            ->where('dr1_deal_id', $dealId)->whereNull('deleted_at')
            ->get(['recipient_email', 'service_provider_id']);
        foreach ($workOrders as $wo) {
            if ($wo->service_provider_id) {
                $personEmails = DB::table('agency_service_provider_contacts')
                    ->where('service_provider_id', $wo->service_provider_id)->whereNull('deleted_at')->pluck('email');
                foreach ($personEmails as $e) {
                    $e = strtolower(trim((string) $e));
                    if ($e !== '' && str_contains($e, '@')) {
                        $roles['coc_supplier'][] = $e;
                    }
                }
            }
            $recipient = strtolower(trim((string) ($wo->recipient_email ?? '')));
            if ($recipient !== '' && str_contains($recipient, '@')) {
                $roles['coc_supplier'][] = $recipient;
            }
        }

        foreach ($roles as $role => $emails) {
            $roles[$role] = array_values(array_unique($emails));
        }

        return $roles;
    }

    /**
     * CX-113 Phase E refinement 2 (Johan, 2026-08-21) — "koos from ooba does all our
     * bonds so we get a lot of emails from him... weight each signal by how
     * discriminating it actually is." A party who appears on ONE deal makes an email
     * match near-conclusive; a party on MANY deals (a regular bond originator, the
     * agency's usual conveyancer) makes the same match nearly meaningless for
     * disambiguation. Computed live from real data — never a hardcoded "common
     * suppliers" list, so it stays true as the book changes. Same three sources as
     * partyEmailsByRoleForDeal()/resolveEmails() above, just counting DISTINCT deals
     * per email instead of grouping by role.
     *
     * @return array<string, int> normalised email => distinct deal count
     */
    public function partyDealFrequency(int $agencyId): array
    {
        $pairs = collect();

        DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->join('contacts', 'contacts.id', '=', 'deal_contacts.contact_id')
            ->where('deals.agency_id', $agencyId)->whereNotNull('deals.deal_v2_id')
            ->whereNotNull('contacts.email')
            ->get(['deals.id as deal_id', 'contacts.email'])
            ->each(fn ($r) => $pairs->push([strtolower(trim($r->email)), $r->deal_id]));

        foreach (['attorney_provider_id', 'bond_originator_provider_id', 'bond_attorney_provider_id'] as $column) {
            DB::table('deals')
                ->join('agency_service_provider_contacts', 'agency_service_provider_contacts.service_provider_id', '=', "deals.$column")
                ->where('deals.agency_id', $agencyId)->whereNotNull('deals.deal_v2_id')->whereNotNull("deals.$column")
                ->whereNull('agency_service_provider_contacts.deleted_at')
                ->get(['deals.id as deal_id', 'agency_service_provider_contacts.email'])
                ->each(fn ($r) => $r->email ? $pairs->push([strtolower(trim($r->email)), $r->deal_id]) : null);
            DB::table('deals')
                ->join('agency_service_providers', 'agency_service_providers.id', '=', "deals.$column")
                ->where('deals.agency_id', $agencyId)->whereNotNull('deals.deal_v2_id')->whereNotNull("deals.$column")
                ->whereNull('agency_service_providers.deleted_at')
                ->get(['deals.id as deal_id', 'agency_service_providers.email'])
                ->each(fn ($r) => $r->email ? $pairs->push([strtolower(trim($r->email)), $r->deal_id]) : null);
        }

        DB::table('deal_step_work_orders')
            ->join('deals', 'deals.id', '=', 'deal_step_work_orders.dr1_deal_id')
            ->where('deals.agency_id', $agencyId)->whereNotNull('deals.deal_v2_id')->whereNull('deal_step_work_orders.deleted_at')
            ->get(['deals.id as deal_id', 'deal_step_work_orders.recipient_email'])
            ->each(fn ($r) => $r->recipient_email ? $pairs->push([strtolower(trim($r->recipient_email)), $r->deal_id]) : null);

        return $pairs->filter(fn ($p) => $p[0] !== '' && str_contains($p[0], '@'))
            ->groupBy(fn ($p) => $p[0])
            ->map(fn ($g) => $g->pluck(1)->unique()->count())
            ->all();
    }

    /**
     * @param  (callable(Builder):void)|null  $extraDealFilter  Additional WHERE applied
     *         to the `deals` table in every branch below (narrows which deals' parties
     *         get resolved; omit for "every DR2-twinned deal in the agency").
     * @return array<int, string>
     */
    private function resolveEmails(int $agencyId, ?callable $extraDealFilter = null): array
    {
        $applyDealFilter = function (Builder $q) use ($agencyId, $extraDealFilter) {
            $q->where('deals.agency_id', $agencyId)->whereNotNull('deals.deal_v2_id');
            if ($extraDealFilter) {
                $extraDealFilter($q);
            }
        };

        $buyerSeller = DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->join('contacts', 'contacts.id', '=', 'deal_contacts.contact_id')
            ->where($applyDealFilter)
            ->whereNotNull('contacts.email')
            ->pluck('contacts.email');

        $providerColumns = ['attorney_provider_id', 'bond_originator_provider_id', 'bond_attorney_provider_id'];
        $supplierEmails = collect();

        foreach ($providerColumns as $column) {
            // Primary: the specific person's own address.
            $supplierEmails = $supplierEmails->merge(
                DB::table('deals')
                    ->join('agency_service_provider_contacts', 'agency_service_provider_contacts.service_provider_id', '=', "deals.$column")
                    ->where($applyDealFilter)
                    ->whereNotNull("deals.$column")
                    ->whereNull('agency_service_provider_contacts.deleted_at')
                    ->pluck('agency_service_provider_contacts.email')
            );
            // Fallback: the firm's own inbox, when it has one and no person is captured.
            $supplierEmails = $supplierEmails->merge(
                DB::table('deals')
                    ->join('agency_service_providers', 'agency_service_providers.id', '=', "deals.$column")
                    ->where($applyDealFilter)
                    ->whereNotNull("deals.$column")
                    ->whereNull('agency_service_providers.deleted_at')
                    ->pluck('agency_service_providers.email')
            );
        }

        // COC / work-order suppliers (electrician, entomologist, gas, etc. — the
        // agency-configured service_type list, not a fixed taxonomy). Same two
        // resolution paths: a direct recipient_email on the work order row, or the
        // assigned supplier's contact-person email.
        $workOrderBase = DB::table('deal_step_work_orders')
            ->join('deals', 'deals.id', '=', 'deal_step_work_orders.dr1_deal_id')
            ->where($applyDealFilter)
            ->whereNull('deal_step_work_orders.deleted_at');

        $supplierEmails = $supplierEmails->merge(
            (clone $workOrderBase)->whereNotNull('deal_step_work_orders.recipient_email')
                ->pluck('deal_step_work_orders.recipient_email')
        );
        $supplierEmails = $supplierEmails->merge(
            (clone $workOrderBase)
                ->join('agency_service_provider_contacts', 'agency_service_provider_contacts.service_provider_id', '=', 'deal_step_work_orders.service_provider_id')
                ->whereNotNull('deal_step_work_orders.service_provider_id')
                ->whereNull('agency_service_provider_contacts.deleted_at')
                ->pluck('agency_service_provider_contacts.email')
        );

        return $buyerSeller->merge($supplierEmails)
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter(fn ($e) => $e !== '' && str_contains($e, '@'))
            ->unique()
            ->values()
            ->all();
    }
}
