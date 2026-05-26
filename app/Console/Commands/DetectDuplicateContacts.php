<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Services\ContactDuplicateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scans all agencies for duplicate contact clusters.
 * Results stored in contact_duplicate_clusters for admin cleanup queue.
 * Designed to run daily via scheduler.
 */
class DetectDuplicateContacts extends Command
{
    protected $signature = 'contacts:detect-duplicates {--agency= : Limit to specific agency ID}';
    protected $description = 'Scan contacts for duplicate clusters and populate cleanup queue';

    public function handle(): int
    {
        $service = app(ContactDuplicateService::class);
        $agencyFilter = $this->option('agency');

        $agencies = $agencyFilter
            ? Agency::withoutGlobalScopes()->where('id', $agencyFilter)->get()
            : Agency::withoutGlobalScopes()->whereNull('deleted_at')->get();

        foreach ($agencies as $agency) {
            $this->info("Scanning agency: {$agency->name} (id={$agency->id})");

            $settings = AgencyContactSettings::forAgency($agency->id);
            $matchFields = $settings->duplicate_match_fields ?? ['phone', 'email', 'id_number'];

            $clustersFound = 0;

            foreach ($matchFields as $field) {
                // Find groups of contacts sharing the same normalized field value
                $duplicateGroups = DB::table('contacts')
                    ->select(DB::raw($this->normalizeExpression($field) . ' as normalized_value'))
                    ->selectRaw('GROUP_CONCAT(id) as contact_ids')
                    ->selectRaw('COUNT(*) as cnt')
                    ->where('agency_id', $agency->id)
                    ->whereNull('deleted_at')
                    ->whereNull('purged_at')
                    ->whereNotNull($field)
                    ->where($field, '!=', '')
                    ->groupBy('normalized_value')
                    ->having('cnt', '>', 1)
                    ->get();

                foreach ($duplicateGroups as $group) {
                    $contactIds = array_map('intval', explode(',', $group->contact_ids));

                    // Skip if cluster already exists with same contacts
                    $existing = DB::table('contact_duplicate_clusters')
                        ->where('agency_id', $agency->id)
                        ->where('match_field', $field)
                        ->where('match_value', $group->normalized_value)
                        ->whereIn('status', ['pending', 'reviewed'])
                        ->first();

                    if ($existing) {
                        continue; // Already tracked
                    }

                    DB::table('contact_duplicate_clusters')->insert([
                        'agency_id' => $agency->id,
                        'contact_ids' => json_encode($contactIds),
                        'match_field' => $field,
                        'match_value' => $group->normalized_value,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $clustersFound++;
                }
            }

            $this->info("  → {$clustersFound} new clusters found");
        }

        $this->info('Done.');
        return 0;
    }

    private function normalizeExpression(string $field): string
    {
        return match ($field) {
            'phone' => "RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9)",
            'email' => "LOWER(TRIM(email))",
            'id_number' => "REPLACE(REPLACE(id_number, ' ', ''), '-', '')",
            default => $field,
        };
    }
}
