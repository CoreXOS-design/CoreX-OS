<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadListingThumbnail;
use App\Jobs\Prospecting\GeocodeTrackedPropertyAddressesJob;
use App\Models\ProspectingListing;
use App\Models\ProspectingPriceAnomaly;
use App\Models\ProspectingPriceHistory;
use App\Models\ProspectingSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProspectingApiController extends Controller
{
    public function import(Request $request)
    {
        $validated = $request->validate([
            'source'                       => 'required|in:p24,pp',
            'search_context'               => 'required|array',
            'search_context.url'           => 'required|string',
            'search_context.search_term'   => 'required|string',
            'search_context.total_results' => 'required|integer',
            'search_context.pages_captured'=> 'required|integer',
            'listings'                     => 'required|array|min:1',
            'listings.*.portal_ref' => 'nullable|string',
            'listings.*.address'           => 'nullable|string',
            'listings.*.price'             => 'nullable|integer',
            'listings.*.portal_url'        => 'nullable|string',
            'listings.*.suburb'            => 'nullable|string',
            'listings.*.district'          => 'nullable|string',
            'listings.*.bedrooms'          => 'nullable|integer',
            'listings.*.bathrooms'         => 'nullable|integer',
            'listings.*.garages'           => 'nullable|integer',
            'listings.*.property_size_m2'  => 'nullable|numeric',
            'listings.*.erf_size_m2'       => 'nullable|numeric',
            'listings.*.property_type'     => 'nullable|string',
            'listings.*.agent_name'        => 'nullable|string',
            'listings.*.agency_name'       => 'nullable|string',
            'listings.*.thumbnail_url'     => 'nullable|string',
            // BUG 3 — optional mandate signal. Only trustworthy once the
            // extension reads it off the P24 listing page itself (see report);
            // absent for every capture until that extension change ships.
            'listings.*.mandate_type'      => 'nullable|string|in:sole,exclusive,open,joint',
        ]);

        // AT-253 (STANDARDS Rule 17) — prospecting_searches.agency_id is NOT NULL and this is a
        // silent JSON endpoint (the Chrome capture extension), so a wrong-tenant write here is
        // never seen by a human. The old `?? 1` filed another agency's captured prospecting
        // data into AGENCY 1's intelligence. Refuse instead, as a clean 422 the extension can
        // surface, and use ?-> so an unauthenticated call cannot 500 on a null receiver.
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        if (! $agencyId) {
            throw new \App\Exceptions\MissingAgencyContextException('a prospecting capture');
        }
        $portalSource = $validated['source'];
        $context = $validated['search_context'];
        $now = Carbon::now();

        // Upsert: if same search_url exists for today, update it instead of creating a duplicate
        $search = ProspectingSearch::where('agency_id', $agencyId)
            ->where('search_url', $context['url'])
            ->whereDate('captured_at', $now->toDateString())
            ->first();

        if ($search) {
            $search->update([
                'total_results'      => $context['total_results'],
                'pages_captured'     => max($search->pages_captured, $context['pages_captured']),
                'search_description' => $context['search_term'],
            ]);
        } else {
            $search = ProspectingSearch::create([
                'agency_id'          => $agencyId,
                'user_id'            => $user->id,
                'portal_source'      => $portalSource,
                'search_url'         => $context['url'],
                'search_description' => $context['search_term'],
                'total_results'      => $context['total_results'],
                'pages_captured'     => $context['pages_captured'],
                'listing_count'      => 0,
                'captured_at'        => $now,
            ]);
        }

        $imported = 0;
        $updated = 0;

        // GEO-SCRAPE — collect TP ids touched by this batch so we can dispatch
        // ONE async geocode job at the end (not N jobs). Filtering down to
        // "needs GPS" happens inside the job to keep this hot path query-free.
        $touchedTrackedPropertyIds = [];

        foreach ($validated['listings'] as $data) {
            if (empty($data['portal_ref'])) {
                \Log::debug('Skipped listing with no portal_ref', ['data' => $data]);
                continue;
            }

            // Truncate strings to column max lengths — defence in depth.
            // Address: store a true NULL when the tile has no street address (blank
            // or the legacy "Address not available" placeholder) so address-less
            // listings land as NULL. The MIC "with address only" filter treats NULL
            // and '' the same, but NULL is the honest value. (Column made nullable.)
            $addrRaw = trim((string) ($data['address'] ?? ''));
            $data['address']       = ($addrRaw === '' || $addrRaw === 'Address not available')
                                     ? null : substr($addrRaw, 0, 255);
            $data['suburb']        = substr($data['suburb'] ?? '', 0, 100);
            $data['district']      = substr($data['district'] ?? '', 0, 100);
            $data['property_type'] = substr($data['property_type'] ?? '', 0, 50);
            $data['agent_name']    = substr($data['agent_name'] ?? '', 0, 100);
            $data['agency_name']   = substr($data['agency_name'] ?? '', 0, 100);

            $existing = ProspectingListing::where('agency_id', $agencyId)
                ->where('portal_source', $portalSource)
                ->where('portal_ref', $data['portal_ref'])
                ->first();

            if ($existing) {
                $existing->last_seen_at = $now;
                $existing->is_active = true;

                // Price-on-application / vacant land: portals send no price at all.
                // Normalise here rather than casting to (int), which would coerce a
                // null (POA) to 0 and either mask a real change or wrongly log one.
                $newPrice = $data['price'] !== null ? (int) $data['price'] : null;

                if ($newPrice !== $existing->price) {
                    if ($newPrice !== null && $existing->price !== null
                        && $this->isImplausiblePriceJump((int) $existing->price, $newPrice)) {
                        // MIC PRICE GUARD — an order-of-magnitude jump vs the stored
                        // price is a misparse (dropped zero / wrong figure grabbed),
                        // not a real market move. Quarantine it for review and KEEP
                        // the good price. A misparse can never silently overwrite MIC.
                        $this->flagPriceAnomaly($existing, (int) $existing->price, $newPrice, $portalSource, $context);
                        // Leave $existing->price and price_changed_at untouched.
                    } else {
                        ProspectingPriceHistory::create([
                            'prospecting_listing_id' => $existing->id,
                            'old_price'              => $existing->price,
                            'new_price'              => $newPrice,
                            'changed_at'             => $now,
                        ]);

                        $existing->price = $newPrice;
                        $existing->price_changed_at = $now;
                    }
                }

                $existing->address          = $data['address'];
                $existing->suburb           = $data['suburb'] ?? $existing->suburb;
                $existing->district         = $data['district'] ?? $existing->district;
                $existing->bedrooms         = $data['bedrooms'] ?? $existing->bedrooms;
                $existing->bathrooms        = $data['bathrooms'] ?? $existing->bathrooms;
                $existing->garages          = $data['garages'] ?? $existing->garages;
                $existing->property_size_m2 = $data['property_size_m2'] ?? $existing->property_size_m2;
                $existing->erf_size_m2      = $data['erf_size_m2'] ?? $existing->erf_size_m2;
                $existing->property_type    = $data['property_type'] ?? $existing->property_type;
                $existing->agent_name       = $data['agent_name'] ?? $existing->agent_name;
                $existing->agency_name      = $data['agency_name'] ?? $existing->agency_name;
                $existing->portal_url       = $data['portal_url'];
                $existing->mandate_type     = $data['mandate_type'] ?? $existing->mandate_type;

                $existing->save();

                // AT-22 item 7 — dispatch-on-update. The download job
                // historically only fired on the CREATE branch, so a listing
                // first seen WITHOUT a thumbnail_url (then later captured with
                // one) never retried, and rows orphaned by the Laravel 11
                // disk-root move never re-fetched. Re-dispatch when we have a
                // source URL AND no thumbnail is cached yet. Guarding on
                // empty(thumbnail_path) keeps us from re-downloading on every
                // capture of an already-thumbnailed row.
                if (!empty($data['thumbnail_url']) && empty($existing->thumbnail_path)) {
                    // thumbnail_source_url is fillable; set + save explicitly
                    // here so the source URL persists for future rehydration.
                    $existing->thumbnail_source_url = $data['thumbnail_url'];
                    $existing->save();
                    DownloadListingThumbnail::dispatch($existing, $data['thumbnail_url']);
                }

                $this->assignPropertyGroup($existing, $agencyId);
                $tpId = $this->linkToTrackedProperty($existing, $agencyId, $user->id);
                if ($tpId !== null) {
                    $touchedTrackedPropertyIds[$tpId] = true;
                }
                $updated++;
            } else {
                $listing = ProspectingListing::create([
                    'agency_id'           => $agencyId,
                    'captured_by_user_id' => $user->id,
                    'portal_source'       => $portalSource,
                    'portal_ref'          => $data['portal_ref'],
                    'portal_url'          => $data['portal_url'],
                    'address'             => $data['address'],
                    'suburb'              => $data['suburb'] ?? '',
                    'price'               => $data['price'],
                    'district'            => $data['district'] ?? null,
                    'bedrooms'            => $data['bedrooms'] ?? null,
                    'bathrooms'           => $data['bathrooms'] ?? null,
                    'garages'             => $data['garages'] ?? null,
                    'property_size_m2'    => $data['property_size_m2'] ?? null,
                    'erf_size_m2'         => $data['erf_size_m2'] ?? null,
                    'property_type'       => $data['property_type'] ?? null,
                    'agent_name'          => $data['agent_name'] ?? null,
                    'agency_name'         => $data['agency_name'] ?? null,
                    'mandate_type'        => $data['mandate_type'] ?? null,
                    'first_seen_at'       => $now,
                    'last_seen_at'        => $now,
                    'is_active'           => true,
                ]);

                $this->assignPropertyGroup($listing, $agencyId);
                $tpId = $this->linkToTrackedProperty($listing, $agencyId, $user->id);
                if ($tpId !== null) {
                    $touchedTrackedPropertyIds[$tpId] = true;
                }

                if (!empty($data['thumbnail_url'])) {
                    // Persist the source URL up-front (AT-22 item 7) so a
                    // later rehydrate can re-fetch even if the job's own
                    // write is lost. Direct attribute set + save() bypasses
                    // $fillable for the new column.
                    $listing->thumbnail_source_url = $data['thumbnail_url'];
                    $listing->save();
                    DownloadListingThumbnail::dispatch($listing, $data['thumbnail_url']);
                }

                $imported++;
            }
        }

        $search->update([
            'listing_count' => $search->listing_count + $imported + $updated,
        ]);

        // GEO-SCRAPE — dispatch ONE async geocode job for the batch. The job
        // filters down to TPs that actually need GPS, then resolves up to the
        // daily cap. Always wrapped in try/catch: a queue-dispatch hiccup
        // MUST NOT break the scrape ingestion response. Worst case, no job
        // gets queued and the next scrape (or the nightly backfill) picks
        // up the unresolved rows.
        try {
            if (!empty($touchedTrackedPropertyIds)) {
                GeocodeTrackedPropertyAddressesJob::dispatch(
                    array_keys($touchedTrackedPropertyIds),
                    (string) Str::uuid(),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('GeocodeTrackedPropertyAddressesJob dispatch failed (swallowed)', [
                'tracked_property_count' => count($touchedTrackedPropertyIds),
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success'  => true,
            'imported' => $imported,
            'updated'  => $updated,
            'total'    => $imported + $updated,
        ]);
    }

    /**
     * MIC price guard threshold. A capture whose price is >= this factor times the
     * stored price (or <= stored / factor) vs the SAME listing ref is treated as a
     * misparse and quarantined, not applied.
     *
     * Factor 4 chosen from the live corruption of 2026-08-10: every bad overwrite
     * was >= ~4.1x (dropped-zero = 10x; wrong-figure grabs = 5x–44x), while every
     * credible market move that day was <= ~2x and the normal cluster was within
     * ±20%. So 4 cleanly catches order-of-magnitude misparses yet leaves real price
     * changes — even a hefty ~3x relist — to import normally.
     */
    private const PRICE_JUMP_FACTOR = 4;

    /**
     * True when moving from $old to $new is an implausible order-of-magnitude jump.
     * Only judged when BOTH sides are positive (a first sighting has no baseline).
     */
    private function isImplausiblePriceJump(int $old, int $new): bool
    {
        if ($old <= 0 || $new <= 0) {
            return false;
        }
        return $new >= $old * self::PRICE_JUMP_FACTOR
            || $new * self::PRICE_JUMP_FACTOR <= $old;
    }

    /**
     * Record a quarantined price write for review and log it. The good stored price
     * is deliberately left untouched by the caller.
     */
    private function flagPriceAnomaly(
        ProspectingListing $listing,
        int $old,
        int $new,
        string $portalSource,
        array $context
    ): void {
        $factor = $new >= $old
            ? round($new / max(1, $old), 2)
            : -1 * round($old / max(1, $new), 2);

        try {
            ProspectingPriceAnomaly::create([
                'prospecting_listing_id' => $listing->id,
                'agency_id'              => $listing->agency_id,
                'portal_source'          => $portalSource,
                'portal_ref'             => $listing->portal_ref,
                'stored_price'           => $old,
                'rejected_price'         => $new,
                'jump_factor'            => $factor,
                'search_url'             => $context['url'] ?? null,
                'status'                 => 'pending',
            ]);
        } catch (\Throwable $e) {
            // Never let flagging break ingestion — the log line below is the backstop.
            \Log::warning('Price-anomaly flag insert failed', [
                'prospecting_listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('property24')->warning(
            'MIC PRICE GUARD — implausible jump QUARANTINED (stored price kept, not overwritten)',
            [
                'prospecting_listing_id' => $listing->id,
                'portal_source'          => $portalSource,
                'portal_ref'             => $listing->portal_ref,
                'stored_price'           => $old,
                'rejected_price'         => $new,
                'jump_factor'            => $factor,
                'threshold_factor'       => self::PRICE_JUMP_FACTOR,
            ]
        );
    }

    /**
     * Assign a property_group_id to link the same property across portals.
     */
    private function assignPropertyGroup(ProspectingListing $listing, int $agencyId): void
    {
        $normalized = ProspectingListing::normalizeAddress($listing->address, $listing->suburb);
        $listing->normalized_address = $normalized;

        if ($normalized) {
            // Find existing match from another portal
            $match = ProspectingListing::where('agency_id', $agencyId)
                ->where('normalized_address', $normalized)
                ->where('portal_source', '!=', $listing->portal_source)
                ->whereNotNull('property_group_id')
                ->first();

            if ($match) {
                $listing->property_group_id = $match->property_group_id;
            } else {
                $listing->property_group_id = $listing->id;
            }
        } else {
            $listing->property_group_id = $listing->id;
        }

        $listing->save();

        // Update any unmatched listings that now match
        if ($normalized) {
            ProspectingListing::where('agency_id', $agencyId)
                ->where('normalized_address', $normalized)
                ->whereNull('property_group_id')
                ->update(['property_group_id' => $listing->property_group_id]);
        }
    }

    /**
     * Universal Match-or-Create: every prospecting listing (P24 + PP, both Chrome-ext
     * captured) contributes to the Tracked Property universe. Failure-isolated so a
     * TP write blip never breaks the listing ingest.
     *
     * Returns the resolved TrackedProperty id (or null if matching failed) so the
     * import() loop can collect the batch's TP ids for a single async geocode
     * dispatch (GEO-SCRAPE).
     *
     * Spec: CLAUDE.md HARD RULE #10 (Universal Match-or-Create Rule, 2026-05-14).
     */
    private function linkToTrackedProperty(ProspectingListing $listing, int $agencyId, ?int $actorUserId): ?int
    {
        try {
            $service = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);

            // Street parsing best-effort. The matcher tolerates nulls — when a P24
            // alert hides the address, source-ref matching (portal_source + portal_ref)
            // is the dominant signal anyway.
            $streetNumber = null;
            $streetName   = null;
            $addr = trim((string) ($listing->address ?? ''));
            if ($addr !== '' && $addr !== 'Address not available'
                && preg_match('/^(\d+\w*)\s+(.+)$/', $addr, $m)) {
                $streetNumber = $m[1];
                $streetName   = $m[2];
            }

            $tp = $service->matchOrCreate(
                agencyId: $agencyId,
                facts: array_filter([
                    'address'                 => $addr !== '' && $addr !== 'Address not available' ? $addr : null,
                    'street_number'           => $streetNumber,
                    'street_name'             => $streetName,
                    'suburb'                  => $listing->suburb !== '' ? $listing->suburb : null,
                    'property_type'           => $listing->property_type,
                    'bedrooms'                => $listing->bedrooms,
                    'bathrooms'               => $listing->bathrooms,
                    'garages'                 => $listing->garages,
                    'floor_size_m2'           => $listing->property_size_m2,
                    'erf_size_m2'             => $listing->erf_size_m2,
                    'last_known_asking_price' => $listing->price,
                ], fn ($v) => $v !== null && $v !== ''),
                source: [
                    'type'    => (string) $listing->portal_source,
                    'ref'     => (string) $listing->portal_ref,
                    'payload' => ['prospecting_listing_id' => $listing->id],
                ],
                actorUserId: $actorUserId,
            );

            if ($tp && (int) ($listing->tracked_property_id ?? 0) !== (int) $tp->id) {
                $listing->tracked_property_id = $tp->id;
                $listing->save();
            }

            return $tp ? (int) $tp->id : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TrackedProperty link from prospecting ingest failed', [
                'prospecting_listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a search URL was already captured today.
     * Used by the Chrome extension for duplicate capture protection.
     */
    public function checkSearch(Request $request)
    {
        $request->validate([
            'search_url' => 'required|string',
        ]);

        // AT-253 (STANDARDS Rule 17) — prospecting_searches.agency_id is NOT NULL and this is a
        // silent JSON endpoint (the Chrome capture extension), so a wrong-tenant write here is
        // never seen by a human. The old `?? 1` filed another agency's captured prospecting
        // data into AGENCY 1's intelligence. Refuse instead, as a clean 422 the extension can
        // surface, and use ?-> so an unauthenticated call cannot 500 on a null receiver.
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        if (! $agencyId) {
            throw new \App\Exceptions\MissingAgencyContextException('a prospecting capture');
        }

        $existing = ProspectingSearch::where('agency_id', $agencyId)
            ->where('search_url', $request->search_url)
            ->whereDate('captured_at', Carbon::today())
            ->latest('captured_at')
            ->first();

        if ($existing) {
            $ago = $existing->captured_at->diffForHumans();
            return response()->json([
                'duplicate'     => true,
                'captured_ago'  => $ago,
                'listing_count' => $existing->listing_count,
                'search_id'     => $existing->id,
            ]);
        }

        return response()->json(['duplicate' => false]);
    }
}
