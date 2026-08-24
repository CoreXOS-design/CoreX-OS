<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalException;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Idempotent backfill — runs the AI background-removal API against existing
 * agent photos that don't have a cutout yet. Safe to re-run: skips any
 * profile_photo row whose bg_removal_status is already 'done' unless
 * --force is passed.
 *
 * Processes SYNCHRONOUSLY (not via the queue, unlike the upload path) so it
 * can print a result table as it runs rather than requiring the operator to
 * poll queue/log state afterward — the right shape at this volume (23
 * photos total today).
 *
 * --limit and --user exist specifically so a handful of photos (including a
 * named one) can be proven against a free tier WITHOUT burning the whole
 * run before a paid key is supplied — see ad-manager.md §15.2.
 */
class AgentsBackfillPhotoCutouts extends Command
{
    protected $signature = 'agents:backfill-photo-cutouts
        {--dry-run : List what WOULD be processed, make no API calls}
        {--limit= : Process at most N photos this run}
        {--user= : Process only this user id}
        {--force : Reprocess rows that already have a cutout}';

    protected $description = 'Backfill AI background-removal cutouts for existing agent photos (ad-manager.md §15.2). Idempotent — safe to re-run.';

    public function handle(BackgroundRemovalManager $manager): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $force      = (bool) $this->option('force');
        $limit      = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $onlyUserId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $this->info('Driver: ' . $manager->driverName() . ($dryRun ? ' (dry run — no API calls will be made)' : ''));

        $query = UserDocument::withoutGlobalScopes()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->whereNotNull('file_path')
            ->orderBy('user_id');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('bg_removal_status')->orWhere('bg_removal_status', '!=', 'done');
            });
        }
        if ($onlyUserId) {
            $query->where('user_id', $onlyUserId);
        }

        $rows = [];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($query->get() as $doc) {
            $user = User::withoutGlobalScopes()->find($doc->user_id);

            if (! $user || $user->agent_photo_path !== $doc->file_path) {
                $skipped++;
                $rows[] = [$doc->user_id, $user->name ?? '(missing)', 'skipped', 'stale document row — superseded by a newer photo'];
                continue;
            }

            $agency = $user->agency_id ? Agency::withoutGlobalScopes()->find($user->agency_id) : null;
            if ($agency && ! $agency->ad_bg_removal_api_enabled) {
                $skipped++;
                $rows[] = [$user->id, $user->name, 'skipped', 'agency has AI background removal disabled'];
                continue;
            }

            if ($dryRun) {
                $rows[] = [$user->id, $user->name, 'would process', $doc->file_path];
                continue;
            }

            if ($limit !== null && $processed >= $limit) {
                $skipped++;
                $rows[] = [$user->id, $user->name, 'skipped', "run limit reached (--limit={$limit})"];
                continue;
            }

            $processed++;
            $absolutePath = Storage::disk('public')->path($doc->file_path);
            $driver = $manager->driver();

            try {
                $result = $driver->removeBackground($absolutePath);
            } catch (BackgroundRemovalException $e) {
                $failed++;
                $doc->update([
                    'bg_removal_status'       => 'failed',
                    'bg_removal_driver'       => $driver->name(),
                    'bg_removal_processed_at' => now(),
                    'bg_removal_error'        => $e->getMessage(),
                ]);
                $rows[] = [$user->id, $user->name, 'FAILED', $e->getMessage()];
                continue;
            }

            $cutoutPath = "agents/{$user->id}/photo-cutout.png";
            Storage::disk('public')->put($cutoutPath, $result->pngContents);
            $doc->update([
                'bg_removal_status'       => 'done',
                'bg_removal_cutout_path'  => $cutoutPath,
                'bg_removal_driver'       => $result->driver,
                'bg_removal_processed_at' => now(),
                'bg_removal_error'        => null,
            ]);
            $succeeded++;
            $rows[] = [$user->id, $user->name, 'done', $result->costCredits ? "cost: {$result->costCredits} credits" : 'cutout stored'];
        }

        $this->table(['Agent ID', 'Agent', 'Result', 'Detail'], $rows);
        $this->newLine();
        $this->line("Processed: {$processed}   Succeeded: {$succeeded}   Failed: {$failed}   Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
