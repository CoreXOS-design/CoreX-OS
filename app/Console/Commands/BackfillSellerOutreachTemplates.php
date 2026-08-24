<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Services\SellerOutreach\SellerOutreachTemplateDefaultsService;
use Illuminate\Console\Command;

/**
 * One-off backfill, safe to re-run. Seeds every EXISTING agency's starter
 * WhatsApp outreach template from HFC's own first one, mirroring what
 * SellerOutreachTemplateDefaultsService::ensureDefaults() now does
 * automatically for brand-new agencies (AgencyController::store()) and
 * opportunistically for any agency whose admin visits Settings → Operations
 * → Outreach Templates (SettingsController::index()).
 *
 * Root cause: HfcConsentTemplatesSeeder is deliberately scoped to HFC
 * (agency_id 1) only — every other agency had zero seller_outreach_templates
 * rows and nothing to send. The page-load hook only reaches an agency once
 * an admin opens that specific settings tab; this command guarantees every
 * existing agency is covered in one pass regardless of whether anyone ever
 * visits that page.
 *
 * Idempotent — SellerOutreachTemplateDefaultsService::ensureDefaults() skips
 * HFC itself and any agency that already has a WhatsApp template of its own.
 */
class BackfillSellerOutreachTemplates extends Command
{
    protected $signature = 'agency:backfill-outreach-templates {--dry-run : List what would be seeded without writing anything}';

    protected $description = "Seed a starter WhatsApp outreach template (cloned from HFC's) for every agency that has none";

    public function handle(SellerOutreachTemplateDefaultsService $seeder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seeded = 0;
        $skipped = 0;

        foreach (Agency::all() as $agency) {
            $hasAny = SellerOutreachTemplate::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('channel', SellerOutreachTemplate::CHANNEL_WHATSAPP)
                ->whereNull('deleted_at')
                ->exists();

            if ($hasAny) {
                $skipped++;
                continue;
            }

            $this->line("  agency #{$agency->id} ({$agency->name}): seeding starter WhatsApp template");
            if (!$dryRun) {
                $seeder->ensureDefaults((int) $agency->id);
            }
            $seeded++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. Agencies seeded: {$seeded}, skipped (already had their own, or is HFC): {$skipped}.");

        return self::SUCCESS;
    }
}
