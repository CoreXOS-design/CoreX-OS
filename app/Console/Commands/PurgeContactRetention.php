<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * POPIA-compliant retention purge — soft-purges contact records, consent
 * records, and access logs that have exceeded the agency's configured
 * retention periods.
 */
class PurgeContactRetention extends Command
{
    protected $signature = 'contacts:purge-retention {--dry-run : Show what would be purged without executing}';
    protected $description = 'Soft-purge contacts/consent/logs past retention period (POPIA + FICA + PPA)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $agencies = Agency::withoutGlobalScopes()->whereNull('deleted_at')->get();

        foreach ($agencies as $agency) {
            $settings = AgencyContactSettings::forAgency($agency->id);
            $this->info("Agency: {$agency->name} (id={$agency->id})");
            $this->info("  Retention: contacts={$settings->contact_retention_years}y, consent={$settings->consent_retention_years}y, access_log={$settings->access_log_retention_years}y");

            // 1. Soft-purge contacts deleted > X years ago
            $contactCutoff = now()->subYears($settings->contact_retention_years);
            $purgeableContacts = DB::table('contacts')
                ->where('agency_id', $agency->id)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $contactCutoff)
                ->whereNull('purged_at')
                ->count();

            $this->info("  Contacts to purge: {$purgeableContacts}");

            if (!$dryRun && $purgeableContacts > 0) {
                DB::table('contacts')
                    ->where('agency_id', $agency->id)
                    ->whereNotNull('deleted_at')
                    ->where('deleted_at', '<', $contactCutoff)
                    ->whereNull('purged_at')
                    ->update([
                        'purged_at' => now(),
                        'purged_reason' => "Retention period ({$settings->contact_retention_years} years) exceeded",
                        'first_name' => '[PURGED]',
                        'last_name' => '[PURGED]',
                        'phone' => '[PURGED]',
                        'email' => null,
                        'id_number' => null,
                        'address' => null,
                        'notes' => null,
                        'bank_name' => null,
                        'bank_account_name' => null,
                        'bank_account_number' => null,
                        'bank_branch_name' => null,
                        'bank_branch_code' => null,
                        'bank_account_type' => null,
                    ]);
            }

            // 2. Purge revoked consent records > X years
            $consentCutoff = now()->subYears($settings->consent_retention_years);
            $purgeableConsent = DB::table('contact_consent_records')
                ->where('agency_id', $agency->id)
                ->whereNotNull('revoked_at')
                ->where('revoked_at', '<', $consentCutoff)
                ->count();

            $this->info("  Consent records to purge: {$purgeableConsent}");

            if (!$dryRun && $purgeableConsent > 0) {
                DB::table('contact_consent_records')
                    ->where('agency_id', $agency->id)
                    ->whereNotNull('revoked_at')
                    ->where('revoked_at', '<', $consentCutoff)
                    ->delete(); // Hard delete — consent evidence no longer required
            }

            // 3. Hard-delete access log entries > X years
            $logCutoff = now()->subYears($settings->access_log_retention_years);
            $purgeableLogs = DB::table('contact_access_log')
                ->where('agency_id', $agency->id)
                ->where('accessed_at', '<', $logCutoff)
                ->count();

            $this->info("  Access log entries to purge: {$purgeableLogs}");

            if (!$dryRun && $purgeableLogs > 0) {
                DB::table('contact_access_log')
                    ->where('agency_id', $agency->id)
                    ->where('accessed_at', '<', $logCutoff)
                    ->delete();
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no records were modified.');
        } else {
            $this->info('Purge complete.');
        }

        return 0;
    }
}
