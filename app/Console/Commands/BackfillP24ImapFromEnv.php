<?php

namespace App\Console\Commands;

use App\Models\AgencyP24ImapSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P24 IMAP per-agency (#3) — one-off backfill, safe to re-run. Before this
 * feature, P24 ingestion read a single global mailbox from .env
 * (services.p24_imap.*) and attributed every imported listing to whichever
 * agency was first to have p24_agency_id set — in practice always the same
 * one agency (see the old P24ImapImportService::resolveAgencyId()).
 *
 * Cutting ImportP24Alerts over to per-agency DB-stored mailboxes (this
 * feature) would silently break that agency's currently-working ingestion
 * the moment nothing has a DB row yet. This command seeds THAT agency's own
 * agency_p24_imap_settings row from the existing .env values, so the
 * currently-live P24 alert pipeline keeps working unchanged until an admin
 * re-enters it via the new Settings screen.
 *
 * Never overwrites a row that already exists (explicit existence check).
 */
class BackfillP24ImapFromEnv extends Command
{
    protected $signature = 'p24-imap:backfill-from-env {--dry-run : Show what would be created without writing anything}';

    protected $description = 'Seed the currently-active agency\'s P24 IMAP settings row from the legacy .env config (P24 IMAP per-agency, #3)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $agencyId = DB::table('agencies')->whereNotNull('p24_agency_id')->orderBy('id')->value('id');

        if (! $agencyId) {
            $this->warn('No agency has p24_agency_id configured — nothing to backfill.');
            return self::SUCCESS;
        }

        if (AgencyP24ImapSetting::withoutGlobalScopes()->where('agency_id', $agencyId)->exists()) {
            $this->info("Agency #{$agencyId} already has its own P24 IMAP settings row — nothing to do.");
            return self::SUCCESS;
        }

        $config = config('services.p24_imap');

        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            $this->warn('.env has no P24 IMAP credentials configured (P24_IMAP_HOST/USERNAME/PASSWORD) — nothing to backfill.');
            return self::SUCCESS;
        }

        $this->line("Agency #{$agencyId}: host={$config['host']} username={$config['username']} folder={$config['folder']}");

        if ($dryRun) {
            $this->info('[DRY RUN] Would create agency_p24_imap_settings row above.');
            return self::SUCCESS;
        }

        AgencyP24ImapSetting::create([
            'agency_id'          => $agencyId,
            'imap_host'          => $config['host'],
            'imap_port'          => (int) $config['port'],
            'imap_encryption'    => $config['encryption'],
            'imap_folder'        => $config['folder'],
            'username'           => $config['username'],
            'encrypted_password' => $config['password'],
            'active'             => (bool) $config['enabled'],
        ]);

        $this->info("Done. Agency #{$agencyId}'s P24 IMAP settings seeded from .env.");

        return self::SUCCESS;
    }
}
