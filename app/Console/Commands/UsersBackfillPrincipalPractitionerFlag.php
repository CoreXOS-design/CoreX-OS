<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PPRA Inspection Pack Phase E — one-time backfill of the new
 * `users.is_principal_practitioner` flag (.ai/specs/ppra-inspection-pack.md
 * §6.6a) from the prior fragile signal: designation LIKE '%Principal%'.
 *
 * After this runs once, the flag is the source of truth and is maintained
 * going forward by an admin on the user profile screen (UserManagementController)
 * — this command never runs automatically again and is not re-derived from
 * designation on any later request.
 *
 * Idempotent: only flips is_principal_practitioner=false→true for a
 * designation match; never the other direction, and re-running is safe
 * (already-flagged users are excluded from the candidate set).
 *
 * Manual operation only — NOT invoked by scripts/deploy.sh.
 *
 * Usage:
 *   php artisan users:backfill-principal-practitioner-flag                 # all agencies, write
 *   php artisan users:backfill-principal-practitioner-flag --agency=1      # one agency, write
 *   php artisan users:backfill-principal-practitioner-flag --dry-run       # show counts only
 *   php artisan users:backfill-principal-practitioner-flag --agency=1 --dry-run
 */
class UsersBackfillPrincipalPractitionerFlag extends Command
{
    protected $signature = 'users:backfill-principal-practitioner-flag
                            {--agency= : Restrict to a single agency_id}
                            {--dry-run : Report counts without writing}';

    protected $description = 'Backfill users.is_principal_practitioner=true from designation LIKE \'%Principal%\' (idempotent, one-time).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $agencyOpt = $this->option('agency');
        $agencyId = $agencyOpt !== null ? (int) $agencyOpt : null;

        $tag = $dryRun ? '[DRY-RUN]' : '[WRITE]';
        $scope = $agencyId ? "agency_id=$agencyId" : 'ALL agencies';
        $this->info("$tag users:backfill-principal-practitioner-flag — scope: $scope");

        if ($agencyId !== null && !DB::table('agencies')->where('id', $agencyId)->exists()) {
            $this->error("Agency id=$agencyId not found.");
            return self::INVALID;
        }

        $beforeFlagged = $this->countFlagged($agencyId);
        $totalUsers = $this->countUsers($agencyId);
        $this->line('');
        $this->line('BEFORE:');
        $this->line(sprintf('  active users in scope:              %d', $totalUsers));
        $this->line(sprintf('  users with is_principal_practitioner=1: %d', $beforeFlagged));

        $candidates = $this->candidatesByDesignation($agencyId);
        $candidateCount = count($candidates);
        $this->line('');
        $this->line(sprintf("CANDIDATES (designation LIKE '%%Principal%%', not already flagged): %d", $candidateCount));

        if ($candidateCount === 0) {
            $this->line('');
            $this->info('Nothing to backfill — no un-flagged user has a "Principal" designation.');
            return self::SUCCESS;
        }

        $this->line('');
        $sample = DB::table('users')
            ->whereIn('id', array_slice($candidates, 0, 5))
            ->get(['id', 'name', 'designation', 'agency_id']);
        $this->line("Sample (first 5 of $candidateCount):");
        foreach ($sample as $u) {
            $this->line(sprintf('  id=%-6d agency=%-3d name=%-30s designation=%s', $u->id, $u->agency_id, $u->name, $u->designation ?? '-'));
        }

        if ($dryRun) {
            $this->line('');
            $this->warn("$tag — skipping write. Re-run without --dry-run to apply.");
            return self::SUCCESS;
        }

        $this->line('');
        $this->info("Applying is_principal_practitioner=true to $candidateCount users...");

        $updated = 0;
        $now = now();
        foreach (array_chunk($candidates, 500) as $chunk) {
            $updated += DB::table('users')
                ->whereIn('id', $chunk)
                ->where(function ($q) {
                    $q->where('is_principal_practitioner', false)->orWhereNull('is_principal_practitioner');
                })
                ->update([
                    'is_principal_practitioner' => true,
                    'updated_at'                => $now,
                ]);
        }

        $afterFlagged = $this->countFlagged($agencyId);
        $this->line('');
        $this->line('AFTER:');
        $this->line(sprintf('  users with is_principal_practitioner=1: %d', $afterFlagged));
        $this->line(sprintf('  Δ flipped to true:  %d  (UPDATE-touched rows: %d)', $afterFlagged - $beforeFlagged, $updated));

        $this->line('');
        $this->info('Done. The PPRA Inspection Pack item (c) roster and Report cover page now read from this flag.');
        $this->info('Re-running this command is safe (idempotent — no user is flipped twice).');

        return self::SUCCESS;
    }

    /** Active, non-deleted users with a "Principal" designation not already flagged. */
    private function candidatesByDesignation(?int $agencyId): array
    {
        $q = DB::table('users')
            ->where('designation', 'like', '%Principal%')
            ->where(function ($w) {
                $w->where('is_principal_practitioner', false)->orWhereNull('is_principal_practitioner');
            })
            ->whereNull('deleted_at');
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }

        return $q->pluck('id')->all();
    }

    private function countFlagged(?int $agencyId): int
    {
        $q = DB::table('users')->where('is_principal_practitioner', true)->whereNull('deleted_at');
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }

        return (int) $q->count();
    }

    private function countUsers(?int $agencyId): int
    {
        $q = DB::table('users')->where('is_active', true)->whereNull('deleted_at');
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }

        return (int) $q->count();
    }
}
