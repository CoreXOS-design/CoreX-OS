<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\RentalApplication\RentalApplicationApproved;
use App\Models\ContactMatch;
use App\Services\RentalApplications\RentalApplicationAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-482 (Johan, 21 Oct 2026) - an approved applicant is only ever shown rentals up to the amount they were approved for.
 * When an application is approved, the upper rent (`price_max`) of EVERY active rental Core Match of that contact (any of
 * the legacy rental listing types too) is set to the approved amount, rounded down to the rand.
 *
 * Why this and not only the existing cap: ContactMatch::enforceRentalApprovedAmountCap() lowers a price_max when a match is
 * next SAVED, so on approval nothing changed until somebody happened to edit the match - and it never filled an empty or
 * lower ceiling. This runs at the moment of approval; the cap stays as the backstop so a later edit can never push it above
 * the approved amount.
 *
 * Declined / withdrawn / reopened: deliberately not listened to - matches are left exactly as they are.
 * An override re-approval fires the same event, so a changed approved amount re-sets the ceiling.
 *
 * Agency-scoped (the match must belong to the application's own agency) and NOT branch-hidden: an approver in another
 * branch must still move the applicant's matches, so the global scopes are dropped and the agency filter is explicit.
 * Failure-isolated: a problem here must never undo the approval (it is already committed). Registered explicitly in
 * AppServiceProvider (AT-261: discovery is off).
 */
class SetRentalCoreMatchCeilingOnApproval
{
    public function handle(RentalApplicationApproved $event): void
    {
        $application = $event->application;

        try {
            $approved = (float) $application->approved_rental_amount;
            if ($approved <= 0 || ! $application->contact_id || ! $application->agency_id) {
                return;
            }
            $ceiling = (int) floor($approved);

            $matches = ContactMatch::withoutGlobalScopes()
                ->where('agency_id', $application->agency_id)
                ->where('contact_id', $application->contact_id)
                ->where('status', ContactMatch::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(listing_type)'), ContactMatch::RENTAL_LISTING_TYPES)
                ->get();

            $changed = [];
            foreach ($matches as $match) {
                $old = $match->price_max;
                if ((int) $old === $ceiling) {
                    continue;
                }
                $match->price_max = $ceiling;
                $match->save();
                $changed[] = ['match_id' => $match->id, 'price_max' => ['from' => $old, 'to' => $match->price_max]];
            }

            if ($changed === []) {
                return;
            }

            // There is no change history on a Core Match itself; the change is written to the application's audit trail
            // (who approved, what the ceiling was and became, per match) so it can always be explained.
            app(RentalApplicationAuditService::class)->log(
                $application,
                eventCategory: 'core_match',
                eventType: 'rental_ceiling_set',
                oldValues: ['price_max' => collect($changed)->mapWithKeys(fn ($c) => [$c['match_id'] => $c['price_max']['from']])->all()],
                newValues: ['price_max' => $ceiling],
                metadata: ['matches' => $changed],
                humanSummary: 'Approved for R ' . number_format($ceiling, 0, '.', ' ') . ': the upper rent on '
                    . count($changed) . ' Core Match' . (count($changed) === 1 ? '' : 'es') . ' was set to that amount.',
            );
        } catch (\Throwable $e) {
            Log::warning('SetRentalCoreMatchCeilingOnApproval failed', [
                'rental_application_id' => $application->id ?? null,
                'contact_id' => $application->contact_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
