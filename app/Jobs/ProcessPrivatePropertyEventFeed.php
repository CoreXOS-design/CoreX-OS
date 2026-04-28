<?php

namespace App\Jobs;

use App\Models\CommandCenter\CommandTask;
use App\Models\PpEventFeedSetting;
use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPrivatePropertyEventFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(PrivatePropertySoapClient $client): void
    {
        $moreToProcess = true;
        $safetyLimit   = 50; // hard cap on inner pages per run

        while ($moreToProcess && $safetyLimit-- > 0) {
            $key           = PpEventFeedSetting::getValue('continuation_key');
            $startDateTime = null;

            if (empty($key)) {
                $key           = '0';
                $startDateTime = now()->subDays(2)->format('Y-m-d\TH:i:s\Z');
            }

            $response = $client->getListingEventFeed($key, $startDateTime);

            if (isset($response['error']) && $response['error'] === true) {
                Log::channel('private_property')->error('Event feed: SOAP error', $response);
                return;
            }

            // PP returns the new continuation key separately from the events.
            $newKey = $response['ContinuationKey'] ?? $response['continuationKey'] ?? null;
            $events = $this->extractEvents($response);

            if ($newKey && $newKey !== $key) {
                PpEventFeedSetting::setValue('continuation_key', (string) $newKey);
                $this->processEvents($events);
            }

            // PP returns up to 100 events per page; <100 means we've drained the feed.
            if (count($events) < 100) {
                $moreToProcess = false;
            }
        }
    }

    private function extractEvents(array $response): array
    {
        $feed = $response['FeedData'] ?? $response['feedData'] ?? [];
        if (!is_array($feed)) {
            return [];
        }

        // SoapClient → json round-trip can wrap a single child as an associative array
        // instead of a list. Normalise to a list of events.
        if (isset($feed['ListingFeedEvent'])) {
            $events = $feed['ListingFeedEvent'];
            return is_array($events) && array_is_list($events) ? $events : [$events];
        }

        return array_is_list($feed) ? $feed : [$feed];
    }

    private function processEvents(array $events): void
    {
        foreach ($events as $event) {
            if (!is_array($event)) continue;

            $type    = $event['ListingFeedEventType']   ?? null;
            $feedRef = $event['ListingFeedRef']         ?? null;
            $ourRef  = $event['OfficeFeedRef']          ?? null;
            $desc    = $event['EventDescription']       ?? null;

            $property = $this->findProperty($ourRef, $feedRef);

            switch ($type) {
                case 'Activated':
                    if (!$property) {
                        Log::channel('private_property')->warning('Event feed: Activated for unknown property', $event);
                        break;
                    }
                    $property->update([
                        'pp_ref'                => $desc,
                        'pp_listing_feed_ref'   => $feedRef,
                        'pp_syndication_status' => 'active',
                        'pp_activated_at'       => now(),
                        'pp_last_error'         => null,
                    ]);
                    Log::channel('private_property')->info("Event feed: property #{$property->id} Activated", [
                        'pp_ref' => $desc, 'pp_listing_feed_ref' => $feedRef,
                    ]);
                    break;

                case 'Deactivated':
                    if (!$property) break;
                    $property->update(['pp_syndication_status' => 'deactivated']);
                    Log::channel('private_property')->info("Event feed: property #{$property->id} Deactivated");
                    break;

                case 'ErrorDownloadingImages':
                    if (!$property) {
                        Log::channel('private_property')->warning('Event feed: image error for unknown property', $event);
                        break;
                    }
                    $property->update([
                        'pp_syndication_status' => 'error',
                        'pp_last_error'         => $desc ?: 'PP could not download images',
                    ]);
                    $this->createImageErrorTask($property, $desc);
                    Log::channel('private_property')->warning("Event feed: property #{$property->id} ErrorDownloadingImages", [
                        'desc' => $desc,
                    ]);
                    break;

                case 'ImagesDownloading':
                case 'ImagesDownloaded':
                    Log::channel('private_property')->info("Event feed: {$type}", [
                        'property_id' => $property?->id, 'feed_ref' => $feedRef,
                    ]);
                    break;

                default:
                    Log::channel('private_property')->info("Event feed: unknown event type '{$type}'", $event);
            }
        }
    }

    private function findProperty(mixed $ourRef, mixed $feedRef): ?Property
    {
        if ($ourRef !== null && $ourRef !== '' && is_numeric($ourRef)) {
            $p = Property::find((int) $ourRef);
            if ($p) return $p;
        }
        if ($feedRef) {
            return Property::where('pp_listing_feed_ref', $feedRef)->first();
        }
        return null;
    }

    private function createImageErrorTask(Property $property, ?string $description): void
    {
        if (!$property->agent_id) return;

        CommandTask::create([
            'title'         => 'PP image upload failed — ' . ($property->title ?? "Property #{$property->id}"),
            'description'   => 'Private Property could not download the images for this listing. '
                             . 'Re-check the photo URLs (must be HTTPS, ≤1MB each, reachable) and re-submit.'
                             . ($description ? "\n\nPP message: {$description}" : ''),
            'task_type'     => 'syndication_error',
            'status'        => CommandTask::STATUS_TODO,
            'priority'      => 'high',
            'send_reminder' => true,
            'assigned_to'   => $property->agent_id,
            'property_id'   => $property->id,
            'source_type'   => 'private_property_event_feed',
            'source_id'     => $property->id,
            'branch_id'     => $property->branch_id,
            'agency_id'     => $property->agency_id,
        ]);
    }
}
