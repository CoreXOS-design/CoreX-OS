<?php

namespace App\Jobs;

use App\Models\Agency;
use App\Models\CommandCenter\CommandTask;
use App\Models\PpEventFeedSetting;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
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
        // Every enabled PP agency gets its own feed drain, bound to its branch
        // GUID/token, with its OWN continuation cursor. Previously this ran once
        // with no agency bound — config fell back to the first enabled agency, so
        // every other agency's branch was never polled (their pp_ref / feed refs
        // never populated; image-error tasks never fired). PP-2.
        $agencies = Agency::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('pp_enabled', true)
            ->whereNotNull('pp_branch_guid')
            ->get();

        if ($agencies->isEmpty()) {
            // Single-tenant / env-credential fallback: drain once with no
            // explicit agency (config resolves env or the lone agency).
            $client->forAgency(null);
            $this->drainFeed($client, 'continuation_key');
            return;
        }

        foreach ($agencies as $agency) {
            $client->forAgency($agency);
            // Per-branch cursor so agencies don't share/clobber one key.
            $this->drainFeed($client, 'continuation_key:' . $agency->pp_branch_guid);
        }
    }

    /**
     * Drain one branch's event feed to exhaustion, advancing $cursorKey as PP
     * issues continuation keys.
     */
    private function drainFeed(PrivatePropertySoapClient $client, string $cursorKey): void
    {
        $moreToProcess = true;
        $safetyLimit   = 50; // hard cap on inner pages per run

        while ($moreToProcess && $safetyLimit-- > 0) {
            $key           = PpEventFeedSetting::getValue($cursorKey);
            $startDateTime = null;

            if (empty($key)) {
                // First poll: PP honours startDateTime ONLY when no continuation
                // key is supplied. Send an EMPTY key (not a literal '0', which PP
                // treats as a real key and ignores the backfill window) so the
                // 2-day catch-up actually applies.
                $key           = '';
                $startDateTime = now()->subDays(2)->format('Y-m-d\TH:i:s\Z');
            }

            $response = $client->getListingEventFeed($key, $startDateTime);

            if (isset($response['error']) && $response['error'] === true) {
                Log::channel('private_property')->error('Event feed: SOAP error', $response);
                return;
            }

            // PP wraps the entire payload in a <GetListingEventFeedByBranchResult>
            // envelope — ContinuationKey AND FeedData live INSIDE it, not at the
            // top level. Reading them at the top level (the old bug) meant the
            // continuation key was always null, processEvents() never ran, and
            // pp_listing_feed_ref was never populated for any listing. Unwrap
            // once, defensively falling back to the top level.
            $result = $response['GetListingEventFeedByBranchResult'] ?? $response;

            $newKey = $result['ContinuationKey'] ?? $result['continuationKey'] ?? null;
            $events = $this->extractEvents($result);

            // Process EVERY page that carries events, independent of whether the
            // continuation key advanced. PP commonly repeats (or omits) the key
            // on the final page; gating processing on key-advance silently
            // dropped that page's activations/image-errors. processEvents is
            // idempotent (status writes are repeat-safe; the image-error task is
            // de-duped), so processing before persisting the cursor is safe.
            if (!empty($events)) {
                $this->processEvents($events);
            }

            // Advance the saved cursor only when PP issued a new key.
            if ($newKey && $newKey !== $key) {
                PpEventFeedSetting::setValue($cursorKey, (string) $newKey);
            }

            // Stop when the feed is drained (<100 = last page) OR the key did not
            // advance (no further page to fetch — re-fetching the same key would
            // loop on identical data).
            if (count($events) < 100 || !$newKey || $newKey === $key) {
                $moreToProcess = false;
            }
        }
    }

    private function extractEvents(array $result): array
    {
        $feed = $result['FeedData'] ?? $result['feedData'] ?? [];
        if (!is_array($feed)) {
            return [];
        }

        // PP nests the event list under a MIS-SPELLED child element:
        //   FeedData.LisitngEventFeedData = [ {event}, {event}, ... ]
        // ("Lisitng", not "Listing" — verified against the live sandbox feed).
        // SoapClient→json collapses a single child to an associative array
        // instead of a list, so normalise to a list either way. Accept the
        // corrected spelling and the legacy key too, so this keeps working
        // if PP ever fixes the typo.
        foreach (['LisitngEventFeedData', 'ListingEventFeedData', 'ListingFeedEvent'] as $childKey) {
            if (isset($feed[$childKey])) {
                $events = $feed[$childKey];
                if (!is_array($events)) {
                    return [];
                }
                return array_is_list($events) ? $events : [$events];
            }
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
        // FIELD ROLES (verified against the live sandbox feed):
        //   ListingFeedRef ($feedRef) = the listing reference WE submitted,
        //       i.e. our CoreX property id (e.g. "16").
        //   OfficeFeedRef  ($ourRef)  = the PP branch GUID (NOT our id).
        // The old code had these inverted — it looked our property up by
        // OfficeFeedRef and never matched. Match on ListingFeedRef first.
        if ($feedRef !== null && $feedRef !== '' && is_numeric($feedRef)) {
            $p = Property::find((int) $feedRef);
            if ($p) return $p;
        }
        if ($feedRef) {
            $p = Property::where('pp_listing_feed_ref', $feedRef)->first();
            if ($p) return $p;
        }
        // Backward-compat: the legacy assumption that OfficeFeedRef carried
        // our id. Harmless now (the branch GUID isn't numeric) but kept in
        // case PP ever changes the contract back.
        if ($ourRef !== null && $ourRef !== '' && is_numeric($ourRef)) {
            return Property::find((int) $ourRef);
        }
        return null;
    }

    private function createImageErrorTask(Property $property, ?string $description): void
    {
        if (!$property->agent_id) return;

        // Idempotent: don't stack duplicate tasks if the same image-error event
        // is reprocessed (e.g. the cursor save was retried). One open task per
        // property+source is enough.
        // SoftDeletes already excludes trashed rows; exclude closed statuses too.
        $existing = CommandTask::where('source_type', 'private_property_event_feed')
            ->where('source_id', $property->id)
            ->whereNotIn('status', [CommandTask::STATUS_DONE, CommandTask::STATUS_DISMISSED])
            ->exists();
        if ($existing) {
            return;
        }

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
