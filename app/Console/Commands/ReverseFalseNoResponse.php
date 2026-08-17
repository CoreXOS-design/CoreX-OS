<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Communications\Communication;
use App\Models\SellerOutreach\SellerOutreachSend;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-81 REMEDIATION — reverse the FALSE "no_response" marketing opt-outs created
 * when the no-response clock was anchored on a composed pitch rather than a
 * delivered message (see .ai/audits/2026-08-17-outreach-no-response-false-optout.md).
 *
 * Selects ONLY system-lapsed, no-delivery-evidence records:
 *   messaging_opt_out_kind = 'no_response'
 *   AND messaging_opt_out_source = 'system:no_response'
 *   AND no send that is delivery-evidence (an email send, OR a WhatsApp send whose
 *       linked communication was confirmed sent).
 * Explicit human 'declined' opt-outs can NEVER match (kind filter) and are never touched.
 *
 * Reversible + snapshot-backed:
 *   --dry-run (default)  preview: counts, per-agency, declined-touched (must be 0),
 *                        suppressions that would lift, sample. NO writes.
 *   --apply              snapshot each contact's opt-out triplet + its active
 *                        system:no_response suppression ids into
 *                        outreach_no_response_reversal_backups, THEN clear the triplet
 *                        (→ NULL, restores to INITIAL — NOT opted-in) and lift the
 *                        suppressions (lifted_at = now). Prints the batch id.
 *   --restore=<batch>    put a prior batch back exactly as it was (re-set the triplet,
 *                        re-activate the suppressions).
 *
 * Idempotent: after --apply a contact no longer matches (kind/source cleared), so a
 * re-run selects 0; --restore skips rows already restored.
 */
class ReverseFalseNoResponse extends Command
{
    protected $signature = 'outreach:reverse-false-no-response
        {--agency= : Limit to a single agency id}
        {--dry-run : Preview only — no writes (this is the default when neither --apply nor --restore is given)}
        {--apply : Perform the reversal (writes: snapshot, clear triplet, lift suppressions)}
        {--restore= : Restore a prior batch id (undo a reversal)}
        {--limit=0 : Cap the number of contacts processed (0 = all)}';

    protected $description = 'Reverse FALSE no_response marketing opt-outs (system-lapsed, no delivery evidence) — reversible, snapshot-backed. Dry-run by default.';

    private const OPT_OUT_SOURCE = 'system:no_response';
    private const SUPPRESSION_SOURCE = 'system:no_response';
    private const BACKUP_TABLE = 'outreach_no_response_reversal_backups';

    public function handle(): int
    {
        $restore = $this->option('restore');
        if ($restore !== null && $restore !== '') {
            return $this->restore((string) $restore);
        }

        $agencyId = $this->option('agency') !== null && $this->option('agency') !== ''
            ? (int) $this->option('agency') : null;
        $apply    = (bool) $this->option('apply');
        $limit    = (int) $this->option('limit');

        $candidates = $this->candidateQuery($agencyId)->orderBy('id');
        if ($limit > 0) {
            $candidates->limit($limit);
        }
        $rows = $candidates->get();

        $total = $rows->count();
        $scopeLabel = $agencyId ? "agency {$agencyId}" : 'all agencies';
        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . " — false no_response reversal ({$scopeLabel}):");
        $this->line("  candidates (system-lapsed, no delivery evidence): {$total}");

        // Safety assertion — declined must NEVER be in scope.
        $declinedInScope = $this->declinedGuardCount($agencyId);
        $this->line("  explicit 'declined' opt-outs in scope (MUST be 0): {$declinedInScope}");

        // Per-agency breakdown.
        $byAgency = $rows->groupBy('agency_id')->map->count();
        foreach ($byAgency as $aid => $c) {
            $this->line("     agency {$aid}: {$c}");
        }

        // Suppressions that would lift.
        $contactIds = $rows->pluck('id')->all();
        $supCount = empty($contactIds) ? 0 : DB::table('marketing_suppressions')
            ->whereIn('contact_id', $contactIds)
            ->where('source', self::SUPPRESSION_SOURCE)
            ->whereNull('lifted_at')->count();
        $this->line("  active marketing_suppressions that would lift: {$supCount}");

        if ($declinedInScope > 0) {
            $this->error('ABORT — declined opt-outs are in scope; selection is unsafe. No changes made.');
            return self::FAILURE;
        }

        // sample
        foreach ($rows->take(8) as $c) {
            $this->line("     #{$c->id} agency {$c->agency_id} | opt_out {$c->messaging_opt_out_at} | src {$c->messaging_opt_out_source} | kind {$c->messaging_opt_out_kind}");
        }

        if (! $apply) {
            $this->warn('DRY-RUN — no changes made. Re-run with --apply (and Johan\'s go) to reverse.');
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('Nothing to reverse.');
            return self::SUCCESS;
        }

        $batchId = 'rev_' . now()->format('YmdHis') . '_' . Str::lower(Str::random(4));
        $reversed = 0;
        $liftedTotal = 0;

        DB::transaction(function () use ($rows, $batchId, &$reversed, &$liftedTotal) {
            foreach ($rows as $c) {
                // active system:no_response suppression ids for this contact
                $supIds = DB::table('marketing_suppressions')
                    ->where('contact_id', $c->id)
                    ->where('source', self::SUPPRESSION_SOURCE)
                    ->whereNull('lifted_at')
                    ->pluck('id')->all();

                // 1) snapshot BEFORE mutating
                DB::table(self::BACKUP_TABLE)->insert([
                    'batch_id'                    => $batchId,
                    'agency_id'                   => $c->agency_id,
                    'contact_id'                  => $c->id,
                    'opt_out_at'                  => $c->messaging_opt_out_at,
                    'opt_out_reason'              => $c->messaging_opt_out_reason,
                    'opt_out_recorded_by_user_id' => $c->messaging_opt_out_recorded_by_user_id,
                    'opt_out_source'              => $c->messaging_opt_out_source,
                    'opt_out_kind'                => $c->messaging_opt_out_kind,
                    'suppression_ids'             => json_encode(array_values($supIds)),
                    'reversed_at'                 => now(),
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ]);

                // 2) clear the opt-out triplet (→ INITIAL, NOT opted-in). Query-builder
                //    update (no model events) so no observer cascade fires.
                DB::table('contacts')->where('id', $c->id)->update([
                    'messaging_opt_out_at'                  => null,
                    'messaging_opt_out_reason'              => null,
                    'messaging_opt_out_recorded_by_user_id' => null,
                    'messaging_opt_out_source'              => null,
                    'messaging_opt_out_kind'                => null,
                    'updated_at'                            => now(),
                ]);

                // 3) lift the suppressions (reversible: lifted_at set)
                if (! empty($supIds)) {
                    DB::table('marketing_suppressions')->whereIn('id', $supIds)->update([
                        'lifted_at'  => now(),
                        'updated_at' => now(),
                    ]);
                    $liftedTotal += count($supIds);
                }

                $reversed++;
            }
        });

        $this->info("APPLIED. batch={$batchId} — {$reversed} contacts reversed, {$liftedTotal} suppressions lifted.");
        $this->line("Restore with: php artisan outreach:reverse-false-no-response --restore={$batchId}");
        return self::SUCCESS;
    }

    /**
     * The wrongly-flagged set: system-lapsed no_response with NO delivery evidence.
     * Single source of truth for the selection (dry-run and apply share it).
     */
    private function candidateQuery(?int $agencyId)
    {
        return Contact::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('purged_at')
            ->whereNotNull('messaging_opt_out_at')
            ->where('messaging_opt_out_kind', Contact::OPT_OUT_KIND_NO_RESPONSE)
            ->where('messaging_opt_out_source', self::OPT_OUT_SOURCE)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            // NO email send (system-delivered)
            ->whereNotExists(function (QueryBuilder $s) {
                $s->select(DB::raw(1))->from('seller_outreach_sends as se')
                  ->whereColumn('se.contact_id', 'contacts.id')
                  ->whereColumn('se.agency_id', 'contacts.agency_id')
                  ->whereNull('se.deleted_at')
                  ->where('se.channel', Communication::CHANNEL_EMAIL);
            })
            // NO WhatsApp send whose linked communication was confirmed sent
            ->whereNotExists(function (QueryBuilder $s) {
                $s->select(DB::raw(1))->from('seller_outreach_sends as sw')
                  ->join('communications as cm', 'cm.id', '=', 'sw.communication_id')
                  ->whereColumn('sw.contact_id', 'contacts.id')
                  ->whereColumn('sw.agency_id', 'contacts.agency_id')
                  ->whereNull('sw.deleted_at')
                  ->where('cm.send_status', Communication::SEND_STATUS_SENT);
            });
    }

    /** Count of explicit 'declined' opt-outs that the selection would touch (must be 0). */
    private function declinedGuardCount(?int $agencyId): int
    {
        // Re-run the candidate predicate but flip the kind to 'declined' — proves the
        // filter can never sweep a human decline into scope.
        return Contact::withoutGlobalScopes()
            ->whereNull('deleted_at')->whereNull('purged_at')
            ->whereNotNull('messaging_opt_out_at')
            ->where('messaging_opt_out_kind', Contact::OPT_OUT_KIND_DECLINED)
            ->where('messaging_opt_out_source', self::OPT_OUT_SOURCE)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereIn('id', $this->candidateQuery($agencyId)->select('id'))
            ->count();
    }

    private function restore(string $batchId): int
    {
        $backups = DB::table(self::BACKUP_TABLE)
            ->where('batch_id', $batchId)
            ->whereNull('restored_at')
            ->get();

        if ($backups->isEmpty()) {
            $this->error("No un-restored backup rows for batch '{$batchId}'.");
            return self::FAILURE;
        }

        $this->info("Restoring batch {$batchId} — {$backups->count()} contacts...");
        $restored = 0; $reactivated = 0;

        DB::transaction(function () use ($backups, &$restored, &$reactivated) {
            foreach ($backups as $b) {
                // re-set the opt-out triplet exactly as snapshotted
                DB::table('contacts')->where('id', $b->contact_id)->update([
                    'messaging_opt_out_at'                  => $b->opt_out_at,
                    'messaging_opt_out_reason'              => $b->opt_out_reason,
                    'messaging_opt_out_recorded_by_user_id' => $b->opt_out_recorded_by_user_id,
                    'messaging_opt_out_source'              => $b->opt_out_source,
                    'messaging_opt_out_kind'                => $b->opt_out_kind,
                    'updated_at'                            => now(),
                ]);

                $supIds = json_decode($b->suppression_ids ?? '[]', true) ?: [];
                if (! empty($supIds)) {
                    DB::table('marketing_suppressions')->whereIn('id', $supIds)->update([
                        'lifted_at'  => null,
                        'updated_at' => now(),
                    ]);
                    $reactivated += count($supIds);
                }

                DB::table(self::BACKUP_TABLE)->where('id', $b->id)->update([
                    'restored_at' => now(), 'updated_at' => now(),
                ]);
                $restored++;
            }
        });

        $this->info("RESTORED. {$restored} contacts re-opted-out, {$reactivated} suppressions re-activated.");
        return self::SUCCESS;
    }
}
