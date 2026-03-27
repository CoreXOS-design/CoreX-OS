<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Console\Command;

class P24SyndicateSubmit extends Command
{
    protected $signature = 'p24:syndicate-submit {property : The property ID to submit}';
    protected $description = 'Submit a property listing to Property24 via ExDev API';

    public function handle(Property24SyndicationService $service): int
    {
        $property = Property::find($this->argument('property'));
        if (!$property) { $this->error("Property not found."); return self::FAILURE; }

        $this->info("Submitting property #{$property->id}: {$property->title}");
        $result = $service->submitListing($property);

        if ($result['success']) {
            $this->info('Submission successful! Status: ' . ($result['status'] ?? 'submitted') . ' P24 Ref: ' . ($result['p24_ref'] ?? 'pending'));
        } else {
            $this->error('Submission failed: ' . $result['message']);
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
