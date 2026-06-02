<?php

namespace App\Console\Commands;

use App\Jobs\DeliverAgencyWebhook;
use App\Models\AgencyWebhookDelivery;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * Re-dispatches agency-website webhook deliveries that are due for a retry.
 *
 * DeliverAgencyWebhook records next_retry_at on a failed attempt (instead of
 * relying on queue-native backoff). This command — scheduled every minute —
 * picks up due rows, claims them (nulls next_retry_at) and re-queues the job.
 *
 * Spec: .ai/specs/agency-public-api.md §6.2
 */
class RetryDueWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:retry-due {--limit=200}';
    protected $description = 'Re-dispatch agency website webhook deliveries that are due for retry';

    public function handle(): int
    {
        $due = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $delivery) {
            // Claim it so the next sweep doesn't double-dispatch before the job runs.
            $delivery->forceFill(['next_retry_at' => null])->save();
            DeliverAgencyWebhook::dispatch($delivery->id);
        }

        $this->info("Re-dispatched {$due->count()} due webhook deliver(ies).");

        return self::SUCCESS;
    }
}
