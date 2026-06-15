<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\CommunicationPending;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\ContactIdentifierResolver;
use App\Services\Communications\PendingAttachmentService;
use Illuminate\Console\Command;

/**
 * Nightly inbound-grace processing (AT-32, spec §7.5):
 *   1. Retroactive attach — any live pending item whose sender now resolves to
 *      a loaded contact is moved into the permanent archive (+ a deterministic
 *      contact link), then marked attached.
 *   2. Prune — pending items still unmatched past their grace window are
 *      soft-purged (POPIA data-minimisation). No hard deletes.
 */
class PruneCommunicationPending extends Command
{
    protected $signature = 'communications:prune-pending';

    protected $description = 'Attach matured inbound pending items to the archive; prune expired unmatched ones.';

    public function handle(ContactIdentifierResolver $resolver, PendingAttachmentService $attachments): int
    {
        $attached = 0;
        $pruned = 0;

        CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNull('purged_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($resolver, $attachments, &$attached, &$pruned) {
                foreach ($rows as $pending) {
                    $contact = $pending->from_identifier
                        ? $resolver->resolve($pending->from_identifier, (int) $pending->agency_id)
                        : null;

                    if ($contact) {
                        $attachments->attach($pending, $contact);
                        $attached++;
                    } elseif ($pending->expires_at && $pending->expires_at->lte(now())) {
                        $pending->update([
                            'purged_at'     => now(),
                            'purged_reason' => 'grace_expired_unmatched',
                        ]);
                        $pending->delete(); // soft
                        $pruned++;
                    }
                }
            });

        $this->info("Attached {$attached} pending item(s) to the archive; pruned {$pruned} expired unmatched.");

        return self::SUCCESS;
    }
}
