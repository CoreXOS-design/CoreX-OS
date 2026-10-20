<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\User;
use App\Services\Images\PrivateImageStore;
use App\Services\Images\PropertyImageStorer;
use App\Services\Images\PropertyThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mobile API — Rental inspection galleries.
 *
 * The web equivalent lives in CoreX\PropertyController
 * (uploadRentalImages / saveRentalImagesMeta / deleteRentalImage); this is the
 * token-authenticated mobile mirror. Data lives on
 * properties.rental_images_json, normalised through
 * Property::rentalImagesStructure(). Files are stored exactly like the web
 * (PropertyImageStorer → storage/app/public/properties/{id}/, downscaled
 * 2560px / JPEG 85). Every image URL the API returns is absolutised against
 * APP_URL so the device can load it.
 *
 * GATE: these endpoints are rental-only AND live-only. A property is eligible
 * only when listing_type === 'rental' AND it is on-market (Property::isOnMarket()
 * — i.e. it has been listed and made live, not draft/withdrawn/sold/etc). The
 * mobile app reads `rental_inspections_available` on the property payload to
 * decide whether to show the tab; this controller enforces the same gate so a
 * crafted request can never reach a sale or off-market property.
 *
 * Spec: .ai/specs/rental-images.md
 */
class MobileRentalImagesController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ResolvesMobileDataScope;

    public function __construct(private PropertyImageStorer $images)
    {
    }

    // ── GET /api/v1/mobile/properties/{property}/rental-images ───────────
    // Returns the normalised gallery structure with absolute image URLs.
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        return response()->json($this->payload($property));
    }

    // ── POST /api/v1/mobile/properties/{property}/rental-images/upload ───
    // Multipart. Append images to a section (in_inspection | out_inspection |
    // custom). Custom sections are addressed by their server-minted custom_id.
    //
    // Two request shapes, brought to parity with the main gallery upload:
    //  - PREFERRED: one photo per request via `image` + a stable `client_upload_id`
    //    idempotency key. A retry (same key) returns the existing record instead
    //    of duplicating, and the write is commit-verified before any 2xx.
    //  - LEGACY: a batch via `images[]` (no idempotency). Kept for older builds;
    //    now race-safe too.
    // Both paths append under a row lock so concurrent uploads to one property
    // cannot lost-update each other's rows on the rental_images_json JSON column.
    public function upload(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            // Exactly one of image / images[] must be present.
            // Phone-camera friendly: HEIC/HEIF accepted explicitly — Laravel's
            // `image` rule excludes them. (GD can't downscale HEIC, so those are
            // stored as-is; PropertyImageStorer swallows that quietly.)
            'image'            => 'required_without:images|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'images'           => 'required_without:image|array',
            'images.*'         => 'file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_upload_id' => 'nullable|string|max:255',
        ]);

        // Validate a stale custom_id BEFORE storing files (no orphaned uploads).
        if ($request->section === 'custom') {
            $this->customIndexOrFail($property->rentalImagesStructure()['custom'], $request->custom_id);
        }

        $section  = $request->section;
        $customId = $request->custom_id;

        // Audit B7 - the In and Out sections ARE the inspection's own photo store now: a photo goes to the inspection under way (private
        // disk, signed links), the same place the recording screen, comparison, match groups and report read from - not the old flat JSON
        // list nothing reads. Custom sections stay as they were.
        if (in_array($section, ['in_inspection', 'out_inspection'], true)) {
            return $this->uploadToInspection($request, $property, $section);
        }

        // ── Per-photo path (idempotent, commit-verified) ──
        if ($request->hasFile('image')) {
            $clientUploadId = trim((string) $request->input('client_upload_id'));
            $clientUploadId = $clientUploadId !== '' ? $clientUploadId : null;

            // Fast-path idempotency: this exact client upload already landed.
            if ($clientUploadId !== null) {
                $existing = $property->rental_upload_keys[$clientUploadId] ?? null;
                if (is_string($existing) && $existing !== '') {
                    return $this->uploadResponse($property, [$existing], true, 200);
                }
            }

            // PropertyImageStorer bakes EXIF orientation, downscales and thumbnails.
            $url = $this->images->store($request->file('image'), $property->id);

            // Commit guard: never return 2xx if the file did not land on disk —
            // the client drops a photo from its retry queue on any 2xx.
            $rel = $this->relPathFromUrl($url);
            if ($rel === null || ! Storage::disk('public')->exists($rel)) {
                return response()->json(['message' => 'Image could not be stored. Please retry.'], 500);
            }

            $duplicateUrl = null;
            DB::transaction(function () use ($property, $url, $section, $customId, $clientUploadId, &$duplicateUrl) {
                /** @var Property $locked */
                $locked = Property::whereKey($property->getKey())->lockForUpdate()->firstOrFail();

                if ($clientUploadId !== null) {
                    $seen = $locked->rental_upload_keys ?? [];
                    if (isset($seen[$clientUploadId]) && is_string($seen[$clientUploadId]) && $seen[$clientUploadId] !== '') {
                        $duplicateUrl = $seen[$clientUploadId];
                        return;
                    }
                }

                $structure = $locked->rentalImagesStructure();
                $this->appendToSection($structure, $section, $customId, [$url]);
                $locked->rental_images_json = $structure;

                if ($clientUploadId !== null) {
                    $seen = $locked->rental_upload_keys ?? [];
                    $seen[$clientUploadId] = $url;
                    $locked->rental_upload_keys = $seen;
                }

                $locked->saveQuietly();
            });

            // A concurrent retry beat us: bin the redundant file + thumb, return
            // the canonical record (still 2xx — nothing lost).
            if ($duplicateUrl !== null) {
                // Audit B27 - the redundant copy is kept (not deleted); it is referenced nowhere, so nothing shows it twice.

                return $this->uploadResponse($property, [$duplicateUrl], true, 200);
            }

            return $this->uploadResponse($property, [$url], false, 201);
        }

        // ── Legacy batch path (now race-safe via the same row lock) ──
        $new = $this->images->storeMany((array) $request->file('images'), $property->id);

        if (! empty($new)) {
            DB::transaction(function () use ($property, $new, $section, $customId) {
                /** @var Property $locked */
                $locked = Property::whereKey($property->getKey())->lockForUpdate()->firstOrFail();
                $structure = $locked->rentalImagesStructure();
                $this->appendToSection($structure, $section, $customId, $new);
                $locked->rental_images_json = $structure;
                $locked->saveQuietly();
            });
        }

        return $this->uploadResponse($property, $new, false, 201);
    }

    // ── POST /api/v1/mobile/properties/{property}/rental-images/save ─────
    // JSON metadata layer: set a section date, add a custom section (server
    // mints the id), or rename a custom section. The structure is rebuilt
    // server-side from the normalised current state so a client can never
    // inject arbitrary keys or overwrite stored images.
    public function save(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        $data = $request->validate([
            'action'    => 'required|in:set_date,add_section,rename_section',
            'section'   => 'required_if:action,set_date|in:in_inspection,out_inspection,custom',
            // nullable first: the global ConvertEmptyStringsToNull middleware
            // turns an empty custom_id ('' for the fixed in/out sections) into
            // null, which a bare string rule would reject. required_if still
            // forces a value for custom sections / renames.
            'custom_id' => 'nullable|string|required_if:section,custom|required_if:action,rename_section',
            'date'      => 'nullable|date',
            'name'      => 'required_if:action,add_section,rename_section|string|max:120',
        ]);

        // Serialise the metadata mutation on the property row so it can't
        // lost-update a concurrent photo upload (both read-modify-write the same
        // rental_images_json column).
        DB::transaction(function () use ($property, $data) {
            /** @var Property $locked */
            $locked = Property::whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $structure = $locked->rentalImagesStructure();

            if ($data['action'] === 'set_date') {
                // Normalise to a canonical Y-m-d (or null) so the response date
                // always matches the documented contract regardless of input format.
                $date = !empty($data['date'])
                    ? \Illuminate\Support\Carbon::parse($data['date'])->toDateString()
                    : null;

                if (($data['section'] ?? null) === 'custom') {
                    $i = $this->customIndexOrFail($structure['custom'], $data['custom_id'] ?? null);
                    $structure['custom'][$i]['date'] = $date;
                } else {
                    $structure[$data['section']]['date'] = $date;
                }
            } elseif ($data['action'] === 'add_section') {
                // Mint a collision-free short id against the existing custom sections.
                do {
                    $id = Str::lower(Str::random(6));
                } while (collect($structure['custom'])->contains('id', $id));

                $structure['custom'][] = [
                    'id'     => $id,
                    'name'   => trim($data['name']),
                    'date'   => null,
                    'images' => [],
                ];
            } elseif ($data['action'] === 'rename_section') {
                $i = $this->customIndexOrFail($structure['custom'], $data['custom_id'] ?? null);
                $structure['custom'][$i]['name'] = trim($data['name']);
            }

            $locked->rental_images_json = $structure;
            $locked->saveQuietly();
        });

        return response()->json($this->payload($property));
    }

    // ── POST /api/v1/mobile/properties/{property}/rental-images/delete ───
    // Remove one image from a section by its index. Audit B27 (non-negotiable
    // #1): the file is NEVER deleted - the reference moves to the property's
    // `archived` list (Property::archiveRentalImage) and the picture stays on
    // disk as evidence.
    public function destroyImage(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        $data = $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            'index'     => 'required_without:photo_id|nullable|integer|min:0',
            // Audit L2 - the stable way: the id the list carries for an inspection photo (`image_ids`), immune to the list shifting underneath.
            'photo_id'  => 'nullable|integer|min:1',
        ]);

        if (! empty($data['photo_id'])) {
            if ($data['section'] === 'custom') {
                return response()->json(['message' => 'A photo id only applies to the In and Out inspection photos.'], 422);
            }

            return $this->archiveInspectionPhotoById($request, $property, $data['section'], (int) $data['photo_id']);
        }

        $index = (int) $data['index'];

        // Audit B7 - the In/Out lists are the legacy images followed by the inspection's own photos; an index past the legacy ones is a photo.
        if ($data['section'] !== 'custom') {
            $legacyCount = count($property->rentalImagesStructure()[$data['section']]['images']);
            if ($index >= $legacyCount) {
                return $this->archiveInspectionPhotoAt($request, $property, $data['section'], $index - $legacyCount);
            }
        }

        // Row lock: a delete-by-index must not race a concurrent upload/append
        // (which would shift indices) or another delete on the same JSON column.
        DB::transaction(function () use ($property, $data, $index, $request) {
            /** @var Property $locked */
            $locked = Property::whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $structure = $locked->rentalImagesStructure();

            if ($data['section'] === 'custom') {
                $i = $this->customIndexOrFail($structure['custom'], $data['custom_id'] ?? null);
                $sectionKey = $structure['custom'][$i]['id'];
                $url = $structure['custom'][$i]['images'][$index] ?? null;
            } else {
                $sectionKey = $data['section'];
                $url = $structure[$sectionKey]['images'][$index] ?? null;
            }
            if ($url !== null) {
                Property::archiveRentalImage($structure, $sectionKey, $url, $request->user()?->id);
            }

            $locked->rental_images_json = $structure;
            $locked->saveQuietly();
        });

        return response()->json($this->payload($property));
    }

    // ── Inspection photo store (audit B7) ────────────────────────────────

    private function inspectionTypeFor(string $section): string
    {
        return $section === 'in_inspection' ? RentalInspection::TYPE_IN : RentalInspection::TYPE_OUT;
    }

    /** The inspection of that type a photo is being added to: the one under way; null when none is. */
    private function currentInspectionFor(Property $property, string $section): ?RentalInspection
    {
        return RentalInspection::currentFor($property, $this->inspectionTypeFor($section));
    }

    /** The photos behind a section's inspection-store images, in the order the list shows them. */
    private function inspectionPhotosFor(Property $property, string $section)
    {
        $inspection = $this->currentInspectionFor($property, $section) ?? RentalInspection::mostRecentFor($property, $this->inspectionTypeFor($section));

        return $inspection
            ? RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->orderBy('id')->get()
            : collect();
    }

    private function uploadToInspection(Request $request, Property $property, string $section): JsonResponse
    {
        $inspection = $this->currentInspectionFor($property, $section);
        // Audit L1 - nothing under way, but the most recent inspection of that type may be COMPLETED (or signed) after a phone captured
        // photos offline: those go down the same flagged late-evidence path as the web (acceptsLateEvidence below), never a bare 409.
        $fallback = false;
        if (! $inspection && ($recent = RentalInspection::mostRecentFor($property, $this->inspectionTypeFor($section))) && $recent->status === RentalInspection::STATUS_COMPLETED) {
            $inspection = $recent;
            $fallback = true;
        }
        if (! $inspection) {
            return response()->json([
                'message' => 'There is no ' . ($section === 'in_inspection' ? 'in' : 'out') . '-inspection under way for this property. Start it in CoreX first, then add the photos.',
                'code' => 'no_inspection_under_way',
            ], 409);
        }
        // the same own/branch/agency reach the recording screen enforces
        abort_unless(RentalInspection::query()->visibleTo($request->user())->whereKey($inspection->id)->exists(), 404);

        $files = $request->hasFile('image') ? [$request->file('image')] : array_values((array) $request->file('images'));
        $clientUploadId = trim((string) $request->input('client_upload_id'));
        $captureTime = app(\App\Services\Rentals\RentalInspectionPhotoCaptureTime::class);
        $store = app(PrivateImageStore::class);
        $directory = "rental-inspections/{$inspection->agency_id}/{$inspection->id}";
        $photos = [];
        $duplicate = false;
        $late = false;

        foreach ($files as $i => $file) {
            // a retried upload (same client_upload_id) is answered from what is stored - one stable uuid per (inspection, key)
            $key = ($clientUploadId !== '' && count($files) === 1)
                ? \Ramsey\Uuid\Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $inspection->id . '|' . $clientUploadId)->toString()
                : null;
            if ($key && ($existing = RentalInspectionPhoto::withoutGlobalScopes()->withTrashed()->where('client_idempotency_key', $key)->first())) {
                $photos[] = $existing;
                $duplicate = true;
                continue;
            }

            $capture = $captureTime->resolve($file, is_string($request->input('captured_at')) ? $request->input('captured_at') : null, ['rental_inspection_id' => $inspection->id, 'user_id' => $request->user()->id]);

            try {
                $inspection->assertContentEditable();
            } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
                // spec 14.4 case 4: offline evidence captured before the report was locked is stored, flagged - never lost to a 409
                $isReal = $capture['taken_at_source'] !== RentalInspectionPhoto::TAKEN_AT_SERVER;
                if (! $isReal || ! $inspection->acceptsLateEvidence($capture['taken_at'])) {
                    if ($fallback) {
                        // a live photo for a property whose inspection is already completed: as before, nothing is under way to add it to
                        return response()->json([
                            'message' => 'There is no ' . ($section === 'in_inspection' ? 'in' : 'out') . '-inspection under way for this property. Start it in CoreX first, then add the photos.',
                            'code' => 'no_inspection_under_way',
                        ], 409);
                    }

                    return response()->json(['message' => $e->getMessage(), 'code' => 'not_recordable'], 409);
                }
                $late = true;
            }

            $reference = $store->store($file, $directory);
            $photos[] = RentalInspectionPhoto::create(array_filter([
                'agency_id' => $inspection->agency_id,
                'rental_inspection_id' => $inspection->id,
                'storage_path' => $reference,
                'uploaded_by_user_id' => $request->user()->id,
                'client_idempotency_key' => $key,
                'file_size_bytes' => $file->getSize(),
                'taken_at' => $capture['taken_at'],
                'taken_at_source' => $capture['taken_at_source'],
                'added_after_signing_at' => $late ? now() : null,
            ], fn ($v) => $v !== null));
        }

        return response()->json(
            $this->payload($property) + [
                'uploaded' => collect($photos)->map(fn ($p) => $p->storage_path)->values()->all(),
                'duplicate' => $duplicate,
            ] + ($late ? ['added_after_signing' => true, 'message' => 'Recorded, but this inspection was already signed or completed - it is on file as added after signing.'] : []),
            $duplicate && count($photos) === 1 ? 200 : 201
        );
    }

    /**
     * Remove one inspection-store photo from the In/Out list by its position - ARCHIVED (never deleted), same as the recording screen's own remove.
     * Audit L2: the position is turned into the photo's id UNDER A ROW LOCK on the property (the same lock the uploads take), and the archive is
     * then done by that id, so a list that shifted between the app's read and this call cannot archive a different photo than the one resolved.
     * An app that sends `photo_id` (from `image_ids`) skips the position entirely.
     */
    private function archiveInspectionPhotoAt(Request $request, Property $property, string $section, int $offset): JsonResponse
    {
        $photoId = DB::transaction(function () use ($property, $section, $offset) {
            Property::whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            return $this->inspectionPhotosFor($property, $section)->values()->get($offset)?->id;
        });
        if ($photoId === null) {
            return response()->json($this->payload($property));
        }

        return $this->archiveInspectionPhotoById($request, $property, $section, (int) $photoId);
    }

    /** Archive one inspection photo, named by its id, out of this property's In or Out inspections. */
    private function archiveInspectionPhotoById(Request $request, Property $property, string $section, int $photoId): JsonResponse
    {
        $photo = RentalInspectionPhoto::query()->whereKey($photoId)
            ->whereIn('rental_inspection_id', RentalInspection::query()->where('property_id', $property->id)->where('type', $this->inspectionTypeFor($section))->select('id'))
            ->first();
        if (! $photo) {
            return response()->json($this->payload($property)); // already archived, or not one of this property's photos: nothing to do
        }
        $inspection = RentalInspection::findOrFail($photo->rental_inspection_id);
        abort_unless(RentalInspection::query()->visibleTo($request->user())->whereKey($inspection->id)->exists(), 404);
        try {
            $inspection->assertContentEditable();
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_recordable'], 409);
        }
        $photo->archive($request->user());

        return response()->json($this->payload($property));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Resolve a custom section's array index by its server-minted id, or 404 if
     * the id is stale (the section was renamed away / removed elsewhere). Keeps
     * the "stale custom_id ⇒ 404" contract consistent across upload/save/delete.
     */
    private function customIndexOrFail(array $custom, ?string $id): int
    {
        foreach ($custom as $i => $sec) {
            if ($sec['id'] === $id) {
                return $i;
            }
        }
        abort(404, 'Custom section not found.');
    }

    /**
     * Append image URLs to the resolved section of a (normalised) structure,
     * in place. Shared by the per-photo and batch upload paths.
     *
     * @param  array<int, string>  $urls
     */
    private function appendToSection(array &$structure, string $section, ?string $customId, array $urls): void
    {
        if ($section === 'custom') {
            $i = $this->customIndexOrFail($structure['custom'], $customId);
            $structure['custom'][$i]['images'] = array_values(array_merge($structure['custom'][$i]['images'], $urls));
        } else {
            $structure[$section]['images'] = array_values(array_merge($structure[$section]['images'], $urls));
        }
    }

    /**
     * Public /storage URL → path relative to the public disk, or null if it isn't
     * a recognised stored-image URL. Used to verify the write committed and to
     * bin a redundant duplicate file.
     */
    private function relPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return preg_match('#/storage/(.+)$#', $path, $m) ? $m[1] : null;
    }

    /**
     * Uniform upload response: the full gallery payload plus the just-stored (or
     * already-stored) URLs and a `duplicate` flag. 201 for a fresh store, 200 for
     * an idempotent replay — mirrors the main gallery upload contract.
     *
     * @param  array<int, string>  $urls
     */
    private function uploadResponse(Property $property, array $urls, bool $duplicate, int $status): JsonResponse
    {
        return response()->json(
            $this->payload($property) + [
                'uploaded'  => $this->absolutise($urls),
                'duplicate' => $duplicate,
            ],
            $status
        );
    }

    /**
     * Rental-only + live-only + in-scope gate. Order matters: scope first
     * (404-equivalent privacy), then the feature eligibility checks.
     */
    private function guard(User $user, Property $property): void
    {
        $this->authorizePropertyAccess($user, $property);

        if (strtolower((string) $property->listing_type) !== 'rental') {
            abort(response()->json([
                'message' => 'Rental inspections are only available on rental listings.',
                'code'    => 'not_a_rental',
            ], 422));
        }

        // Audit B7 - a LET property is off the market but is exactly when its inspections happen (an In needs an active lease).
        if (!$property->isOnMarket() && !$property->isLetOrRented()) {
            abort(response()->json([
                'message' => 'Rental inspections become available once the property is listed and live.',
                'code'    => 'not_live',
            ], 422));
        }
    }

    /**
     * The full response body for every endpoint: the availability signals plus
     * the normalised structure with absolute image URLs.
     */
    private function payload(Property $property): array
    {
        $structure = $property->fresh()->rentalImagesStructure();

        return [
            'property_id'  => $property->id,
            'listing_type' => $property->listing_type,
            'is_live'      => $property->isOnMarket() || $property->isLetOrRented(),
            'available'    => true, // guard() already proved eligibility
            'rental_images' => [
                'in_inspection'  => $this->absolutiseSection($this->withInspectionPhotos($property, 'in_inspection', $structure['in_inspection'])),
                'out_inspection' => $this->absolutiseSection($this->withInspectionPhotos($property, 'out_inspection', $structure['out_inspection'])),
                'custom'         => array_map(
                    fn (array $sec) => $this->absolutiseSection($sec),
                    $structure['custom']
                ),
            ],
        ];
    }

    /** Audit B7 - an In/Out section lists the legacy images first, then the inspection's own photos (signed links, already absolute). */
    private function withInspectionPhotos(Property $property, string $section, array $sectionData): array
    {
        $photos = $this->inspectionPhotosFor($property, $section);
        $urls = $photos->map(fn ($p) => $p->storage_path)->values()->all();
        $legacyCount = count($sectionData['images'] ?? []);
        $sectionData['images'] = array_values(array_merge($sectionData['images'] ?? [], $urls));
        // Audit L2 - the stable id of each image, parallel to `images` (null for a legacy image); send it back as `photo_id` to delete.
        $sectionData['image_ids'] = array_merge(array_fill(0, $legacyCount, null), $photos->pluck('id')->map(fn ($id) => (int) $id)->values()->all());

        return $sectionData;
    }

    /** Absolutise the images of one section, leaving date/id/name intact. */
    private function absolutiseSection(array $section): array
    {
        $section['images'] = $this->absolutise($section['images'] ?? []);
        return $section;
    }

    /**
     * Absolutise stored /storage URLs against APP_URL (mobile devices can't
     * resolve relative paths). Already-absolute URLs pass through untouched.
     *
     * @param  array<int, mixed>  $urls
     * @return array<int, string>
     */
    private function absolutise(array $urls): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return array_values(array_filter(array_map(function ($u) use ($base) {
            $u = trim((string) (is_string($u) ? $u : ''));
            if ($u === '') {
                return null;
            }
            if (preg_match('#^(https?:)?//#i', $u)) {
                return $u;
            }
            return $base . '/' . ltrim($u, '/');
        }, $urls)));
    }
}
