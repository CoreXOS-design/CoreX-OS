<?php

namespace App\Jobs;

use App\Models\P24ImportRow;
use App\Models\Property;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $rowId, public ?int $userId = null) {}

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

        try {
            DB::transaction(function () use ($row, $mapped, $run, &$propertyId, &$imageUrls) {
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
                    'youtube_video_id', 'matterport_id',
                    'features_json', 'spaces_json', 'pet_friendly',
                    'lease_period', 'p24_listing_number',
                ];
                $attrs = [];
                foreach ($fillable as $k) {
                    if (array_key_exists($k, $mapped)) $attrs[$k] = $mapped[$k];
                }
                $attrs['agent_id']  = $row->resolved_agent_id;
                $attrs['agency_id'] = $run->agency_id;

                // These columns are NOT NULL with DEFAULT 0 in the schema, but
                // the P24 CSV legitimately carries null for rentals (price) or
                // land listings (beds/baths/garages). Drop nulls so the column
                // default applies instead of triggering a NOT NULL violation.
                foreach (['price', 'beds', 'baths', 'garages'] as $notNull) {
                    if (array_key_exists($notNull, $attrs) && $attrs[$notNull] === null) {
                        unset($attrs[$notNull]);
                    }
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
            });

            if ($propertyId && !empty($imageUrls)) {
                // Run inline so the confirm response only returns once images
                // are on disk. Keeps things working without a queue worker.
                DownloadP24RowImagesJob::dispatchSync($propertyId, $imageUrls);
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
