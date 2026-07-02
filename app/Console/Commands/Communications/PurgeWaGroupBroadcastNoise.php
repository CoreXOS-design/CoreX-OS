<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-151 — one-off remediation for WhatsApp group/broadcast noise that was
 * archived before the ingestion-gate filter existed.
 *
 * A group message (thread_key …@g.us) or a status broadcast (status@broadcast)
 * whose sender resolved to a contact was archived and linked per-participant,
 * so every group a contact is in surfaced as a separate "thread" on their record
 * (the Elize 10298 four-thread fragmentation — see
 * .ai/audits/2026-07-02-elize-four-thread-fragmentation.md).
 *
 * Soft event ONLY — sets purged_at/purged_reason so the archive viewer's
 * notPurged() scope hides these rows; NO hard deletes, rows stay recoverable and
 * the content-addressed bytes (possibly shared by dedup) are never touched. The
 * real 1:1 (@lid / phone) threads are NOT matched and stay intact.
 *
 * Idempotent (skips already-purged rows), agency-scopable, and --dry-run safe.
 */
class PurgeWaGroupBroadcastNoise extends Command
{
    protected $signature = 'communications:purge-wa-noise
        {--agency= : Restrict to one agency id (default: all agencies)}
        {--dry-run : Report what would be purged without writing}';

    protected $description = 'Soft-purge archived WhatsApp group (@g.us) and status@broadcast messages (never a 1:1).';

    public function handle(): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $dryRun = (bool) $this->option('dry-run');

        // WhatsApp rows, not already purged, whose thread_key is a group (…@g.us)
        // or a status broadcast. @lid / phone 1:1 threads never match either clause.
        $base = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->whereNull('purged_at')
            ->where(function ($q) {
                $q->where('thread_key', 'like', '%@g.us')
                  ->orWhere('thread_key', 'like', '%status@broadcast%');
            })
            ->when($agencyId !== null, fn ($q) => $q->where('agency_id', $agencyId));

        $count = (clone $base)->count();
        $threads = (clone $base)->distinct()->count('thread_key');
        $scope = $agencyId !== null ? "agency {$agencyId}" : 'all agencies';

        if ($count === 0) {
            $this->info("No WhatsApp group/broadcast noise to purge ({$scope}).");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[dry-run] Would soft-purge {$count} WhatsApp message(s) across {$threads} group/broadcast thread(s) ({$scope}). Nothing written.");
            return self::SUCCESS;
        }

        $purged = (clone $base)->update([
            'purged_at'     => now(),
            'purged_reason' => 'group_broadcast_noise',
        ]);

        Log::info('AT-151 WhatsApp group/broadcast noise soft-purged', [
            'scope'        => $scope,
            'agency_id'    => $agencyId,
            'messages'     => $purged,
            'threads'      => $threads,
            'purged_reason' => 'group_broadcast_noise',
        ]);

        $this->info("Soft-purged {$purged} WhatsApp message(s) across {$threads} group/broadcast thread(s) ({$scope}). Rows retained (purged_at set), recoverable.");

        return self::SUCCESS;
    }
}
