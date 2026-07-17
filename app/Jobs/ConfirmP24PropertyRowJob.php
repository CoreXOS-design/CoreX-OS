<?php

namespace App\Jobs;

use App\Models\P24ImportRow;
use App\Models\Property;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Confirm a single pending P24 listing row into a Property.
 * - Creates or updates the Property
 * - Downloads images in order into storage/app/public/properties/{id}/{ordinal}.jpg
 * - Writes images_json
 * - Marks row confirmed, stores target_id
 */
class ConfirmP24PropertyRowJob implements ShouldQueue
{
    // Batchable is REQUIRED — Import All dispatches these via Bus::batch(), and
    // Bus::batch() throws "does not use the Batchable trait" at dispatch without
    // it. (Bus::fake() in tests does NOT enforce this, so the guard test below
    // asserts the trait directly.)
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The wide, cheap lane. A confirm is a DB write and no CDN call, so this
     * queue can fan out to many workers without touching P24. Image fetches
     * live on the separate, narrow `p24images` lane. A worker must drain
     * `p24import` or confirms strand. Set via onQueue() (not a redeclared
     * $queue property, which conflicts with the Queueable trait).
     */
    public function __construct(public int $rowId, public ?int $userId = null)
    {
        $this->onQueue('p24import');
    }

    public function handle(): void
    {
        $row = P24ImportRow::with(['run' => fn ($q) => $q->withTrashed()])->find($this->rowId);
        if (!$row || $row->row_type !== 'listing') return;
        if (in_array($row->status, ['confirmed', 'excluded'], true)) return;

        $run = $row->run;

        // The review run was cancelled or soft-deleted while this job waited in
        // the queue. Stop immediately rather than churning through every
        // remaining row in the background — a deleted review must not keep
        // processing. Without this guard each queued job loads a null run,
        // throws, and marks its row 'error' one at a time (thousands deep).
        if (!$run || $run->trashed() || in_array($run->status, ['cancelled', 'completed', 'failed'], true)) {
            if (!empty($row->processing_at)) {
                $row->update(['processing_at' => null]);
            }
            return;
        }

        $mapped = $row->mapped_json ?? [];
        $propertyId = null;
        $imageUrls = [];
        $skipImages = false;
        $galleryChanged = false;

        try {
            DB::transaction(function () use ($row, $mapped, $run, &$propertyId, &$imageUrls, &$skipImages, &$galleryChanged) {
                $listingNumber = $mapped['p24_listing_number'] ?? $row->external_id;

                $existing = Property::withoutGlobalScopes()
                    ->where('p24_listing_number', $listingNumber)
                    ->where('agency_id', $run->agency_id)
                    ->first();

                $fillable = [
                    'external_id', 'title', 'headline', 'description',
                    'listing_type', 'status', 'price', 'rental_amount',
                    'address', 'street_name', 'street_number',
                    'stand_number', 'unit_number',
                    'beds', 'baths', 'garages', 'erf_size_m2', 'size_m2',
                    'property_type', 'category', 'expiry_date',
                    'levy', 'special_levy', 'rates_taxes',
                    'latitude', 'longitude',
                    'youtube_video_id', 'matterport_id', 'eyespy_360_id',
                    'features_json', 'spaces_json', 'pet_friendly',
                    'lease_period', 'p24_listing_number',
                    // Fields the CSV carries that were previously dropped (audit
                    // run 10, 2026-07-17) — every P24 column now lands somewhere.
                    'occupation_date', 'source_reference', 'lightstone_id',
                    'development_id', 'p24_suburb_id', 'erf_area_unit', 'floor_area_unit',
                ];
                $attrs = [];
                foreach ($fillable as $k) {
                    if (array_key_exists($k, $mapped)) $attrs[$k] = $mapped[$k];
                }
                $attrs['agent_id']  = $row->resolved_agent_id;
                $attrs['agency_id'] = $run->agency_id;

                // branch_id is NOT NULL with no default. This job runs on the
                // queue with no auth user, so BelongsToBranch cannot auto-fill
                // it — leaving it null 1364s the whole confirm. Source it from
                // the property's own agent, falling back to the agency's first
                // branch. Only set it when we don't already have one, so a
                // re-import never reshuffles an existing property's branch.
                if (empty($existing?->branch_id)) {
                    $agentBranch = $row->resolved_agent_id
                        ? \App\Models\User::withoutGlobalScopes()->whereKey($row->resolved_agent_id)->value('branch_id')
                        : null;
                    $attrs['branch_id'] = $agentBranch
                        ?? \App\Models\Branch::where('agency_id', $run->agency_id)->value('id');
                }

                // These columns are NOT NULL with DEFAULT 0 in the schema, but
                // the P24 CSV legitimately carries null for rentals (price) or
                // land listings (beds/baths/garages). Drop nulls so the column
                // default applies instead of triggering a NOT NULL violation.
                foreach (['price', 'beds', 'baths', 'garages'] as $notNull) {
                    if (array_key_exists($notNull, $attrs) && $attrs[$notNull] === null) {
                        unset($attrs[$notNull]);
                    }
                }

                // p24_suburb_id is FK-constrained to p24_suburbs. The CSV's raw
                // P24 SuburbId only matches when that suburb is seeded locally
                // (reference tables can be partial per environment); an unseeded
                // id 1452s the whole confirm. Only set it when it resolves — the
                // raw value is always preserved in features_json.p24_source_suburb_id.
                if (!empty($attrs['p24_suburb_id'])
                    && !DB::table('p24_suburbs')->where('id', $attrs['p24_suburb_id'])->exists()) {
                    unset($attrs['p24_suburb_id']);
                }

                // Link the P24 listing number so a later push UPDATES the
                // existing P24 listing instead of CREATING a duplicate. The
                // syndication push (Property24ListingMapper::map) decides
                // update-vs-create on p24_ref — NOT p24_listing_number — so the
                // import MUST set p24_ref too, or every imported property pushes
                // as a brand-new duplicate. This stock is, by definition,
                // already live on P24 (it came from a P24 export), so reflect
                // that with an 'active' syndication status / activation stamp.
                if (is_numeric($listingNumber)) {
                    $attrs['p24_ref'] = (string) $listingNumber;
                    if (empty($existing?->p24_syndication_status)) {
                        $attrs['p24_syndication_status'] = 'active';
                        $attrs['p24_activated_at'] = now();
                    }
                }

                if ($existing) {
                    $existing->fill($attrs)->save();
                    $property = $existing;
                } else {
                    // Imported stock is existing inventory, not a freshly
                    // captured mandate — suppress the new-listing document-chase
                    // chore tasks AutoEventService would otherwise generate
                    // (the leak that grew an 18k-task backlog and OOM'd the
                    // Tasks page). The created() observer reads this flag.
                    $property = new Property($attrs);
                    $property->skipNewListingAutomation = true;
                    $property->save();
                }

                // Go-live migration: agency on-boarding imports their existing
                // already-compliant P24 stock. The run was flagged at upload
                // time; flip the compliance snapshot so MarketingReadinessService
                // short-circuits to "ready" (see service line 31).
                if ($run->mark_compliant_on_confirm && $property->compliance_snapshot_at === null) {
                    $property->forceFill([
                        'compliance_snapshot_at'   => now(),
                        'compliance_snapshot_data' => [
                            'snapshot_version'       => 1,
                            'source'                 => 'p24_go_live_migration',
                            'p24_import_run_id'      => $run->id,
                            'p24_listing_number'     => $listingNumber,
                            'snapshotted_by_user_id' => $this->userId,
                            'snapshotted_at'         => now()->toIso8601String(),
                            'note'                   => 'Auto-marked compliant via P24 agency on-boarding import. Pre-existing P24 stock treated as already compliant for go-live.',
                        ],
                        'first_marketed_at' => $property->first_marketed_at ?? now(),
                    ])->save();
                }

                $row->target_id = $property->id;
                $row->status = 'confirmed';
                $row->confirmed_at = now();
                $row->processing_at = null;
                if ($this->userId) $row->confirmed_by = $this->userId;
                $row->save();

                $propertyId = $property->id;
                $imageUrls = array_values(array_filter((array) ($row->image_urls_json ?? [])));

                // Stamp gallery expectations + the INBOUND signature now, so the
                // property is queryable as "images pending" the instant it lands
                // and a later re-import can skip an unchanged, already-complete
                // gallery. Only (re)arm 'pending' when there is fetch work — an
                // unchanged, already-complete gallery keeps 'complete' so the
                // download job short-circuits instead of re-walking the CDN.
                $newSig = DownloadP24RowImagesJob::signatureFor($imageUrls);
                $oldSig = $property->p24_source_image_signature;
                $skipImages = $property->gallery_import_status === 'complete'
                    && $oldSig === $newSig
                    && (int) $property->gallery_stored_count >= count($imageUrls);

                // The P24 gallery genuinely CHANGED since we last stored it — a
                // different inbound URL set, not just an incomplete heal of the
                // same one. The files already on disk are the OLD gallery; the
                // download job must drop them and refetch every ordinal fresh,
                // NOT "heal" the new set against stale files (fetch-only-missing
                // would see 1.jpg..N.jpg present and refetch nothing, leaving the
                // listing marked complete while rendering the previous photos).
                // Only a change when we had a prior set — a first import
                // (oldSig null) is not a change.
                $galleryChanged = $oldSig !== null && $oldSig !== $newSig;

                $galleryMeta = [
                    'gallery_expected_count'     => count($imageUrls),
                    'p24_source_image_signature' => $newSig,
                ];
                if (!$skipImages) {
                    $galleryMeta['gallery_import_status'] = empty($imageUrls) ? 'complete' : 'pending';
                }
                // A changed gallery restarts its stored count from zero — the old
                // count described the old photos and would otherwise read as
                // spurious progress until the refetch overwrites it.
                if ($galleryChanged) {
                    $galleryMeta['gallery_stored_count'] = 0;
                }
                $property->forceFill($galleryMeta)->save();
            });

            // Images stream in behind on the narrow p24images lane — the confirm
            // no longer blocks on the CDN, so a property is searchable in seconds
            // while its gallery fills. Nothing to fetch when the set is empty or
            // an unchanged gallery is already complete.
            if ($propertyId && !empty($imageUrls) && !$skipImages) {
                DownloadP24RowImagesJob::dispatch($propertyId, $imageUrls, $galleryChanged);
            }
        } catch (\Throwable $e) {
            Log::error('ConfirmP24PropertyRowJob failed', ['row_id' => $row->id, 'error' => $e->getMessage()]);
            $row->update([
                'status'        => 'error',
                'processing_at' => null,
                'errors_json'   => array_merge($row->errors_json ?? [], ['Confirm failed: ' . $e->getMessage()]),
            ]);
        }
    }
}
