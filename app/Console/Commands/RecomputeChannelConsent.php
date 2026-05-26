<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;

class RecomputeChannelConsent extends Command
{
    protected $signature = 'contacts:recompute-channel-consent {--agency= : Limit to specific agency}';
    protected $description = 'Recompute denormalised opt-out flags from consent records for all contacts';

    public function handle(): int
    {
        $query = Contact::withoutGlobalScopes()->whereNull('deleted_at')->whereNull('purged_at');

        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', (int) $agencyId);
        }

        $count = $query->count();
        $this->info("Recomputing channel consent for {$count} contacts...");

        $bar = $this->output->createProgressBar($count);
        $query->chunk(200, function ($contacts) use ($bar) {
            foreach ($contacts as $contact) {
                $contact->recomputeChannelConsent();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');
        return 0;
    }
}
