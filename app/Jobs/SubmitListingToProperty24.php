<?php

namespace App\Jobs;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubmitListingToProperty24 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(public Property $property) {}

    public function handle(Property24SyndicationService $service): void
    {
        $service->submitListing($this->property);
    }
}
