<?php

namespace App\Observers;

use App\Jobs\MatchPropertyJob;
use App\Jobs\RegenerateBuyerMatchesJob;
use App\Jobs\SubmitListingToProperty24;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\AutoEventService;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Support\Facades\Log;

class PropertyObserver
{
    /**
     * Ensure branch_id is populated on new properties.
     * Derives from agent's branch_id; falls back to agency's default branch.
     */
    public function creating(Property $property): void
    {
        if (!empty($property->branch_id)) {
            return;
        }

        // Try agent's branch
        if ($property->agent_id) {
            $agentBranch = \DB::table('users')->where('id', $property->agent_id)->value('branch_id');
            if ($agentBranch) {
                $property->branch_id = $agentBranch;
                return;
            }
        }

        // Try creator's branch
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user && $user->branch_id) {
            $property->branch_id = $user->branch_id;
            return;
        }

        // Fallback: agency's default branch
        $agencyId = $property->agency_id ?? ($user ? $user->effectiveAgencyId() : null);
        if ($agencyId) {
            $agency = \App\Models\Agency::withoutGlobalScopes()->find($agencyId);
            if ($agency && $agency->default_branch_id) {
                $property->branch_id = $agency->default_branch_id;
            } else {
                $property->branch_id = \App\Models\Branch::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->value('id') ?? 1;
            }
        }
    }

    /**
     * Reject owner-role users as listing agents. System Owners are
     * platform identities — they don't own properties, they supervise
     * every agency. This observer closes the write side; the read side
     * is `User::scopeAgencyMembers()`.
     */
    /** Static registry for pre-save originals (keyed by property ID) */
    private static array $auditOriginals = [];

    public function saving(Property $property): void
    {
        // Capture originals in static registry for audit diffing in saved()
        if ($property->exists && !$property->wasRecentlyCreated) {
            $auditFields = ['price', 'status', 'agent_id', 'compliance_snapshot_at', 'published_at', 'mandate_type'];
            $captured = [];
            foreach ($auditFields as $f) {
                if ($property->isDirty($f)) {
                    $captured[$f] = $property->getOriginal($f);
                }
            }
            if (!empty($captured)) {
                self::$auditOriginals[$property->id] = $captured;
            }
        }

        // AT-266 — ONE TRUTH for the address.
        //
        // `address` is DERIVED from the structured address columns, exactly as
        // title_type is derived from property_type immediately below. The two-copy
        // era ends here: an agent edits the parts (street / complex / unit) and the
        // display string follows, atomically, in the same save. It can no longer sit
        // frozen while the parts move underneath it.
        //
        // Only fires when a part actually changed, so a save that touches price or
        // status never rewrites the address of a row nobody asked us to touch. And
        // an empty composition is ignored rather than blanking a row we know nothing
        // better about.
        $addressParts = ['street_number', 'street_name', 'complex_name', 'unit_number', 'unit_section_block'];
        $partsDirty = !$property->exists;
        foreach ($addressParts as $part) {
            if ($property->isDirty($part)) {
                $partsDirty = true;
                break;
            }
        }
        if ($partsDirty) {
            $composed = $property->composeAddressFromParts();
            if ($composed !== '') {
                $property->address = $composed;
            }
        }

        // Keystone — derive title_type whenever the inputs change OR
        // when the column is currently NULL (self-heal for rows that
        // pre-date the backfill). Fires on insert OR when property_type
        // / category shift. Leaves NULL when both fail so the
        // presentation generator gate can reject the row with a
        // user-facing message rather than guessing.
        $titleTypeDirty = !$property->exists
            || $property->isDirty('property_type')
            || $property->isDirty('category')
            || $property->title_type === null;
        if ($titleTypeDirty) {
            $classifier = app(\App\Services\TitleTypeClassifier::class);
            $derived = $classifier->forProperty($property);
            if ($derived === null) {
                \Illuminate\Support\Facades\Log::info('[PRES-WARN] property saved with no derivable title_type', [
                    'property_id'   => $property->id,
                    'property_type' => $property->property_type,
                    'category'      => $property->category,
                ]);
            }
            $property->title_type = $derived;
        }

        if (!$property->agent_id) {
            return;
        }

        $ownerRoleNames = User::ownerRoleNames();
        if (empty($ownerRoleNames)) {
            return;
        }

        $agentRole = \DB::table('users')->where('id', $property->agent_id)->value('role');
        if ($agentRole && in_array($agentRole, $ownerRoleNames, true)) {
            throw new \RuntimeException('System Owner accounts cannot be assigned as a property agent. Pick an agency member.');
        }
    }

    /**
     * Fired when a property is first created.
     * Auto-generates document expectation tasks via Command Center.
     */
    public function created(Property $property): void
    {
        try {
            app(AutoEventService::class)->onPropertyCreated($property);
        } catch (\Throwable $e) {
            Log::warning("Command Center auto-event failed on property create #{$property->id}: {$e->getMessage()}");
        }

        // Audit: property created
        try {
            app(\App\Services\Audit\PropertyAuditService::class)->log(
                $property, 'property', 'property_created',
                humanSummary: 'Property created: ' . ($property->title ?? 'Untitled'),
            );
        } catch (\Throwable $e) {
            Log::warning("Audit log failed on property create #{$property->id}: {$e->getMessage()}");
        }

        // SPINE-3 — domain event for activity-points crediting. Actor is
        // the listing agent (set by creating() above). If no agent_id
        // resolves, actor = null and InstantPointService skips the
        // credit silently (covers system/import-created rows).
        try {
            event(new \App\Events\Property\PropertyCaptured(
                property: $property,
                actorUserId: $property->agent_id !== null ? (int) $property->agent_id : null,
            ));
        } catch (\Throwable $e) {
            Log::warning("SPINE-3 PropertyCaptured dispatch failed on property #{$property->id}: {$e->getMessage()}");
        }

        // AT-18 BUG-2: geocode on CREATE. updated() re-resolves GPS only on
        // UPDATE, so a property created with a complete address in a single
        // save never geocoded (lat/lng stay 0,0 → broken map + spatial view).
        // created() fires exactly once on INSERT, so the wasRecentlyCreated
        // lingering caveat the updated() docblock warns about does NOT apply
        // here. Mirrors updated()'s address-field set + fail-safe try/catch.
        try {
            $hasAddress = !empty($property->address)
                || !empty($property->street_number)
                || !empty($property->street_name)
                || !empty($property->suburb)
                || !empty($property->town);
            // Skip if lat/lng already set (e.g. map-drag on create) — never
            // overwrite. Uses the geocoder's own "has GPS" definition (0.0 = unset).
            $hasGps = $property->latitude !== null
                && $property->longitude !== null
                && (float) $property->latitude !== 0.0
                && (float) $property->longitude !== 0.0;
            if ($hasAddress && !$hasGps) {
                (new \App\Services\Geocoding\PropertyGeoBackfillService())
                    ->backfillProperty($property, batchId: null, force: true);
            }
        } catch (\Throwable $e) {
            Log::warning("Geocode-on-create failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Fired only on UPDATE (not INSERT). Re-resolve GPS when an address
     * field changed but lat/lng was NOT updated in the same save. Catches
     * every ingress path (form submit without Map widget open, import job,
     * API update, mass-assign) so building-level pins stay in sync with
     * the address columns.
     *
     * Skipped when lat/lng was explicitly changed in this save — frontend
     * / drag handler / explicit set wins. Not in saved() because
     * `wasRecentlyCreated` persists across subsequent saves on the same
     * in-memory instance, which would suppress the legitimate follow-up
     * UPDATE after a CREATE in the same request.
     */
    public function updated(Property $property): void
    {
        try {
            $addrFields = ['address', 'street_number', 'street_name', 'suburb', 'town'];
            $dirtyKeys = array_keys($property->getChanges());
            $addrDirty = (bool) array_intersect($dirtyKeys, $addrFields);
            $gpsDirty  = (bool) array_intersect($dirtyKeys, ['latitude', 'longitude']);
            if ($addrDirty && !$gpsDirty) {
                (new \App\Services\Geocoding\PropertyGeoBackfillService())
                    ->backfillProperty($property, batchId: null, force: true);
            }
        } catch (\Throwable $e) {
            Log::warning("Geocode-on-address-change failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Fired after create or update.
     * Only sync if the property has been published.
     */
    public function saved(Property $property): void
    {
        // AT-108 — stock changed → buyers' canonical Core Match counts may shift.
        // Queue an ASYNC, COALESCED recompute (Freshness Option B). Never sync —
        // bulk imports must stay fast; ShouldBeUnique + delay collapse a burst into
        // one per-agency recompute. Overnight `matches:recompute` is the backstop.
        $this->queueBuyerMatchRecompute($property);

        // Update last_activity_at for Command Center health tracking
        try {
            if ($property->wasRecentlyCreated === false) {
                app(AutoEventService::class)->onPropertyUpdated($property);
            }
        } catch (\Throwable $e) {
            Log::warning("Command Center activity update failed for property #{$property->id}: {$e->getMessage()}");
        }

        // Audit: track meaningful field changes
        if (!$property->wasRecentlyCreated) {
            try {
                $auditSvc = app(\App\Services\Audit\PropertyAuditService::class);
                $changes = $property->getChanges();

                $pre = self::$auditOriginals[$property->id] ?? [];
                unset(self::$auditOriginals[$property->id]);

                if (isset($changes['price']) && array_key_exists('price', $pre)) {
                    $auditSvc->logPriceChange($property, $pre['price'], $changes['price']);
                }
                if (isset($changes['status']) && array_key_exists('status', $pre)) {
                    $auditSvc->logStatusChange($property, $pre['status'], $changes['status']);
                }
                if (isset($changes['agent_id']) && array_key_exists('agent_id', $pre)) {
                    $newAgent = User::find($changes['agent_id']);
                    $auditSvc->log($property, 'property', 'agent_assigned',
                        oldValues: ['agent_id' => $pre['agent_id']],
                        newValues: ['agent_id' => $changes['agent_id']],
                        humanSummary: 'Listing agent changed to ' . ($newAgent->name ?? "Agent #{$changes['agent_id']}"),
                    );
                }
                if (isset($changes['compliance_snapshot_at']) && $changes['compliance_snapshot_at'] !== null && ($pre['compliance_snapshot_at'] ?? null) === null) {
                    $auditSvc->logComplianceSnapshot($property, snapshotData: $property->compliance_snapshot_data);
                    // SPINE-3 — first-time compliance snapshot is a
                    // genuine agent achievement; credit it. Per-property
                    // idempotency in InstantPointService prevents
                    // re-credit on subsequent snapshot refreshes.
                    try {
                        event(new \App\Events\Property\PropertyCompliancePassed(
                            property: $property,
                            actorUserId: $property->agent_id !== null ? (int) $property->agent_id : null,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning("SPINE-3 PropertyCompliancePassed dispatch failed on property #{$property->id}: {$e->getMessage()}");
                    }
                }
                if (isset($changes['published_at']) && $changes['published_at'] !== null && ($pre['published_at'] ?? null) === null) {
                    $auditSvc->log($property, 'syndication', 'website_published', humanSummary: 'Listing published');
                    // SPINE-3 — listing went live (first time or
                    // re-published after takedown). Same per-property
                    // idempotency rule prevents double-credit on
                    // same-day publish/unpublish toggling.
                    try {
                        event(new \App\Events\Property\PropertyPublished(
                            property: $property,
                            actorUserId: $property->agent_id !== null ? (int) $property->agent_id : null,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning("SPINE-3 PropertyPublished dispatch failed on property #{$property->id}: {$e->getMessage()}");
                    }
                }
                if (isset($changes['published_at']) && $changes['published_at'] === null && ($pre['published_at'] ?? null) !== null) {
                    $auditSvc->log($property, 'syndication', 'website_unpublished', humanSummary: 'Listing unpublished');
                }
            } catch (\Throwable $e) {
                Log::warning("Audit log failed on property save #{$property->id}: {$e->getMessage()}");
            }
        }

        // NOTE: the legacy single-site push-sync (SyncPropertyToWebsite, driven by
        // published_at → themandatecompany.co.za) was retired with the Agency
        // Public API. Websites now PULL via /api/v1/website/* and receive webhooks;
        // per-property visibility is the property_website_syndication pivot, not
        // published_at. See .ai/audits/legacy-web-portal-published-at-2026-06-02.md.

        // Core Matches — fire on create or on any criteria-affecting change.
        // Re-saves with no relevant change won't trigger duplicate notifications
        // because MatchPropertyJob dedups via contact_match_notifications.
        $matchSignals = [
            'price', 'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
            'suburb', 'city', 'category', 'property_type', 'listing_type',
            'status', 'features_json',
        ];
        if ($property->wasRecentlyCreated || array_intersect(array_keys($property->getChanges()), $matchSignals)) {
            try {
                MatchPropertyJob::dispatch($property->id);
            } catch (\Throwable $e) {
                Log::warning("MatchPropertyJob dispatch failed for property #{$property->id}: {$e->getMessage()}");
            }
        }

        // Agency Public API — when a listing that's live on one or more agency
        // websites changes a marketing-relevant field, fan a listing.updated
        // webhook out to those sites. Guarded (only syndicated listings) and
        // failure-isolated. Spec: .ai/specs/agency-public-api.md §6.1.
        $websiteSignals = [
            'price', 'price_on_application', 'status', 'title', 'headline', 'description',
            'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2', 'suburb', 'city',
            'province', 'address', 'features_json', 'images_json', 'gallery_images_json',
            'property_type', 'listing_type', 'agent_id',
        ];
        // No wasRecentlyCreated guard: a brand-new property has no enabled
        // syndication rows yet, so the isSyndicated check below already excludes
        // the create case — and wasRecentlyCreated lingers on a reused instance.
        if (array_intersect(array_keys($property->getChanges()), $websiteSignals)) {
            try {
                $isSyndicated = \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                    ->where('property_id', $property->id)->where('enabled', true)->exists();
                if ($isSyndicated) {
                    event(new \App\Events\Website\ListingSyndicationChanged($property, 'updated'));
                }
            } catch (\Throwable $e) {
                Log::warning("Website listing.updated dispatch failed for property #{$property->id}: {$e->getMessage()}");
            }
        }

        // Off-market delist (Private Property + agency website). When a listing
        // goes off-market, take it off PP and — for true removals only — the
        // website. P24 is handled per-status by the auto-sync block below
        // (Sold/Withdrawn/Expired…). Sold/rented STAY on the website (agencies
        // showcase sold stock); withdrawn/expired/cancelled/archived are removed
        // everywhere. The DesyndicatePropertyFromPortalsJob guards are idempotent,
        // so the rare double with the mandate-expiry cron path is harmless.
        // See .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-1).
        // No wasRecentlyCreated guard (it lingers on a reused instance — same
        // reasoning as the website webhook block above): the $onPortal check
        // already excludes brand-new, not-yet-syndicated stock.
        if (array_key_exists('status', $property->getChanges())
            && $this->isOffMarketStatus((string) $property->status)) {
            try {
                // Only dispatch when the property is actually on a portal or a
                // website. Skips pointless no-op jobs for never-syndicated stock.
                // P24 is normally delisted per-status by the auto-sync block below,
                // but that block is gated on p24_syndication_enabled — so a listing
                // toggled off while still live on P24 would never be withdrawn by
                // anything. mayBeLiveOnP24() makes the job the real safety net.
                $onPortal = $property->pp_syndication_enabled
                    || $property->mayBeLiveOnP24()
                    || \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                        ->where('property_id', $property->id)->where('enabled', true)->exists();
                if ($onPortal) {
                    \App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob::dispatch(
                        $property,
                        removeFromWebsite: $this->isWebsiteRemovalStatus((string) $property->status),
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("Off-market delist dispatch failed for property #{$property->id}: {$e->getMessage()}");
            }
        }

        // Prospecting stock match — find prospects that match this property.
        // Queued (not inline): the matcher scans every prospect with regex and
        // is too slow to run synchronously, especially during bulk creation
        // (sold properties import). Dispatched to `default` like MatchPropertyJob.
        // 'status' is included so an on→off-market transition (sold/withdrawn/
        // expired/…) re-runs the matcher, which then CLEARS the stale IN STOCK
        // badges and returns those listings to the prospectable pool. Address
        // fields drive the forward match as before.
        $stockMatchFields = ['address', 'suburb', 'street_name', 'street_number', 'status'];
        if ($property->wasRecentlyCreated || array_intersect(array_keys($property->getChanges()), $stockMatchFields)) {
            try {
                \App\Jobs\Prospecting\MatchPropertyProspectingJob::dispatch($property->id);
            } catch (\Throwable $e) {
                Log::warning("Prospecting stock match dispatch failed for property #{$property->id}: {$e->getMessage()}");
            }
        }

        // P24 syndication auto-sync
        if (!$property->p24_syndication_enabled || !$property->p24_ref) {
            return;
        }

        // MUST be getChanges(), not getDirty(). saved()'s first call
        // (onPropertyUpdated → updateQuietly(last_activity_at)) runs a nested
        // save that calls syncOriginal(), so getDirty() is already EMPTY by the
        // time we reach here — which silently killed ALL P24 status/field
        // auto-sync (sold/withdrawn/price/photo edits never reached P24).
        // getChanges() still carries the real change because it was captured
        // (and re-captured) during those saves. Matches every other change
        // check in saved(). Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md
        $dirty = $property->getChanges();

        // If status changed, send a lightweight status update to P24
        if (isset($dirty['status'])) {
            $p24Status = Property24ListingMapper::getP24Status($property->status, $property->p24_ref, $property->status_label);

            try {
                $agency = $property->agency ?? \App\Models\Agency::find($property->agency_id);
                $client = new Property24ApiClient($agency);
                $client->setListingStatus($property->id, (int) $property->p24_ref, $p24Status);

                Log::channel('property24')->info("Status auto-synced for property #{$property->id}: {$p24Status}");

                // Record the listing's lifecycle state ON the portal. Only the
                // statuses that actually remove it (Withdrawn/Expired/Cancelled)
                // may be written as 'deactivated' — that value is what every
                // delist guard reads as "already off the portal". Sold/Rented
                // stay listed, so they get their own state and remain delistable.
                if (Property24ListingMapper::removesFromPortal($p24Status)) {
                    $property->updateQuietly([
                        'p24_syndication_status' => Property::PORTAL_OFF_STATUS,
                    ]);
                } elseif (Property24ListingMapper::isTerminalStatus($p24Status)) {
                    $property->updateQuietly([
                        'p24_syndication_status' => strtolower($p24Status),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('property24')->error("Status sync failed for property #{$property->id}: {$e->getMessage()}");
            }

            return; // Don't also re-submit the full listing
        }

        // For non-status field changes, re-submit the full listing
        $syncFields = [
            'title', 'headline', 'description', 'price', 'price_on_application',
            'beds', 'baths', 'garages', 'size_m2', 'erf_size_m2',
            'street_name', 'street_number', 'suburb', 'city', 'province',
            'property_type', 'listing_type', 'mandate_type',
            'images_json', 'dawn_images_json', 'noon_images_json',
            'dusk_images_json', 'gallery_images_json',
            'latitude', 'longitude', 'complex_name', 'unit_number',
            'features_json', 'spaces_json',
            'rates_taxes', 'levy', 'special_levy',
            'deposit_amount', 'lease_period',
        ];

        $changed = array_intersect(array_keys($dirty), $syncFields);

        if (!empty($changed)) {
            SubmitListingToProperty24::dispatch($property);
        }
    }

    /**
     * Fired on soft-delete or force-delete.
     * Always tell the website to remove it if it was ever published.
     * Also withdraw the listing from P24.
     */
    /**
     * AT-108 (Freshness Option B) — queue an async, coalesced recompute of the
     * agency's buyers' canonical Core Match cache after a stock change. Reuses
     * the existing RegenerateBuyerMatchesJob (no parallel job); ShouldBeUnique
     * collapses a burst (bulk import) to one per-agency job; the 60s delay lets
     * an import settle before the (single) recompute runs. truncate:false because
     * recomputeForBuyer self-corrects each buyer's rows. Bounded staleness window
     * ≈ delay + queue latency; the overnight `matches:recompute` guarantees
     * exactness daily. Never throws into the save path.
     */
    private function queueBuyerMatchRecompute(Property $property): void
    {
        if (empty($property->agency_id)) {
            return;
        }
        try {
            RegenerateBuyerMatchesJob::dispatch(
                agencyId: (int) $property->agency_id,
                contactId: null,
                truncate: false,
            )->delay(now()->addSeconds(60));
        } catch (\Throwable $e) {
            Log::warning("Buyer-match recompute dispatch failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    public function deleted(Property $property): void
    {
        // AT-108 — archived stock no longer matches; recompute affected buyers (async, coalesced).
        $this->queueBuyerMatchRecompute($property);

        try {
            app(\App\Services\Audit\PropertyAuditService::class)->log(
                $property, 'property', 'property_archived',
                humanSummary: 'Property archived: ' . ($property->title ?? 'Untitled'),
            );
        } catch (\Throwable $e) {
            Log::warning("Audit log failed on property delete #{$property->id}: {$e->getMessage()}");
        }

        // Legacy push-sync removed with the Agency Public API — see the note in
        // saved() and .ai/audits/legacy-web-portal-published-at-2026-06-02.md.

        // Withdraw from P24 if syndicated
        if ($property->p24_syndication_enabled && $property->p24_ref) {
            try {
                $agency = $property->agency ?? \App\Models\Agency::find($property->agency_id);
                $client = new Property24ApiClient($agency);
                $client->setListingStatus($property->id, (int) $property->p24_ref, 'Withdrawn');
                Log::channel('property24')->info("Property #{$property->id} withdrawn from P24 (deleted)");
            } catch (\Exception $e) {
                Log::channel('property24')->error("P24 withdrawal failed for deleted property #{$property->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Off-market = no longer actively for sale / to let. These must come off the
     * portals. Substring match mirrors Property24ListingMapper::getP24Status so
     * status-string variants (e.g. "Sold", "sold • cash") all resolve.
     */
    private function isOffMarketStatus(string $status): bool
    {
        $s = strtolower($status);
        // Single source of truth — Property::OFF_MARKET_STATUSES (BUILD_STANDARD §6).
        // Substring match handles variants like "sold • cash"; the space form
        // covers underscored slugs (e.g. let_out / "let out").
        foreach (Property::OFF_MARKET_STATUSES as $needle) {
            if (str_contains($s, $needle) || str_contains($s, str_replace('_', ' ', $needle))) {
                return true;
            }
        }
        return false;
    }

    /**
     * True removals — the listing must come off the agency website too. Sold and
     * rented are deliberately EXCLUDED: agencies showcase sold/rented stock on
     * their websites (see WebsiteSyndicationService::bulkActivateSold).
     */
    private function isWebsiteRemovalStatus(string $status): bool
    {
        $s = strtolower($status);
        foreach (['withdrawn', 'expired', 'cancelled', 'archived', 'unavailable'] as $needle) {
            if (str_contains($s, $needle)) {
                return true;
            }
        }
        return false;
    }
}
