<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dry-run of the buyer_preferences -> contact_matches + contacts.preapproval_*
 * data migration. Reads buyer_preferences, simulates the migration logic, and
 * writes its findings to wishlist_migration_log. NO writes to contacts, no
 * writes to contact_matches, no writes to either match cache table.
 *
 * Spec reference: .ai/specs/unified-buyer-wishlist-spec.md §8 (data migration)
 * + §4.5 (log table schema). Field mapping per audit §D1.
 *
 * Special-case contacts (spec D12):
 *   - contact_id=2:  Row 1 in contact_matches is all-null and will be soft-deleted
 *                    by Prompt 08 before this bp row is migrated, so the prediction
 *                    here is `would_create` (post-delete the contact has no matches).
 *   - contact_id=24: Row 2 has real criteria; collision is resolved by richness —
 *                    the richer row becomes primary, the other is demoted.
 */
class WishlistMigrateDryRun extends Command
{
    protected $signature = 'wishlist:migrate-dry-run
                            {--clear-previous-runs : Truncate prior dry-run log entries first}';

    protected $description = 'Simulate the buyer_preferences → ContactMatch migration without writing any data (logs only).';

    /** Fields on contact_matches that count toward "match-criteria richness". Excludes preapproval. */
    private const MATCH_CRITERIA_FIELDS = [
        'category', 'property_type', 'property_types', 'price_min', 'price_max',
        'beds_min', 'bedrooms_max', 'baths_min', 'garages_min', 'parking_min',
        'floor_size_min', 'floor_size_max', 'erf_size_min', 'erf_size_max',
        'suburb', 'suburbs', 'must_have_features', 'nice_to_have_features',
        'deal_breakers', 'notes',
    ];

    /** Fields on buyer_preferences that count as match criteria (excludes preapproval block). */
    private const BP_CRITERIA_FIELDS = [
        'budget_min', 'budget_max', 'bedrooms_min', 'bedrooms_max',
        'preferred_areas', 'preferred_property_types', 'must_have_features', 'deal_breakers',
    ];

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $this->info("Dry-run started, run_id={$runId}");

        if ($this->option('clear-previous-runs')) {
            $cleared = DB::table('wishlist_migration_log')->where('mode', 'dry_run')->delete();
            $this->line("  cleared {$cleared} prior dry-run log entries");
        }

        $bpCount = DB::table('buyer_preferences')->count();
        if ($bpCount === 0) {
            $this->info('buyer_preferences is empty — nothing to migrate.');
            return self::SUCCESS;
        }
        $this->line("  buyer_preferences rows to process: {$bpCount}");

        // System user existence check (informational only — Prompt 08 creates).
        $systemEmail = config('corex.wishlist_migration.system_user_email', 'system@corexos.co.za');
        $systemUserId = User::withoutGlobalScopes()->where('email', $systemEmail)->value('id');
        if (!$systemUserId) {
            $this->warn("  note: system user [{$systemEmail}] does not yet exist (Prompt 08 will create). Logged as placeholder.");
        }

        $stats = [
            'would_create' => 0, 'would_append' => 0, 'would_merge' => 0,
            'would_skip' => 0, 'would_fail' => 0,
        ];
        $preapprovalCount = 0;
        $emptyPropertyTypesCount = 0;
        $contact2Note = null;
        $contact24Note = null;

        // Baseline invariants — captured before iteration, asserted after.
        $invariantBefore = $this->captureInvariants();

        $bpRows = DB::table('buyer_preferences')->orderBy('id')->get();

        foreach ($bpRows as $bp) {
            try {
                [$action, $isPrimary, $notes, $extraStat] = $this->predictAction($bp, $systemEmail, $systemUserId);

                $mapping = $this->buildFieldMapping($bp, $isPrimary, $systemUserId, $systemEmail);
                $contact = Contact::withoutGlobalScopes()->find($bp->contact_id);
                $agencyId = $contact?->agency_id ?? 0;

                if (($mapping['contacts.preapproval_amount'] ?? null) !== null) {
                    $preapprovalCount++;
                }
                // Source rows whose preferred_property_types is NULL or [] will
                // map to property_types=NULL on the new contact_matches row.
                if (($mapping['contact_matches.property_types'] ?? null) === null) {
                    $emptyPropertyTypesCount++;
                }

                DB::table('wishlist_migration_log')->insert([
                    'run_id'                     => $runId,
                    'source_buyer_preference_id' => $bp->id,
                    'target_contact_match_id'    => null,
                    'contact_id'                 => $bp->contact_id,
                    'agency_id'                  => $agencyId,
                    'action'                     => $action,
                    'mode'                       => 'dry_run',
                    'notes'                      => $notes,
                    'field_mapping_snapshot'     => json_encode($mapping),
                    'created_at'                 => now(),
                ]);

                $stats[$action] = ($stats[$action] ?? 0) + 1;

                if ($bp->contact_id === 2)  { $contact2Note  = $notes; }
                if ($bp->contact_id === 24) { $contact24Note = $notes; }

            } catch (\Throwable $e) {
                DB::table('wishlist_migration_log')->insert([
                    'run_id'                     => $runId,
                    'source_buyer_preference_id' => $bp->id,
                    'target_contact_match_id'    => null,
                    'contact_id'                 => $bp->contact_id,
                    'agency_id'                  => 0,
                    'action'                     => 'would_fail',
                    'mode'                       => 'dry_run',
                    'notes'                      => 'Exception during prediction: ' . $e->getMessage(),
                    'field_mapping_snapshot'     => null,
                    'created_at'                 => now(),
                ]);
                $stats['would_fail']++;
            }
        }

        // Assert invariants — fail loudly if any writes leaked.
        $this->assertInvariantsHeld($invariantBefore);

        $this->newLine();
        $this->info("Dry-run summary (run_id={$runId}):");
        $this->line("  Total rows processed:  {$bpCount}");
        $this->line("  would_create:          {$stats['would_create']}");
        $this->line("  would_append:          {$stats['would_append']}");
        $this->line("  would_merge:           {$stats['would_merge']}");
        $this->line("  would_skip:            {$stats['would_skip']}");
        $this->line("  would_fail:            {$stats['would_fail']}");
        $this->newLine();
        $this->line('Special cases:');
        $this->line('  contact_id=2:  ' . ($contact2Note ?? '(no buyer_preferences row)'));
        $this->line('  contact_id=24: ' . ($contact24Note ?? '(no buyer_preferences row)'));
        $this->newLine();
        $this->line("Preapproval block migrations: {$preapprovalCount} (of {$bpCount})");
        $this->line("Empty preferred_property_types rows: {$emptyPropertyTypesCount} (will result in null property_types)");
        $this->newLine();
        $this->info('No data was written to contacts, contact_matches, prospecting_buyer_matches, or property_buyer_matches.');
        $logCount = DB::table('wishlist_migration_log')->where('run_id', $runId)->count();
        $this->info("wishlist_migration_log run_id={$runId} has {$logCount} entries.");
        $this->newLine();
        $this->line('Review the log:');
        $this->line("  php artisan wishlist:show-dry-run-log --run-id={$runId}");
        $this->line('To run live migration after review:');
        $this->line('  php artisan wishlist:migrate           (Prompt 08, not yet built)');

        return self::SUCCESS;
    }

    /**
     * Predict the migration action for one buyer_preferences row.
     * Returns [action, isPrimary, notes, extraStat].
     *
     * @return array{0:string,1:bool,2:string,3:?string}
     */
    private function predictAction(object $bp, string $systemEmail, ?int $systemUserId): array
    {
        $contact = Contact::withoutGlobalScopes()->find($bp->contact_id);
        if (!$contact) {
            return ['would_skip', false, "Orphan: contact_id={$bp->contact_id} not found.", null];
        }

        // Existing matches for this contact (including soft-deleted, since the
        // observer's primary-flag enforcement looks at non-deleted siblings only).
        $allMatches  = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->withTrashed()
            ->get();
        $liveMatches = $allMatches->whereNull('deleted_at');

        // Special case: contact_id=2 — Row 1 is the empty placeholder that gets
        // soft-deleted by Prompt 08 BEFORE this bp row is processed. After that
        // delete, this contact has zero live matches → would_create as primary.
        if ($contact->id === 2) {
            $row1 = $allMatches->firstWhere('id', 1);
            $note = 'D12 contact_id=2: Row 1 (id=1) will be soft-deleted by live migration before this row is created. '
                . 'Post-delete the contact has no matches → action=would_create, is_primary=true.';
            return ['would_create', true, $note, null];
        }

        // Special case: contact_id=24 — collision with existing Row 2.
        if ($contact->id === 24) {
            $row2 = $allMatches->firstWhere('id', 2);
            $row2Richness  = $this->matchRichness($row2);
            $bpRichness    = $this->bpRichness($bp);
            $bpWins        = $bpRichness > $row2Richness;
            $isPrimary     = $bpWins;
            $note = sprintf(
                'D12 contact_id=24: collision with existing Row 2. Richness — Row 2 (existing): %d (%s) | bp row (incoming): %d (%s). '
                . 'Verdict: %s. Migrated row is_primary=%s; existing Row 2 will be %s.',
                $row2Richness,
                implode(',', $this->matchRichnessFields($row2)),
                $bpRichness,
                implode(',', $this->bpRichnessFields($bp)),
                $bpWins ? 'incoming bp row wins (richer)' : 'existing Row 2 wins (richer or equal)',
                $isPrimary ? 'true'  : 'false',
                $isPrimary ? 'demoted to is_primary=false' : 'kept as primary',
            );
            return ['would_append', $isPrimary, $note, null];
        }

        // General case.
        if ($liveMatches->isEmpty()) {
            return ['would_create', true, 'No existing live matches for this contact → would_create as primary.', null];
        }

        // Existing matches present, no special case — append as non-primary.
        $existingPrimaries = $liveMatches->where('is_primary', true)->count();
        $note = sprintf(
            'Existing live matches=%d (primaries=%d) → would_append as non-primary. '
            . 'No spec-recognised collision rule applies to this contact.',
            $liveMatches->count(),
            $existingPrimaries
        );
        return ['would_append', false, $note, null];
    }

    private function matchRichness(?ContactMatch $m): int
    {
        return count($this->matchRichnessFields($m));
    }

    /** @return string[] */
    private function matchRichnessFields(?ContactMatch $m): array
    {
        if (!$m) return [];
        $hit = [];
        foreach (self::MATCH_CRITERIA_FIELDS as $f) {
            $v = $m->{$f};
            if ($v === null || $v === '' || $v === []) continue;
            $hit[] = $f;
        }
        return $hit;
    }

    private function bpRichness(object $bp): int
    {
        return count($this->bpRichnessFields($bp));
    }

    /** @return string[] */
    private function bpRichnessFields(object $bp): array
    {
        $hit = [];
        foreach (self::BP_CRITERIA_FIELDS as $f) {
            $v = $bp->{$f} ?? null;
            if ($v === null || $v === '' || $v === '[]' || $v === '{}') continue;
            $hit[] = $f;
        }
        return $hit;
    }

    /**
     * Build the would-be field mapping for the log's snapshot column. Includes
     * both contact_matches.* and contacts.* fields with explicit prefixes for
     * clarity in the audit trail.
     *
     * @return array<string,mixed>
     */
    private function buildFieldMapping(object $bp, bool $isPrimary, ?int $systemUserId, string $systemEmail): array
    {
        $contact = Contact::withoutGlobalScopes()->find($bp->contact_id);
        $agencyId = $contact?->agency_id ?? null;

        $propertyTypes = $this->jsonDecodeArray($bp->preferred_property_types);
        $propertyType  = (is_array($propertyTypes) && count($propertyTypes) === 1) ? $propertyTypes[0] : null;

        $stamperId   = $bp->updated_by_user_id ?? $systemUserId;
        $stamperNote = $stamperId
            ? "user_id={$stamperId}"
            : "system user [{$systemEmail}] (placeholder — Prompt 08 will create)";

        return [
            'source_table'                  => 'buyer_preferences',
            'source_id'                     => $bp->id,
            'contact_matches.agency_id'     => $agencyId,
            'contact_matches.contact_id'    => $bp->contact_id,
            'contact_matches.listing_type'  => 'sale',
            'contact_matches.status'        => 'active',
            'contact_matches.is_primary'    => $isPrimary,
            'contact_matches.price_min'     => $bp->budget_min !== null ? (int) $bp->budget_min : null,
            'contact_matches.price_max'     => $bp->budget_max !== null ? (int) $bp->budget_max : null,
            'contact_matches.beds_min'      => $bp->bedrooms_min,
            'contact_matches.bedrooms_max'  => $bp->bedrooms_max,
            'contact_matches.suburbs'       => $this->jsonDecodeArray($bp->preferred_areas),
            'contact_matches.property_types' => $propertyTypes ?: null,
            'contact_matches.property_type' => $propertyType,
            'contact_matches.must_have_features'    => $this->jsonDecodeArray($bp->must_have_features),
            'contact_matches.nice_to_have_features' => null, // no source column
            'contact_matches.deal_breakers'         => $this->jsonDecodeArray($bp->deal_breakers),
            'contact_matches.notes'                 => null, // no source column
            'contact_matches.created_by_user_id'    => $stamperId,
            'contact_matches.updated_by_user_id'    => $stamperId,
            'contact_matches.stamper_note'          => $stamperNote,
            'contacts.preapproval_amount'      => $bp->preapproval_amount !== null ? (float) $bp->preapproval_amount : null,
            'contacts.preapproval_expires_at'  => $bp->preapproval_expires_at,
            'contacts.preapproval_institution' => $bp->preapproval_institution,
        ];
    }

    private function jsonDecodeArray(?string $json): ?array
    {
        if ($json === null || $json === '') return null;
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || count($decoded) === 0) return [];
        return array_values($decoded);
    }

    /** @return array<string,int> */
    private function captureInvariants(): array
    {
        return [
            'contacts_with_preapproval' => DB::table('contacts')->whereNotNull('preapproval_amount')->count(),
            'contact_matches_all'       => DB::table('contact_matches')->count(),
            'contact_matches_trashed'   => DB::table('contact_matches')->whereNotNull('deleted_at')->count(),
            'buyer_preferences'         => DB::table('buyer_preferences')->count(),
            'property_buyer_matches'    => DB::table('property_buyer_matches')->count(),
            'prospecting_buyer_matches' => DB::table('prospecting_buyer_matches')->count(),
        ];
    }

    /** @param array<string,int> $before */
    private function assertInvariantsHeld(array $before): void
    {
        $after = $this->captureInvariants();
        foreach ($before as $key => $value) {
            if ($after[$key] !== $value) {
                throw new \RuntimeException(
                    "Dry-run invariant violated for [{$key}]: before={$value}, after={$after[$key]}. ".
                    'This is a fatal bug — the dry-run must never modify protected tables.'
                );
            }
        }
    }
}
