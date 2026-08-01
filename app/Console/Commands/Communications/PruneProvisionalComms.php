<?php

namespace App\Console\Commands\Communications;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\CommunicationSendStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resolve stale UNRECONCILED provisional outbound communications (AT-59 / AT-323).
 *
 * A provisional row is created when an agent clicks WhatsApp/Email; ingestion
 * normally reconciles it (promotes it to a confirmed archive record) within
 * minutes/hours once the real Sent message is captured. If it is still
 * unreconciled past the agency's prune age, the send almost certainly never
 * actually happened (e.g. the agent was not signed into WhatsApp Web — the
 * AT-323 defect) or the message was edited before sending.
 *
 * AT-323 part (B): instead of SILENTLY SOFT-PURGING such a row (which showed a
 * false "sent" for days then made it vanish), we now FLAG it not_delivered —
 * the honest terminal state. It is excluded from the tile counts / last-contacted
 * (same as a purge) but STAYS ON RECORD as a "could not send" audit fact and
 * carries the existing resend affordance. This is the backstop for the post-send
 * confirmation modal (covers an agent who closes the tab without answering it).
 * Reuses CommunicationSendStatusService::markNotDelivered — nothing is deleted.
 */
class PruneProvisionalComms extends Command
{
    protected $signature = 'communications:prune-provisional';

    protected $description = 'Flag unreconciled provisional outbound communications past their prune age as not_delivered (AT-323).';

    public function handle(CommunicationSendStatusService $sendStatus): int
    {
        $pruneHoursByAgency = [];
        $flagged = 0;

        Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNotNull('provisional_at')
            ->whereNull('purged_at')
            // Skip rows already resolved to not_delivered so the sweep is idempotent.
            ->where(function ($q) {
                $q->whereNull('send_status')
                  ->orWhere('send_status', '!=', Communication::SEND_STATUS_NOT_DELIVERED);
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pruneHoursByAgency, &$flagged, $sendStatus) {
                foreach ($rows as $comm) {
                    $agencyId = (int) $comm->agency_id;

                    if (! array_key_exists($agencyId, $pruneHoursByAgency)) {
                        $agency = Agency::query()->withoutGlobalScope(AgencyScope::class)->find($agencyId);
                        $pruneHoursByAgency[$agencyId] = $agency
                            ? $agency->provisionalPruneHours()
                            : max(1, (int) config('communications.provisional_prune_hours', 168));
                    }

                    $cutoff = now()->subHours($pruneHoursByAgency[$agencyId]);

                    if (! $comm->provisional_at || $comm->provisional_at->gt($cutoff)) {
                        continue; // still within the reconcile window
                    }

                    // Resolve the contact this provisional was linked to (comms-tile quick-send +
                    // outreach both link a Contact). Distribution rows (Property/DealV2 only, no
                    // Contact) get a direct flag with no last-contacted recompute.
                    $contact = $this->resolveContact($comm, $agencyId);

                    if ($contact) {
                        $sendStatus->markNotDelivered(
                            $comm, $contact, null,
                            'Unconfirmed send — never reconciled; auto-flagged not delivered (AT-323).'
                        );
                    } else {
                        DB::transaction(function () use ($comm) {
                            $comm->forceFill([
                                'send_status'                => Communication::SEND_STATUS_NOT_DELIVERED,
                                'send_status_set_by_user_id' => null,
                                'send_status_set_at'         => now(),
                            ])->save();
                        });
                    }

                    $flagged++;
                }
            });

        $this->info("Flagged {$flagged} unreconciled provisional communication(s) as not_delivered.");

        return self::SUCCESS;
    }

    /** The Contact this provisional communication was linked to, if any. */
    private function resolveContact(Communication $comm, int $agencyId): ?Contact
    {
        $contactId = CommunicationLink::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('communication_id', $comm->id)
            ->where('linkable_type', Contact::class)
            ->value('linkable_id');

        return $contactId
            ? Contact::withoutGlobalScope(AgencyScope::class)->find($contactId)
            : null;
    }
}
