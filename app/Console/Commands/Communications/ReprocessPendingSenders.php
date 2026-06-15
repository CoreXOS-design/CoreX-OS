<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationPending;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\CommunicationStorageService;
use App\Services\Communications\ContactIdentifierResolver;
use App\Services\Communications\EmailAddressExtractor;
use App\Services\Communications\PendingAttachmentService;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\Message;

/**
 * Backfill from_identifier on email pending rows that were captured before the
 * AT-40 sender-extraction fix (when getFrom() yielded nothing and every row
 * landed with from_identifier = NULL). Re-parses each row's stored raw .eml,
 * recomputes from_identifier + participant_identifiers via the same shared
 * extractor the live poller now uses, then re-runs the known-contact gate so a
 * sender who IS a contact is matched + archived retroactively (the nightly
 * pruner can't, because it keys on from_identifier which was NULL).
 *
 * Idempotent and safe to re-run. Only touches rows whose from_identifier is
 * still NULL; never deletes content.
 */
class ReprocessPendingSenders extends Command
{
    protected $signature = 'communications:reprocess-pending-senders
                            {--agency= : Limit to one agency_id}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill from_identifier on email pending rows captured before the AT-40 fix; retroactively attach now-matchable senders.';

    public function handle(
        CommunicationStorageService $storage,
        ContactIdentifierResolver $resolver,
        PendingAttachmentService $attachments,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $agency = $this->option('agency');

        $scanned = 0;
        $backfilled = 0;
        $attached = 0;
        $noRaw = 0;
        $stillNull = 0;

        CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNull('purged_at')
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->whereNull('from_identifier')
            ->when($agency !== null, fn ($q) => $q->where('agency_id', (int) $agency))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (
                $storage, $resolver, $attachments, $dryRun,
                &$scanned, &$backfilled, &$attached, &$noRaw, &$stillNull
            ) {
                foreach ($rows as $pending) {
                    $scanned++;

                    $raw = $pending->raw_path ? $storage->get($pending->raw_path) : null;
                    if (! $raw) {
                        $noRaw++;
                        continue;
                    }

                    try {
                        $message = Message::fromString($raw);
                        $from = EmailAddressExtractor::first($message->getFrom());
                        $participants = array_values(array_unique(array_filter(array_merge(
                            [$from],
                            EmailAddressExtractor::normalize($message->getTo()),
                            EmailAddressExtractor::normalize($message->getCc()),
                        ))));
                    } catch (\Throwable $e) {
                        $this->warn("pending {$pending->id}: parse failed — {$e->getMessage()}");
                        continue;
                    }

                    if (! $from) {
                        $stillNull++;
                        continue;
                    }

                    if ($dryRun) {
                        $contact = $resolver->resolve($from, (int) $pending->agency_id);
                        $this->line("would set pending {$pending->id} from_identifier={$from}" . ($contact ? " → MATCH contact {$contact->id} (attach)" : ' → no contact (stays pending)'));
                        $backfilled++;
                        if ($contact) {
                            $attached++;
                        }
                        continue;
                    }

                    $pending->update([
                        'from_identifier'         => $from,
                        'participant_identifiers' => $participants,
                    ]);
                    $backfilled++;

                    $contact = $resolver->resolve($from, (int) $pending->agency_id);
                    if ($contact) {
                        $attachments->attach($pending, $contact);
                        $attached++;
                    }
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Scanned {$scanned} null-sender pending row(s): {$backfilled} backfilled, {$attached} retroactively attached, {$stillNull} still unresolvable, {$noRaw} missing raw.");

        return self::SUCCESS;
    }
}
