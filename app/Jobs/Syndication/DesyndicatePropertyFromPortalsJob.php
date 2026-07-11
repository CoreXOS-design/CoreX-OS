<?php

declare(strict_types=1);

namespace App\Jobs\Syndication;

use App\Models\AgencyApiKey;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Take a property OFF the syndication portals — Property24, Private Property and
 * (optionally) the agency website(s).
 *
 * Dispatched when a listing goes off-market: mandate expiry (always remove from
 * the website) and manual status changes via PropertyObserver. For sold/rented
 * the agency still showcases the listing on its website, so `removeFromWebsite`
 * is false there; for withdrawn/expired/cancelled it is true.
 *
 * P24 status on a manual change is set per-status by PropertyObserver's own
 * status-sync (Sold/Withdrawn/Expired…); this job's P24 step is the safety net
 * that catches anything that path missed (guard skips an already-deactivated
 * row, so the two never conflict).
 *
 * A dedicated Job (rather than a queued listener) is used because the
 * MandateExpired domain event's readonly properties cannot be restored by
 * SerializesModels on a queued listener — a Job serialising only the Property
 * queues and retries cleanly. Every portal step is failure-isolated; guards key
 * off current syndication status so retries never double-delist.
 *
 * Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md,
 *        .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-1)
 * Non-Negotiable #9 — cross-pillar reactivity (Mandate/Property → Syndication).
 */
class DesyndicatePropertyFromPortalsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Property $property,
        public readonly bool $removeFromWebsite = true,
    ) {
    }

    /** Exponential-ish backoff between retries (seconds). */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $property = $this->property;
        $failures = [];

        $this->delistProperty24($property, $failures);
        $this->delistPrivateProperty($property, $failures);
        if ($this->removeFromWebsite) {
            $this->delistWebsites($property, $failures);
        }

        if (! empty($failures)) {
            Log::error("DesyndicatePropertyFromPortalsJob: partial failure for property #{$property->id}", [
                'property_id'        => $property->id,
                'remove_from_website' => $this->removeFromWebsite,
                'failures'           => $failures,
            ]);
            // Re-throw so the queue retries (idempotent guards make re-runs safe).
            throw new \RuntimeException(
                "Desyndication failed for property #{$property->id}: " . implode(' | ', $failures)
            );
        }
    }

    /**
     * Property24. Skipped only when the listing is known to be off the portal
     * ('deactivated'). PropertyObserver's status-sync normally pushes the
     * per-status P24 value; this is the safety net for when that path did not run
     * (e.g. p24_syndication_enabled toggled off while a live p24_ref remains, or a
     * Sold push that left the listing on the portal) and the retry path for a
     * previous failed attempt ('error'). A repeat Withdrawn is harmless.
     */
    private function delistProperty24(Property $property, array &$failures): void
    {
        if (! $property->mayBeLiveOnP24()) {
            return;
        }

        try {
            $result = app(Property24SyndicationService::class)->deactivateListing($property);
            if (! ($result['success'] ?? false)) {
                $failures[] = 'p24:' . ($result['message'] ?? 'unknown error');
            }
        } catch (\Throwable $e) {
            $failures[] = 'p24:' . $e->getMessage();
            Log::channel('property24')->error("Off-market P24 delist failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Private Property. Mirrors the controller's "is live" definition: we hold a
     * pp_ref and nothing has told us the listing left the portal. Includes 'error'
     * and 'pending' so a previously failed or in-flight attempt still retries.
     */
    private function delistPrivateProperty(Property $property, array &$failures): void
    {
        if (! $property->pp_syndication_enabled || ! $property->mayBeLiveOnPp()) {
            return;
        }

        try {
            $result = app(PrivatePropertySyndicationService::class)->deactivateListing($property);
            if (! ($result['success'] ?? false)) {
                $failures[] = 'pp:' . ($result['message'] ?? 'unknown error');
            }
        } catch (\Throwable $e) {
            $failures[] = 'pp:' . $e->getMessage();
            Log::channel('private_property')->error("Off-market PP delist failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Agency website(s). Disable every enabled per-(property × website) pivot so
     * the public feed stops serving the listing and a listing.removed webhook
     * fans out to each site. Only currently-enabled rows are touched, so a retry
     * skips already-removed sites.
     */
    private function delistWebsites(Property $property, array &$failures): void
    {
        $rows = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)
            ->where('enabled', true)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $service = app(WebsiteSyndicationService::class);

        foreach ($rows as $row) {
            try {
                $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->find($row->agency_api_key_id);
                if ($key === null) {
                    // No key behind this pivot — disable the row directly so the
                    // feed (which joins on enabled=true) stops serving it.
                    $row->forceFill([
                        'enabled' => false,
                        'status'  => PropertyWebsiteSyndication::STATUS_DEACTIVATED,
                    ])->save();
                    continue;
                }
                $service->setEnabled($property, $key, false);
            } catch (\Throwable $e) {
                $failures[] = "website#{$row->agency_api_key_id}:" . $e->getMessage();
                Log::error("Off-market website delist failed for property #{$property->id}, key #{$row->agency_api_key_id}: {$e->getMessage()}");
            }
        }
    }
}
