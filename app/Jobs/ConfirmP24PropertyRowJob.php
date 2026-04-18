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
        $row = P24ImportRow::with('run')->find($this->rowId);
        if (!$row || $row->row_type !== 'listing') return;
        if (in_array($row->status, ['confirmed', 'excluded'], true)) return;

        $mapped = $row->mapped_json ?? [];
        $run = $row->run;
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
                    'beds', 'baths', 'garages', 'erf_size_m2', 'size_m2',
                    'property_type', 'expiry_date',
                    'levy', 'rates_taxes', 'latitude', 'longitude',
                    'features_json', 'lease_period', 'p24_listing_number',
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

                if ($existing) {
                    $existing->fill($attrs)->save();
                    $property = $existing;
                } else {
                    $property = Property::create($attrs);
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
                DownloadP24RowImagesJob::dispatch($propertyId, $imageUrls);
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
