<?php

declare(strict_types=1);

namespace App\Jobs\Rentals;

use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.10.3 — tell the tenant(s) that work at their home has been reported complete and
 * ask them to check it, with a one-click response link. Queued so the crew's phone never waits on SMTP; dispatched by
 * RentalCompletionService::openRound() with a plain id (domain events carry readonly state and cannot be queued).
 *
 * Sent AS the property's responsible agent through the agency mailbox path (RentalMailDispatcher), falling back to the
 * shared mailer; never a plain Mail::to(). Best-effort: the outcome is recorded on the round (`sent` / `failed`) and a
 * failure never breaks the crew's completion. One live link per round; the same link goes to every tenant on the lease.
 */
class SendTenantCompletionCheckMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $roundId)
    {
    }

    public function handle(RentalMailDispatcher $dispatcher, RentalCompletionService $completion, RentalSecureAccessTokenService $tokens): void
    {
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->find($this->roundId);
        if (! $round || $round->outcome !== RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT || $round->tenant_notify_status === RentalWorkCompletionRound::NOTIFY_SENT) {
            return;
        }

        $workOrder = RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($round->rental_work_order_id);
        if (! $workOrder || $workOrder->trashed() || $workOrder->status === RentalWorkOrder::STATUS_CANCELLED) {
            return;
        }

        $contacts = $completion->tenantContacts($workOrder)->filter(fn ($c) => $completion->usableEmail($c) !== null)->values();
        if ($contacts->isEmpty()) {
            $round->forceFill(['tenant_notify_status' => RentalWorkCompletionRound::NOTIFY_NO_EMAIL])->save();

            return;
        }

        $issued = $tokens->issueForCompletionRound($round);
        $url = route('rentals.completion.show', $issued['raw_token']);
        $agent = $completion->agentFor($workOrder);

        $sent = 0;
        foreach ($contacts as $contact) {
            try {
                $dispatcher->send($completion->usableEmail($contact), new RentalTenantCompletionCheckMail(
                    $round, $workOrder, (string) $contact->first_name, $url, $agent, $contact,
                ));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Tenant completion-check email failed', ['round_id' => $round->id, 'error' => $e->getMessage()]);
            }
        }

        $round->forceFill([
            'tenant_notify_status' => $sent > 0 ? RentalWorkCompletionRound::NOTIFY_SENT : RentalWorkCompletionRound::NOTIFY_FAILED,
            'tenant_notified_at' => $sent > 0 ? now() : null,
        ])->save();

        $workOrder->updates()->create([
            'agency_id' => $workOrder->agency_id,
            'update_type' => 'completion_check_sent',
            'note' => $sent > 0
                ? "Round {$round->round_no}: tenant emailed to check the work ({$sent} of {$contacts->count()} address" . ($contacts->count() === 1 ? '' : 'es') . ')'
                : "Round {$round->round_no}: the tenant could not be emailed — record their answer by phone",
        ]);
    }
}
