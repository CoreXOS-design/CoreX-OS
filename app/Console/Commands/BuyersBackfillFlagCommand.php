<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BUYER-PIPELINE-FIX — backfill `contacts.is_buyer` from a wider real-
 * world signal set.
 *
 * Context: the legacy migration (2026_05_05_000020_buyer_crm_foundation)
 * set is_buyer=true only for contacts who ALREADY had a calendar_event_
 * feedback row at migration time. On real/live-copy databases, that
 * leaves any contact who was a buyer through other workflows
 * (contact_property pivot, deal_v2_contacts pivot) flagged is_buyer=false,
 * so BuyerPipelineController::index → Contact::buyers() returns nothing.
 *
 * This command infers is_buyer=true from the union of three real-world
 * signals:
 *   1. A row in calendar_event_feedback with contact_id = X
 *   2. A contact_property pivot row with role IN (buyer, tenant, lessee)
 *   3. A deal_v2_contacts pivot row with role IN (buyer, co_buyer)
 *
 * Idempotent: only flips is_buyer=false→true, never the other direction.
 * Scoped: --agency=N runs against a single agency; without it, runs
 * across all agencies.
 *
 * Manual operation only — NOT invoked by scripts/deploy.sh.
 *
 * Usage:
 *   php artisan buyers:backfill-flag                  # all agencies, write
 *   php artisan buyers:backfill-flag --agency=1       # one agency, write
 *   php artisan buyers:backfill-flag --dry-run        # show counts only
 *   php artisan buyers:backfill-flag --agency=1 --dry-run
 */
class BuyersBackfillFlagCommand extends Command
{
    protected $signature = 'buyers:backfill-flag
                            {--agency= : Restrict to a single agency_id}
                            {--dry-run : Report counts without writing}';

    protected $description = 'Backfill contacts.is_buyer=true from feedback / contact_property / deal_v2_contacts signals (idempotent).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $agencyOpt = $this->option('agency');
        $agencyId = $agencyOpt !== null ? (int) $agencyOpt : null;

        $tag = $dryRun ? '[DRY-RUN]' : '[WRITE]';
        $scope = $agencyId ? "agency_id=$agencyId" : 'ALL agencies';
        $this->info("$tag buyers:backfill-flag — scope: $scope");

        // ── Sanity: agency exists? ────────────────────────────────────
        if ($agencyId !== null && !DB::table('agencies')->where('id', $agencyId)->exists()) {
            $this->error("Agency id=$agencyId not found.");
            return self::INVALID;
        }

        // ── Before-counts ─────────────────────────────────────────────
        $beforeBuyers = $this->countBuyers($agencyId);
        $totalContacts = $this->countContacts($agencyId);
        $this->line("");
        $this->line("BEFORE:");
        $this->line(sprintf("  contacts in scope:        %d", $totalContacts));
        $this->line(sprintf("  contacts with is_buyer=1: %d  (%.1f%%)",
            $beforeBuyers,
            $totalContacts > 0 ? ($beforeBuyers / $totalContacts) * 100 : 0
        ));

        // ── Per-signal candidate counts (for the report) ──────────────
        $sigFeedback = $this->signalFromFeedback($agencyId);
        $sigContactProp = $this->signalFromContactProperty($agencyId);
        $sigDeals = $this->signalFromDeals($agencyId);

        $this->line("");
        $this->line("CANDIDATE SIGNALS (contacts currently is_buyer=0 matching):");
        $this->line(sprintf("  has calendar_event_feedback row:               %d", count($sigFeedback)));
        $this->line(sprintf("  contact_property role IN (buyer,tenant,lessee): %d", count($sigContactProp)));
        $this->line(sprintf("  deal_v2_contacts role IN (buyer,co_buyer):     %d", count($sigDeals)));

        $unionIds = array_unique(array_merge($sigFeedback, $sigContactProp, $sigDeals));
        $candidateCount = count($unionIds);
        $this->line(sprintf("  UNION (distinct contacts to flip):              %d", $candidateCount));

        if ($candidateCount === 0) {
            $this->line("");
            $this->info("Nothing to backfill — all candidate contacts already have is_buyer=1.");
            return self::SUCCESS;
        }

        // ── Sample for the operator's sanity ──────────────────────────
        $this->line("");
        $sample = DB::table('contacts')
            ->whereIn('id', array_slice($unionIds, 0, 5))
            ->get(['id', 'first_name', 'last_name', 'email', 'agency_id']);
        $this->line("Sample (first 5 of $candidateCount):");
        foreach ($sample as $c) {
            $this->line(sprintf(
                "  id=%-6d agency=%-3d name=%-40s email=%s",
                $c->id, $c->agency_id,
                trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                $c->email ?? '-'
            ));
        }

        // ── Apply (or skip in dry-run) ────────────────────────────────
        if ($dryRun) {
            $this->line("");
            $this->warn("$tag — skipping write. Re-run without --dry-run to apply.");
            return self::SUCCESS;
        }

        $this->line("");
        $this->info("Applying is_buyer=true to $candidateCount contacts...");

        // Chunked update so a 5000-row agency doesn't blow IN(...) limits.
        $updated = 0;
        $now = now();
        foreach (array_chunk($unionIds, 500) as $chunk) {
            $updated += DB::table('contacts')
                ->whereIn('id', $chunk)
                ->where(function ($q) {
                    // Belt-and-braces: only update rows still flagged
                    // is_buyer=false. A row that flipped to true between
                    // the SELECT and the UPDATE is skipped (no
                    // semantic change, just count consistency).
                    $q->where('is_buyer', false)->orWhereNull('is_buyer');
                })
                ->update([
                    'is_buyer'                  => true,
                    'buyer_pipeline_entered_at' => DB::raw('COALESCE(buyer_pipeline_entered_at, NOW())'),
                    'buyer_state'               => DB::raw("COALESCE(buyer_state, 'new')"),
                    'updated_at'                => $now,
                ]);
        }

        $afterBuyers = $this->countBuyers($agencyId);
        $this->line("");
        $this->line("AFTER:");
        $this->line(sprintf("  contacts in scope:        %d", $totalContacts));
        $this->line(sprintf("  contacts with is_buyer=1: %d  (%.1f%%)",
            $afterBuyers,
            $totalContacts > 0 ? ($afterBuyers / $totalContacts) * 100 : 0
        ));
        $this->line(sprintf("  Δ flipped to is_buyer=1:  %d  (UPDATE-touched rows: %d)",
            $afterBuyers - $beforeBuyers, $updated
        ));

        $this->line("");
        $this->info("Done. The Buyer Pipeline UI should now list these contacts.");
        $this->info("Re-running this command is safe (idempotent — no contact is flipped twice).");

        return self::SUCCESS;
    }

    /** Contacts that match: (is_buyer = 0 OR NULL) AND have a feedback row. */
    private function signalFromFeedback(?int $agencyId): array
    {
        $q = DB::table('contacts as c')
            ->join('calendar_event_feedback as f', 'f.contact_id', '=', 'c.id')
            ->where(fn ($w) => $w->where('c.is_buyer', false)->orWhereNull('c.is_buyer'))
            ->whereNull('c.deleted_at');
        if ($agencyId !== null) $q->where('c.agency_id', $agencyId);
        return $q->distinct()->pluck('c.id')->all();
    }

    /** Contacts linked via contact_property with a buyer-side pivot role. */
    private function signalFromContactProperty(?int $agencyId): array
    {
        $q = DB::table('contacts as c')
            ->join('contact_property as cp', 'cp.contact_id', '=', 'c.id')
            ->whereIn(DB::raw('LOWER(TRIM(cp.role))'), ['buyer', 'tenant', 'lessee'])
            ->where(fn ($w) => $w->where('c.is_buyer', false)->orWhereNull('c.is_buyer'))
            ->whereNull('c.deleted_at');
        if ($agencyId !== null) $q->where('c.agency_id', $agencyId);
        return $q->distinct()->pluck('c.id')->all();
    }

    /** Contacts linked as buyer / co_buyer on any deal via deal_v2_contacts. */
    private function signalFromDeals(?int $agencyId): array
    {
        // Guard: deal_v2_contacts may not exist in older DBs. Skip
        // gracefully so the command works against any DB version.
        if (!\Schema::hasTable('deal_v2_contacts')) {
            $this->warn('  (deal_v2_contacts table missing — deal-signal skipped)');
            return [];
        }
        $q = DB::table('contacts as c')
            ->join('deal_v2_contacts as dc', 'dc.contact_id', '=', 'c.id')
            ->whereIn(DB::raw('LOWER(TRIM(dc.role))'), ['buyer', 'co_buyer'])
            ->where(fn ($w) => $w->where('c.is_buyer', false)->orWhereNull('c.is_buyer'))
            ->whereNull('c.deleted_at');
        if ($agencyId !== null) $q->where('c.agency_id', $agencyId);
        return $q->distinct()->pluck('c.id')->all();
    }

    private function countBuyers(?int $agencyId): int
    {
        $q = DB::table('contacts')->where('is_buyer', true)->whereNull('deleted_at');
        if ($agencyId !== null) $q->where('agency_id', $agencyId);
        return (int) $q->count();
    }

    private function countContacts(?int $agencyId): int
    {
        $q = DB::table('contacts')->whereNull('deleted_at');
        if ($agencyId !== null) $q->where('agency_id', $agencyId);
        return (int) $q->count();
    }
}
