<?php

namespace App\Console\Commands;

use App\Services\TrainingVideos\Auto\TrainingAgencySeeder;
use Illuminate\Console\Command;

/** AT-525 - make (or refresh) the training agency the recorder signs in to. */
class TrainingSeedAgency extends Command
{
    protected $signature = 'training:seed-agency {--reset : delete the training agency\'s demo rows and seed them again (keeps the agency and its users)}';

    protected $description = 'Create the CoreX Training Academy agency with invented demo data for the auto-generated training videos';

    public function handle(TrainingAgencySeeder $seeder): int
    {
        $r = $this->option('reset') ? $seeder->reset() : $seeder->seed();
        $this->info(($r['created'] ? 'Created' : 'Found') . " the training agency (#{$r['agency']->id}); users: " . implode(', ', array_map(fn ($u) => $u->email, $r['users'])));

        return self::SUCCESS;
    }
}
