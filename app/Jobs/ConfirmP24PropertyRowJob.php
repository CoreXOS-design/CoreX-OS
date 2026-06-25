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

                // Resolve suburb / town / province from the P24 SuburbId via the
                // p24_suburbs → p24_cities → p24_provinces reference tables. The
                // CSV carries no suburb/town text — only SuburbId — so the
                // original importer left every property with NULL location.
                // Left unset when the id is missing or does not resolve, so the
                // property is flagged for manual review rather than guessed.
                $suburbId = $mapped['p24_suburb_id'] ?? null;
                if ($suburbId) {
                    $loc = DB::table('p24_suburbs as s')
                        ->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')
                        ->leftJoin('p24_provinces as pr', 'pr.id', '=', 'c.p24_province_id')
                        ->where('s.p24_id', $suburbId)
                        ->whereNull('s.deleted_at')
                        ->selectRaw('s.name as suburb, c.name as town, pr.name as province')
                        ->first();
                    if ($loc) {
                        if (!empty($loc->suburb))   $attrs['suburb']   = $loc->suburb;
                        if (!empty($loc->town))     $attrs['town']     = $loc->town;
                        if (!empty($loc->province)) $attrs['province'] = $this->canonicalProvince($loc->province);
                    }
                }

                if ($existing) {
                    $existing->fill($attrs)->save();
                    $property = $existing;
                } else {
                    $property = Property::create($attrs);
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
            $this->markRowError($row, $e);
        }
    }

    /**
     * Normalise a P24 province name to the canonical CoreX value (the form
     * stores "KwaZulu-Natal" with the hyphen; p24_provinces holds the
     * un-hyphenated "KwaZulu Natal").
     */
    private function canonicalProvince(string $p): string
    {
        $n = strtolower(str_replace(['-', ' '], '', trim($p)));
        return match ($n) {
            'kwazulunatal', 'kzn' => 'KwaZulu-Natal',
            'gauteng'             => 'Gauteng',
            'westerncape'         => 'Western Cape',
            'easterncape'         => 'Eastern Cape',
            'northerncape'        => 'Northern Cape',
            'freestate'           => 'Free State',
            'mpumalanga'          => 'Mpumalanga',
            'limpopo'             => 'Limpopo',
            'northwest'           => 'North West',
            default               => $p,
        };
    }

    private function markRowError(P24ImportRow $row, \Throwable $e): void
    {
        $row->update([
            'status'        => 'error',
            'processing_at' => null,
            'errors_json'   => array_merge($row->errors_json ?? [], ['Confirm failed: ' . $e->getMessage()]),
        ]);
    }
}
