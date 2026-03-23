<?php

namespace App\Jobs;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPrivatePropertyActivations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PrivatePropertySyndicationService $syndicationService): void
    {
        $properties = Property::where('pp_syndication_enabled', true)
            ->where('pp_syndication_status', 'submitted')
            ->whereNull('pp_ref')
            ->get();

        if ($properties->isEmpty()) {
            Log::channel('private_property')->info('PP activation poll: no pending properties to check.');
            return;
        }

        Log::channel('private_property')->info("PP activation poll: checking {$properties->count()} properties.");

        foreach ($properties as $property) {
            try {
                $result = $syndicationService->syncActivationStatus($property);

                Log::channel('private_property')->info("Property #{$property->id}: status={$result['status']}, pp_ref={$result['pp_ref']}");
            } catch (\Throwable $e) {
                Log::channel('private_property')->error("Property #{$property->id}: activation check failed — {$e->getMessage()}");
            }
        }
    }
}
