<?php

namespace App\Services\RentalApplications;

use App\Mail\RentalApplicationDecisionMail;
use App\Mail\RentalApplicationReturnedMail;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\LeaseAgentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AT-392, Johan 2026-09-07 — notifies the sending agent when their tenant's
 * application comes back. Deliberately its own file, separate from
 * RentalApplicationMailer (the applicant-facing invite), and from anything
 * on the agent-side lane — new, additive, nothing shared touched.
 */
class RentalApplicationNotifier
{
    /**
     * Best-effort — a notification failure must never break the applicant's
     * submit action itself (their data is already saved either way), same
     * "never let mail break the real action" posture as the invite mailer.
     */
    public function notifyAgentOfReturn(RentalApplication $application): bool
    {
        $agentEmail = $application->createdBy?->email;
        if (! $agentEmail) {
            return false;
        }

        try {
            Mail::to($agentEmail)->send(new RentalApplicationReturnedMail($application));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application returned-notification mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * AT-392 authoriser flow — notifies the agent of an authoriser decision
     * (approved / declined / more info requested). Same best-effort posture
     * as notifyAgentOfReturn() above.
     */
    public function notifyAgentOfDecision(RentalApplication $application, string $decision, ?string $reason = null, bool $isOverride = false): bool
    {
        $agentEmail = $application->createdBy?->email;
        if (! $agentEmail) {
            return false;
        }

        try {
            Mail::to($agentEmail)->send(new RentalApplicationDecisionMail($application, $decision, $reason, $isOverride));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application decision-notification mail failed', [
                'rental_application_id' => $application->id,
                'decision' => $decision,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
    /**
     * Rentals front-half decision D3 (8 Oct 2026): when a tenant submits, the agents who will deal with it are told IN THE APP,
     * not only the agent who created the application (who is still emailed by notifyAgentOfReturn() above). Recipients: the
     * creating agent, plus the people who must hear about anything on this property - the lease's owner-side and tenant-side
     * agents when it is let, else the property's agent, else the branch manager/office admin/admin (LeaseAgentService::
     * faultRecipients(), the one rule for "who is responsible"). Agency setting `notify_agents_on_application_returned`
     * (default on); each person's own notification preferences still decide whether they see it. In-app only (no second mail).
     * Best-effort like every notification here; returns the number of people it reached.
     */
    public function notifyAgentsInApp(RentalApplication $application): int
    {
        if (! RentalApplicationQualifyingSetting::notifyAgentsOnApplicationReturnedFor($application->agency_id)) {
            return 0;
        }

        try {
            $people = collect();
            if ($application->created_by_user_id && ($creator = User::withoutGlobalScopes()->where('agency_id', $application->agency_id)->where('is_active', true)->find($application->created_by_user_id))) {
                $people->push($creator);
            }
            $property = $application->property()->withoutGlobalScopes()->first();
            if ($property) {
                $lease = $property->activeLease()->withoutGlobalScopes()->first();
                $people = $people->merge(app(LeaseAgentService::class)->faultRecipients($lease, $property, (int) $application->agency_id));
            }

            $reached = 0;
            $name = $application->full_name ?: ($application->contact?->full_name ?: 'An applicant');
            foreach ($people->unique('id') as $user) {
                $fired = app(NotificationDispatcher::class)->fire($user, 'rental_application.returned', $application, [
                    'title' => 'Rental application submitted - ' . $name,
                    'body' => $name . ' submitted their rental application' . ($property ? ' for ' . $property->buildDisplayAddress() : '') . '.',
                    'action_url' => route('corex.rental-applications.review', $application),
                    'severity' => 'info',
                    'threshold_hit_at' => $application->submitted_at ?? now(),
                ]);
                $reached += $fired ? 1 : 0;
            }

            app(RentalApplicationAuditService::class)->log(
                $application,
                eventCategory: 'notification',
                eventType: 'agents_notified_on_return',
                newValues: ['recipient_user_ids' => $people->pluck('id')->unique()->values()->all(), 'reached' => $reached],
                humanSummary: 'In-app note sent to ' . $people->unique('id')->count() . ' agent(s) when the applicant submitted.',
            );

            return $reached;
        } catch (\Throwable $e) {
            Log::warning('Rental application returned in-app notification failed', ['rental_application_id' => $application->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Rentals front-half decision D4 (8 Oct 2026): the authoriser(s) are told when an application is handed to them -
     * instead of the queue being the only place it shows. Authorisers are the agency's configured RO reviewers; with none
     * configured, the CO tier. In-app and email through the notification gateway, so each person's own preferences, the
     * open-hours schedule and the QA1 mail guard all apply. Agency setting `notify_authoriser_on_hand_over` (default on).
     * Best-effort; returns the number of people it reached. The hand-off itself never depends on it.
     */
    public function notifyAuthorisersOfHandOver(RentalApplication $application, ?User $handedBy = null): int
    {
        if (! RentalApplicationQualifyingSetting::notifyAuthoriserOnHandOverFor($application->agency_id)) {
            return 0;
        }

        try {
            $agency = \App\Models\Agency::withoutGlobalScopes()->find($application->agency_id);
            $ids = array_values(array_filter((array) ($agency?->rental_application_ro_user_ids ?? [])));
            if ($ids === []) {
                $ids = array_values(array_filter((array) ($agency?->rental_application_co_user_ids ?? [])));
            }
            $authorisers = $ids === [] ? collect() : User::withoutGlobalScopes()
                ->where('agency_id', $application->agency_id)->whereNull('deleted_at')->where('is_active', true)->whereIn('id', $ids)->get();

            $name = $application->full_name ?: ($application->contact?->full_name ?: 'An applicant');
            $reached = 0;
            foreach ($authorisers as $user) {
                if ($handedBy && $user->id === $handedBy->id) {
                    continue; // you do not need telling about what you just did
                }
                $fired = app(NotificationDispatcher::class)->fire($user, 'rental_application.handed_over', $application, [
                    'title' => 'Rental application to authorise - ' . $name,
                    'body' => ($handedBy?->name ?? 'An agent') . ' handed ' . $name . '\'s rental application to you for a decision.',
                    'action_url' => route('corex.rental-applications.authorisation.show', $application),
                    'severity' => 'info',
                    'threshold_hit_at' => $application->submitted_for_approval_at ?? now(),
                ]);
                $reached += $fired ? 1 : 0;
            }

            app(RentalApplicationAuditService::class)->log(
                $application,
                eventCategory: 'notification',
                eventType: 'authorisers_notified_on_hand_over',
                user: $handedBy,
                newValues: ['recipient_user_ids' => $authorisers->pluck('id')->all(), 'reached' => $reached],
                humanSummary: 'Authoriser(s) told of the hand-over (' . $authorisers->count() . ' on the list, ' . $reached . ' reached).',
            );

            return $reached;
        } catch (\Throwable $e) {
            Log::warning('Rental application hand-over notification failed', ['rental_application_id' => $application->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
