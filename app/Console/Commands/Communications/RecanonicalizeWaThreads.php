<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\CommsThreadSetting;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\WaThreadKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-168 Part A — re-key existing WhatsApp archive rows onto the CANONICAL
 * thread key so the same human captured via the extension (@lid) and via WAHA
 * (@c.us) collapse into ONE thread.
 *
 * For every WhatsApp row (unpurged, NOT a group/broadcast — those never become a
 * 1:1 thread) it computes `wa:<last-9 of from_identifier>` and, where that
 * differs from the current thread_key, re-keys the communication and MERGES any
 * per-thread privacy settings + per-thread access grants that pointed at the old
 * key onto the canonical one. The raw chat id is preserved in wa_chat_id (the
 * migration backfilled it) so WAHA media recovery is unaffected.
 *
 * Idempotent (a row already on its canonical key is skipped), agency-scopable,
 * and --dry-run safe. No hard deletes — a settings-row that would collide with an
 * existing canonical setting is soft-deleted (the canonical one wins), never
 * dropped. The AgencyScope bypass mirrors the audited maintenance-command
 * exception (reassign-capture-owner, purge-wa-noise).
 */
class RecanonicalizeWaThreads extends Command
{
    protected $signature = 'communications:recanonicalize-wa-threads
        {--agency= : Restrict to one agency id (default: all agencies)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Collapse fragmented WhatsApp threads onto the canonical wa:<number> thread key.';

    public function handle(): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $dryRun   = (bool) $this->option('dry-run');
        $scope    = $agencyId !== null ? "agency {$agencyId}" : 'all agencies';

        // WhatsApp, unpurged, 1:1 only (exclude group/broadcast — the AT-151 noise
        // purge owns those; they must never fold into a person's thread).
        $rows = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->whereNull('purged_at')
            ->when($agencyId !== null, fn ($q) => $q->where('agency_id', $agencyId))
            ->get(['id', 'agency_id', 'thread_key', 'wa_chat_id', 'from_identifier']);

        // Build the old→new re-key map, skipping group/broadcast and no-ops.
        $rekeys = [];   // communication id => canonical
        $pairs  = [];   // "agency|old" => ['agency'=>, 'old'=>, 'new'=>]
        foreach ($rows as $r) {
            $rawChat = $r->wa_chat_id ?: $r->thread_key;
            if (WaThreadKey::isGroupOrBroadcast($rawChat)) {
                continue;
            }
            $canonical = WaThreadKey::canonical($r->from_identifier);
            if ($canonical === null || $canonical === $r->thread_key) {
                continue; // unresolvable number → leave as-is; already canonical → no-op
            }
            $rekeys[$r->id] = $canonical;
            if ($r->thread_key !== null && $r->thread_key !== '') {
                $pairs[$r->agency_id . '|' . $r->thread_key] = [
                    'agency' => (int) $r->agency_id, 'old' => $r->thread_key, 'new' => $canonical,
                ];
            }
        }

        if (empty($rekeys)) {
            $this->info("No fragmented WhatsApp threads to canonicalize ({$scope}).");
            return self::SUCCESS;
        }

        $msgCount    = count($rekeys);
        $threadCount = count(array_unique($rekeys));
        $mergePairs  = count($pairs);

        if ($dryRun) {
            $this->warn("[dry-run] Would re-key {$msgCount} WhatsApp message(s) onto {$threadCount} canonical thread(s); {$mergePairs} old→canonical settings/grant merge(s) ({$scope}). Nothing written.");
            foreach (array_slice($pairs, 0, 20) as $p) {
                $this->line("  agency {$p['agency']}: {$p['old']}  →  {$p['new']}");
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rekeys, $pairs) {
            // 1) Re-key the communications themselves (grouped by target to batch).
            $byTarget = [];
            foreach ($rekeys as $id => $canonical) {
                $byTarget[$canonical][] = $id;
            }
            foreach ($byTarget as $canonical => $ids) {
                Communication::query()->withoutGlobalScope(AgencyScope::class)
                    ->whereIn('id', $ids)->update(['thread_key' => $canonical]);
            }

            // 2) Merge per-thread privacy settings + access grants onto the canonical
            //    key. Settings carry a unique (agency, contact, thread_key) — on a
            //    collision the canonical row wins and the stale one is soft-deleted.
            foreach ($pairs as $p) {
                $this->mergeThreadSettings($p['agency'], $p['old'], $p['new']);

                CommsAccessRequest::query()->withoutGlobalScope(AgencyScope::class)
                    ->where('agency_id', $p['agency'])
                    ->where('thread_key', $p['old'])
                    ->update(['thread_key' => $p['new']]);
            }
        });

        Log::info('AT-168 WhatsApp threads recanonicalized', [
            'scope'    => $scope,
            'agency_id' => $agencyId,
            'messages' => $msgCount,
            'threads'  => $threadCount,
            'merges'   => $mergePairs,
        ]);

        $this->info("Re-keyed {$msgCount} WhatsApp message(s) onto {$threadCount} canonical thread(s); merged {$mergePairs} settings/grant pair(s) ({$scope}).");

        return self::SUCCESS;
    }

    /**
     * Move any thread-setting rows from the old key to the canonical key,
     * respecting the unique (agency, contact, thread_key) index: if a setting
     * already exists on the canonical key for that contact, the canonical one
     * wins and the old one is soft-deleted (no hard delete).
     */
    private function mergeThreadSettings(int $agencyId, string $old, string $new): void
    {
        $olds = CommsThreadSetting::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('thread_key', $old)->get();

        foreach ($olds as $setting) {
            $exists = CommsThreadSetting::query()->withoutGlobalScope(AgencyScope::class)
                ->withTrashed()
                ->where('agency_id', $agencyId)
                ->where('contact_id', $setting->contact_id)
                ->where('thread_key', $new)
                ->exists();

            if ($exists) {
                $setting->delete(); // canonical setting already present → retire the stale one (soft)
            } else {
                $setting->update(['thread_key' => $new]);
            }
        }
    }
}
