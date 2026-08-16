<?php

namespace App\Jobs;

use App\Models\P24ImportRow;
use App\Models\Property;
use App\Services\P24\P24LocationResolver;
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
        // AT-321 — attribute audit rows written while applying this import.
        \App\Support\Audit\PropertyAuditContext::setSource('P24 import', 'import');

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

                // The P24 CSV carries no mandate/exclusivity field at all — P24
                // only ever exports what's live on their platform, never the
                // agency's private mandate terms with the seller (audit
                // 2026-08-16: confirmed absent from all 68 export columns).
                // Default imported stock to 'Open' rather than leaving
                // mandate_type silently blank forever; never overwrite one a
                // human has since set manually in CoreX.
                if (empty($existing?->mandate_type)) {
                    $attrs['mandate_type'] = 'Open';
                }

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

                // `$attrs['p24_suburb_id']` at this point is still the CSV's raw
                // value — Property24's EXTERNAL suburb id (`p24_suburbs.p24_id`),
                // NOT our internal `p24_suburbs.id`. `properties.p24_suburb_id`
                // is a FK to our internal id, so it must be resolved via the
                // external id before it's usable — never stored as-is.
                //
                // P24 suburb-id import bug (2026-08-16), two issues found the same day on the same
                // column:
                //  1. The FK was stored raw with no suburb/city text ever
                //     derived from it — every CSV import left suburb/city blank
                //     (4,753/4,755 on the Demo Agency Test run of 2026-08-14).
                //  2. The first fix for #1 still matched the raw external
                //     SuburbId against `p24_suburbs.id` (our internal PK) —
                //     resolveByP24Id() below is what that should have been all
                //     along. Both external and internal ids are called "the P24
                //     suburb id" throughout this codebase and are NOT
                //     interchangeable — see P24LocationResolver's docblock. The
                //     collision is silent: whenever a listing's external id
                //     happened to also exist as an internal row id, the wrong
                //     property landed on it as blocked-nothing/dropped-nothing
                //     wrong-suburb data (100% of the 4,753 confirmed rows on
                //     that run — every external SuburbId in a real P24 export is
                //     large enough to always collide with SOME unrelated
                //     internal row).
                //
                // Unverified or unseeded suburbs are never guessed — the FK (and
                // suburb/city text) is simply left unset.
                if (!empty($attrs['p24_suburb_id'])) {
                    $resolved = P24LocationResolver::resolveByP24Id((int) $attrs['p24_suburb_id']);
                    if ($resolved && $resolved['suburb']->p24_verified_at) {
                        $attrs['p24_suburb_id']   = $resolved['suburb']->id;
                        $attrs['p24_city_id']     = $resolved['city']->id;
                        $attrs['p24_province_id'] = $resolved['province']?->id;
                        $attrs['suburb']          = $resolved['suburb']->name;
                        $attrs['city']            = $resolved['city']->name;
                        if ($resolved['province']) {
                            $attrs['province'] = $resolved['province']->name;
                        }
                    } else {
                        unset($attrs['p24_suburb_id']);
                    }
                }

                // Link the P24 listing number so a later push UPDATES the
                // existing P24 listing instead of CREATING a duplicate. The
                // syndication push (Property24ListingMapper::map) decides
                // update-vs-create on p24_ref — NOT p24_listing_number — so the
                // import MUST set p24_ref too, regardless of the listing's own
                // status, or a later real push for this property creates a
                // duplicate on P24.
                if (is_numeric($listingNumber)) {
                    $attrs['p24_ref'] = (string) $listingNumber;

                    // The CSV is a point-in-time P24 export, not a feed of only
                    // currently-live stock — most rows are historical
                    // (Withdrawn/Expired/Sold/Cancelled/Rented). Unconditionally
                    // stamping every row 'active' told every downstream consumer
                    // (Property24SyndicationService's refresh loop, AdManager,
                    // SellerOutreach's "advertised" check, the stats dashboard)
                    // that sold/withdrawn stock was still being pushed live to
                    // P24 — found in the 2026-08-16 import audit: 4,507/4,753
                    // Demo Agency Test rows landed 'active' though only 232
                    // actually carried status=Active. Gate on the listing's own
                    // status instead, and only touch this when CoreX has never
                    // made a REAL outbound push of its own
                    // (p24_last_submitted_at) — once we've genuinely submitted,
                    // our own syndication history is authoritative, not a
                    // re-import of the original source export.
                    if ($existing?->p24_last_submitted_at === null) {
                        if (($attrs['status'] ?? null) === 'Active') {
                            $attrs['p24_syndication_status'] = 'active';
                            $attrs['p24_activated_at'] = $existing?->p24_activated_at ?? now();
                        } else {
                            $attrs['p24_syndication_status'] = null;
                            $attrs['p24_activated_at'] = null;
                        }
                    }
                }

                if ($existing) {
                    $existing->fill($attrs);
                    // A re-import is a snapshot load, not a live edit — it must not
                    // fire P24 push/deactivation calls off the back of a status or
                    // field change (a bulk import of off-market stock would 401 on
                    // every one and churn P24 with thousands of calls). See flag below.
                    $existing->skipSyndicationAutomation = true;
                    $existing->skipNewListingAutomation = true;
                    $existing->save();
                    $property = $existing;
                } else {
                    // Imported stock is existing inventory, not a freshly
                    // captured mandate — suppress the new-listing document-chase
                    // chore tasks AutoEventService would otherwise generate
                    // (the leak that grew an 18k-task backlog and OOM'd the
                    // Tasks page), AND the syndication push/deactivation the
                    // PropertyObserver would fire for imported stock. The observer
                    // and created() observer read these flags.
                    $property = new Property($attrs);
                    $property->skipNewListingAutomation = true;
                    $property->skipSyndicationAutomation = true;
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
