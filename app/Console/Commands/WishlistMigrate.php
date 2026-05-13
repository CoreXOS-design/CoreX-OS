<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WishlistMigrationSnapshot;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Live data migration: buyer_preferences → contact_matches + contacts.preapproval_*.
 *
 * Spec: .ai/specs/unified-buyer-wishlist-spec.md Sections 8 (data migration)
 * + D11, D12. Mirrors the action-determination logic of WishlistMigrateDryRun
 * exactly. Single DB transaction wraps the row-writing loop + assertions; only
 * after assertions pass does buyer_preferences get TRUNCATEd.
 *
 * The match cache tables (prospecting_buyer_matches, property_buyer_matches)
 * are NOT regenerated here — that is Prompt 09's RegenerateBuyerMatchesJob.
 */
class WishlistMigrate extends Command
{
    use WishlistMigrationSnapshot;

    protected $signature = 'wishlist:migrate
                            {--dry-run-id= : The verified dry-run run_id whose plan this executes}
                            {--force : Bypass dry-run verification (emergencies only)}
                            {--skip-snapshot : Skip the table snapshot step (NOT recommended)}';

    protected $description = 'Live migration of buyer_preferences rows into contact_matches + contacts.preapproval_*.';

    private const MATCH_CRITERIA_FIELDS = [
        'category', 'property_type', 'property_types', 'price_min', 'price_max',
        'beds_min', 'bedrooms_max', 'baths_min', 'garages_min', 'parking_min',
        'floor_size_min', 'floor_size_max', 'erf_size_min', 'erf_size_max',
        'suburb', 'suburbs', 'must_have_features', 'nice_to_have_features',
        'deal_breakers', 'notes',
    ];

    private const BP_CRITERIA_FIELDS = [
        'budget_min', 'budget_max', 'bedrooms_min', 'bedrooms_max',
        'preferred_areas', 'preferred_property_types', 'must_have_features', 'deal_breakers',
    ];

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $this->newLine();
        $this->info("=== Wishlist live migration ===");
        $this->info("run_id={$runId}");
        $this->newLine();

        $bpCount = DB::table('buyer_preferences')->count();
        if ($bpCount === 0) {
            $this->error('buyer_preferences is empty — nothing to migrate. (This is the expected state post-migration.)');
            return self::FAILURE;
        }
        $this->line("  buyer_preferences rows to migrate: {$bpCount}");

        // 1. Optional snapshot.
        if (!$this->option('skip-snapshot')) {
            $this->line('  taking table snapshots...');
            $snapshotDir = $this->snapshotTables($runId);
            $this->info("  snapshot directory: {$snapshotDir}");
        } else {
            $this->warn('  --skip-snapshot: no backup taken (rollback will not be possible)');
        }

        // 2. Dry-run verification (unless --force).
        if (!$this->option('force')) {
            $dryRunId = $this->option('dry-run-id');
            if (!$dryRunId) {
                $this->error('  --dry-run-id is required unless --force. Run wishlist:migrate-dry-run first.');
                return self::FAILURE;
            }
            if (!$this->verifyDryRunStillValid($dryRunId)) {
                $this->error('  Stale dry-run; re-run wishlist:migrate-dry-run before proceeding.');
                return self::FAILURE;
            }
            $this->info("  dry-run {$dryRunId} verified against current state ✓");
        } else {
            $this->warn('  --force: skipping dry-run verification');
        }

        // 3. Ensure system user exists.
        $systemUserId = $this->ensureSystemUser();
        $this->line("  system user id={$systemUserId} ready");
        $this->newLine();

        // 4. Capture baseline counts for post-flight assertions.
        $baselineContactMatches  = DB::table('contact_matches')->whereNull('deleted_at')->count();
        $baselineContactsPreapp  = DB::table('contacts')->whereNotNull('preapproval_amount')->count();

        // 5. Transactional migration loop.
        $stats = ['created' => 0, 'appended' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0];
        try {
            DB::transaction(function () use ($runId, $systemUserId, &$stats) {
                // 5a. Soft-delete the empty Row 1 (contact_id=2) per D12.
                $row1 = ContactMatch::withoutGlobalScopes()->find(1);
                if ($row1) {
                    $row1->delete(); // soft-delete via SoftDeletes trait
                    DB::table('wishlist_migration_log')->insert([
                        'run_id'                     => $runId,
                        'source_buyer_preference_id' => 0, // synthetic — no source row for this deletion
                        'target_contact_match_id'    => 1,
                        'contact_id'                 => $row1->contact_id,
                        'agency_id'                  => $row1->agency_id ?? 0,
                        'action'                     => 'skipped',
                        'mode'                       => 'live',
                        'notes'                      => 'D12: empty placeholder ContactMatch id=1 (contact_id=2) soft-deleted before migrating its buyer_preferences row',
                        'field_mapping_snapshot'     => null,
                        'created_at'                 => now(),
                    ]);
                    $stats['deleted']++;
                }

                // 5b. Iterate every buyer_preferences row in deterministic order.
                $bpRows = DB::table('buyer_preferences')->orderBy('id')->get();
                foreach ($bpRows as $bp) {
                    try {
                        $result = $this->migrateRow($bp, $runId, $systemUserId);
                        $stats[$result]++;
                    } catch (\Throwable $e) {
                        // Log inside the transaction; throw to roll back.
                        DB::table('wishlist_migration_log')->insert([
                            'run_id'                     => $runId,
                            'source_buyer_preference_id' => $bp->id,
                            'target_contact_match_id'    => null,
                            'contact_id'                 => $bp->contact_id,
                            'agency_id'                  => 0,
                            'action'                     => 'failed',
                            'mode'                       => 'live',
                            'notes'                      => 'Exception: ' . $e->getMessage(),
                            'field_mapping_snapshot'     => null,
                            'created_at'                 => now(),
                        ]);
                        $stats['failed']++;
                        throw $e;
                    }
                }

                // 5c. Assertions inside transaction.
                $this->assertInvariants($runId);
            });
        } catch (\Throwable $e) {
            $this->error('  Migration FAILED — transaction rolled back.');
            $this->error('  ' . $e->getMessage());
            $this->line('  All changes reverted. buyer_preferences and contact_matches unchanged.');
            return self::FAILURE;
        }

        // 6. Post-transaction TRUNCATE (the point of no return for forward migration).
        $this->line('  emptying buyer_preferences (snapshot retained on disk)...');
        try {
            DB::table('buyer_preferences')->truncate();
        } catch (QueryException $e) {
            // MySQL TRUNCATE can fail on FK-referenced tables; fall back to DELETE.
            DB::table('buyer_preferences')->delete();
        }

        // 7. Final summary.
        $this->newLine();
        $this->info("=== Migration complete (run_id={$runId}) ===");
        $this->line(sprintf('  created:  %d', $stats['created']));
        $this->line(sprintf('  appended: %d', $stats['appended']));
        $this->line(sprintf('  deleted (placeholder): %d', $stats['deleted']));
        $this->line(sprintf('  skipped:  %d', $stats['skipped']));
        $this->line(sprintf('  failed:   %d', $stats['failed']));
        $this->newLine();
        $this->line('  buyer_preferences:        ' . DB::table('buyer_preferences')->count() . ' (expect 0)');
        $newCmActive = DB::table('contact_matches')->whereNull('deleted_at')->count();
        $this->line("  contact_matches (active): {$newCmActive} (baseline was {$baselineContactMatches})");
        $newContactsPreapp = DB::table('contacts')->whereNotNull('preapproval_amount')->count();
        $this->line("  contacts with preapproval: {$newContactsPreapp} (baseline was {$baselineContactsPreapp})");
        $this->newLine();
        $this->warn('  Next: php artisan wishlist:regenerate-matches  (Prompt 09 — not yet built)');
        $this->warn('  Match cache tables hold STALE data until Prompt 09 ships and runs.');
        $this->newLine();
        $this->line('  Review the live log:');
        $this->line("    php artisan wishlist:show-migration-log --run-id={$runId}");
        $this->line('  Rollback if needed:');
        $this->line("    php artisan wishlist:rollback-migration --run-id={$runId}");

        return self::SUCCESS;
    }

    /**
     * Migrate a single buyer_preferences row. Returns the stat bucket name
     * ('created', 'appended', 'skipped').
     */
    private function migrateRow(object $bp, string $runId, int $systemUserId): string
    {
        $contact = Contact::withoutGlobalScopes()->find($bp->contact_id);
        if (!$contact) {
            DB::table('wishlist_migration_log')->insert([
                'run_id'                     => $runId,
                'source_buyer_preference_id' => $bp->id,
                'target_contact_match_id'    => null,
                'contact_id'                 => $bp->contact_id,
                'agency_id'                  => 0,
                'action'                     => 'skipped',
                'mode'                       => 'live',
                'notes'                      => "Orphan: contact_id={$bp->contact_id} not found.",
                'field_mapping_snapshot'     => null,
                'created_at'                 => now(),
            ]);
            return 'skipped';
        }

        // 1. Preapproval block → Contact (spec D3).
        $hasPreapproval = $bp->preapproval_amount !== null
            || $bp->preapproval_expires_at !== null
            || $bp->preapproval_institution !== null;
        if ($hasPreapproval) {
            $contact->update([
                'preapproval_amount'      => $bp->preapproval_amount,
                'preapproval_expires_at'  => $bp->preapproval_expires_at,
                'preapproval_institution' => $bp->preapproval_institution,
            ]);
        }

        // 2. Decide action + is_primary per D12.
        $allMatches = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->withTrashed()
            ->get();
        $liveMatches = $allMatches->whereNull('deleted_at');

        $action = 'created';
        $isPrimary = true;
        $note = '';

        if ($contact->id === 2) {
            // D12: Row 1 was soft-deleted above → contact has no live matches → would_create as primary.
            $action = 'created';
            $isPrimary = true;
            $note = 'D12 contact_id=2: created as primary after empty Row 1 was soft-deleted.';
        } elseif ($contact->id === 24) {
            // D12: collision with existing Row 2. Richness comparison.
            $row2 = $allMatches->firstWhere('id', 2);
            $row2Richness = $this->matchRichness($row2);
            $bpRichness   = $this->bpRichness($bp);
            $bpWins       = $bpRichness > $row2Richness;
            $action = 'appended';
            $isPrimary = $bpWins;
            $note = sprintf(
                'D12 contact_id=24: appended (existing Row 2 richness=%d vs bp richness=%d). bp %s; new row is_primary=%s.',
                $row2Richness,
                $bpRichness,
                $bpWins ? 'wins' : 'loses',
                $isPrimary ? 'true' : 'false'
            );
        } elseif ($liveMatches->isEmpty()) {
            $action = 'created';
            $isPrimary = true;
            $note = 'No prior live matches → created as primary.';
        } else {
            $action = 'appended';
            $isPrimary = false;
            $note = 'Appended as non-primary (existing live matches present).';
        }

        // 3. Build ContactMatch payload (identical mapping to the dry-run).
        $payload = $this->buildPayload($bp, $contact, $isPrimary, $systemUserId);

        // 4. Create the ContactMatch. Observer fires; saved() handles is_primary uniqueness.
        $match = ContactMatch::withoutGlobalScopes()->create($payload);

        // 5. Log it.
        DB::table('wishlist_migration_log')->insert([
            'run_id'                     => $runId,
            'source_buyer_preference_id' => $bp->id,
            'target_contact_match_id'    => $match->id,
            'contact_id'                 => $contact->id,
            'agency_id'                  => $contact->agency_id,
            'action'                     => $action,
            'mode'                       => 'live',
            'notes'                      => $note,
            'field_mapping_snapshot'     => json_encode($payload),
            'created_at'                 => now(),
        ]);

        return $action;
    }

    /** @return array<string,mixed> */
    private function buildPayload(object $bp, Contact $contact, bool $isPrimary, int $systemUserId): array
    {
        $propertyTypes = $this->jsonDecodeArray($bp->preferred_property_types);
        $propertyType  = (is_array($propertyTypes) && count($propertyTypes) === 1) ? $propertyTypes[0] : null;
        $stamperId     = $bp->updated_by_user_id ?? $systemUserId;

        return [
            'agency_id'             => $contact->agency_id,
            'contact_id'            => $contact->id,
            'created_by_user_id'    => $stamperId,
            'updated_by_user_id'    => $stamperId,
            'status'                => ContactMatch::STATUS_ACTIVE,
            'is_primary'            => $isPrimary,
            'listing_type'          => 'sale',
            'price_min'             => $bp->budget_min !== null ? (int) $bp->budget_min : null,
            'price_max'             => $bp->budget_max !== null ? (int) $bp->budget_max : null,
            'beds_min'              => $bp->bedrooms_min,
            'bedrooms_max'          => $bp->bedrooms_max,
            'suburbs'               => $this->jsonDecodeArray($bp->preferred_areas) ?: [],
            'property_types'        => $propertyTypes ?: [],
            'property_type'         => $propertyType,
            'must_have_features'    => $this->jsonDecodeArray($bp->must_have_features) ?: [],
            'nice_to_have_features' => [],
            'deal_breakers'         => $this->jsonDecodeArray($bp->deal_breakers) ?: [],
        ];
    }

    private function jsonDecodeArray(?string $json): ?array
    {
        if ($json === null || $json === '') return null;
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || count($decoded) === 0) return [];
        return array_values($decoded);
    }

    private function matchRichness(?ContactMatch $m): int
    {
        if (!$m) return 0;
        $hits = 0;
        foreach (self::MATCH_CRITERIA_FIELDS as $f) {
            $v = $m->{$f};
            if ($v === null || $v === '' || $v === []) continue;
            $hits++;
        }
        return $hits;
    }

    private function bpRichness(object $bp): int
    {
        $hits = 0;
        foreach (self::BP_CRITERIA_FIELDS as $f) {
            $v = $bp->{$f} ?? null;
            if ($v === null || $v === '' || $v === '[]' || $v === '{}') continue;
            $hits++;
        }
        return $hits;
    }

    /**
     * Verify the supplied dry-run is still authoritative — current
     * buyer_preferences row count + per-row criteria match what was logged.
     * If any source row has changed since the dry-run was taken, abort.
     */
    private function verifyDryRunStillValid(string $dryRunId): bool
    {
        $dryRunRows = DB::table('wishlist_migration_log')
            ->where('run_id', $dryRunId)
            ->where('mode', 'dry_run')
            ->get(['source_buyer_preference_id', 'field_mapping_snapshot']);

        if ($dryRunRows->isEmpty()) {
            $this->error("  Dry-run run_id={$dryRunId} not found.");
            return false;
        }

        $bpCount = DB::table('buyer_preferences')->count();
        if ($bpCount !== $dryRunRows->count()) {
            $this->error("  Drift: buyer_preferences has {$bpCount} rows but dry-run logged {$dryRunRows->count()}.");
            return false;
        }

        foreach ($dryRunRows as $dr) {
            $bp = DB::table('buyer_preferences')->find($dr->source_buyer_preference_id);
            if (!$bp) {
                $this->error("  Drift: buyer_preferences id={$dr->source_buyer_preference_id} no longer exists.");
                return false;
            }
            $snap = json_decode($dr->field_mapping_snapshot, true);
            if (!is_array($snap)) continue;

            $compare = [
                'price_min' => $bp->budget_min !== null ? (int) $bp->budget_min : null,
                'price_max' => $bp->budget_max !== null ? (int) $bp->budget_max : null,
                'beds_min'  => $bp->bedrooms_min,
                'bedrooms_max' => $bp->bedrooms_max,
                'preapproval_amount' => $bp->preapproval_amount !== null ? (float) $bp->preapproval_amount : null,
            ];
            $expect = [
                'price_min' => $snap['contact_matches.price_min'] ?? null,
                'price_max' => $snap['contact_matches.price_max'] ?? null,
                'beds_min'  => $snap['contact_matches.beds_min'] ?? null,
                'bedrooms_max' => $snap['contact_matches.bedrooms_max'] ?? null,
                'preapproval_amount' => $snap['contacts.preapproval_amount'] ?? null,
            ];

            foreach ($compare as $key => $now) {
                if ($now != $expect[$key]) { // loose compare; ints + floats interoperate
                    $this->error("  Drift: bp.id={$bp->id} field={$key} dry-run={$expect[$key]} current={$now}");
                    return false;
                }
            }
        }
        return true;
    }

    private function ensureSystemUser(): int
    {
        $email = config('corex.wishlist_migration.system_user_email');
        $existing = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($existing) return (int) $existing->id;

        // role='system' is a string value (role column is varchar, not an enum).
        // Other code that filters by role typically uses whitelists like
        // whereNotIn('role', ['super_admin','owner']) — 'system' falls outside
        // those whitelists, which is the correct behaviour for a non-login user.
        $user = User::withoutGlobalScopes()->create([
            'name'              => 'System (Migration)',
            'email'             => $email,
            'password'          => bcrypt(Str::random(64)),
            'role'              => 'system',
            'agency_id'         => null,
            'email_verified_at' => null,
            'is_active'         => false,
        ]);
        $this->line("  system user created id={$user->id} email={$email}");
        return (int) $user->id;
    }

    private function assertInvariants(string $runId): void
    {
        // 31 live log rows for this run (deleted-placeholder log uses action='skipped' but is also stored).
        $loggedCount = DB::table('wishlist_migration_log')
            ->where('run_id', $runId)
            ->where('mode', 'live')
            ->whereIn('action', ['created', 'appended', 'merged'])
            ->count();
        $expected = DB::table('buyer_preferences')->count();
        if ($loggedCount !== $expected) {
            throw new \RuntimeException(
                "Assertion failed: logged created/appended rows = {$loggedCount}, expected {$expected}."
            );
        }

        // Every active contact has exactly one primary ContactMatch.
        $offenders = DB::table('contact_matches')
            ->whereNull('deleted_at')
            ->groupBy('contact_id')
            ->select('contact_id', DB::raw('SUM(is_primary) as primaries'))
            ->havingRaw('SUM(is_primary) != 1')
            ->count();
        if ($offenders > 0) {
            throw new \RuntimeException(
                "Assertion failed: {$offenders} contacts have ≠1 primary ContactMatch."
            );
        }

        // Every log row has target_contact_match_id (except the synthetic delete log).
        $missing = DB::table('wishlist_migration_log')
            ->where('run_id', $runId)
            ->where('mode', 'live')
            ->whereIn('action', ['created', 'appended', 'merged'])
            ->whereNull('target_contact_match_id')
            ->count();
        if ($missing > 0) {
            throw new \RuntimeException(
                "Assertion failed: {$missing} created/appended log rows have NULL target_contact_match_id."
            );
        }
    }
}
