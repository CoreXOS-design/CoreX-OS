<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\ContactIdentifierResolver;
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

    public function handle(ContactIdentifierResolver $resolver): int
    {
        $attached = 0;
        $pruned = 0;

        CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNull('purged_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($resolver, &$attached, &$pruned) {
                foreach ($rows as $pending) {
                    $contact = $pending->from_identifier
                        ? $resolver->resolve($pending->from_identifier, (int) $pending->agency_id)
                        : null;

                    if ($contact) {
                        $this->attach($pending, $contact);
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

    /**
     * Move a matured pending item into the permanent archive (dedup on
     * agency_id + external_id) and record the deterministic contact link.
     */
    private function attach(CommunicationPending $pending, Contact $contact): void
    {
        $exists = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $pending->agency_id)
            ->where('external_id', $pending->external_id)
            ->exists();

        if (! $exists) {
            $comm = Communication::create([
                'agency_id'              => $pending->agency_id,
                'channel'                => $pending->channel,
                'direction'              => $pending->direction,
                'external_id'            => $pending->external_id,
                'thread_key'             => $pending->thread_key,
                'from_identifier'        => $pending->from_identifier,
                'participant_identifiers' => $pending->participant_identifiers,
                'occurred_at'            => $pending->occurred_at,
                'captured_at'            => $pending->captured_at,
                'subject'                => $pending->subject,
                'body_text'              => $pending->body_text,
                'body_preview'           => $pending->body_preview,
                'raw_path'               => $pending->raw_path,
                'has_attachments'        => $pending->has_attachments,
                'content_hash'           => $pending->content_hash,
                'source_ref'             => $pending->source_ref,
            ]);

            CommunicationLink::create([
                'agency_id'        => $pending->agency_id,
                'communication_id' => $comm->id,
                'linkable_type'    => Contact::class,
                'linkable_id'      => $contact->id,
                'link_method'      => CommunicationLink::METHOD_DETERMINISTIC,
                'confidence'       => 100,
                'confirmed_at'     => now(),
            ]);
        }

        $pending->update([
            'purged_at'     => now(),
            'purged_reason' => 'attached_to_archive',
        ]);
        $pending->delete(); // soft
    }
}
