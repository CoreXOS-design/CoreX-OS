<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function upload(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            'images'    => 'required|array',
            // Phone-camera friendly: HEIC/HEIF must be accepted explicitly —
            // Laravel's `image` rule excludes them. (GD can't downscale HEIC, so
            // those are stored as-is; PropertyImageStorer swallows that quietly.)
            'images.*'  => 'file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
        ]);

        // Validate a stale custom_id BEFORE storing files (no orphaned uploads).
        if ($request->section === 'custom') {
            $this->customIndexOrFail($property->rentalImagesStructure()['custom'], $request->custom_id);
        }

        $structure = $property->rentalImagesStructure();
        $new       = $this->images->storeMany((array) $request->file('images'), $property->id);

        if (!empty($new)) {
            if ($request->section === 'custom') {
                $i = $this->customIndexOrFail($structure['custom'], $request->custom_id);
                $structure['custom'][$i]['images'] = array_values(array_merge($structure['custom'][$i]['images'], $new));
            } else {
                $structure[$request->section]['images'] = array_values(
                    array_merge($structure[$request->section]['images'], $new)
                );
            }

            $property->update(['rental_images_json' => $structure]);
        }

        return response()->json(
            $this->payload($property) + ['uploaded' => $this->absolutise($new)],
            201
        );
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

        $structure = $property->rentalImagesStructure();

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

        $property->update(['rental_images_json' => $structure]);

        return response()->json($this->payload($property));
    }

    // ── POST /api/v1/mobile/properties/{property}/rental-images/delete ───
    // Remove one image from a section by its index and delete the file from
    // disk. JSON array entries are not Eloquent models, so — exactly like the
    // marketing gallery's deleteImage — this is a real file removal, not a
    // soft delete (no model to archive). Non-Negotiable #1 governs models.
    public function destroyImage(Request $request, Property $property): JsonResponse
    {
        $this->guard($request->user(), $property);

        $data = $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            'index'     => 'required|integer|min:0',
        ]);

        $structure = $property->rentalImagesStructure();
        $index     = (int) $data['index'];

        $removeAt = function (array $images, int $idx): array {
            if (isset($images[$idx])) {
                $path = str_replace('/storage/', '', parse_url($images[$idx], PHP_URL_PATH));
                Storage::disk('public')->delete($path);
                array_splice($images, $idx, 1);
            }
            return array_values($images);
        };

        if ($data['section'] === 'custom') {
            $i = $this->customIndexOrFail($structure['custom'], $data['custom_id'] ?? null);
            $structure['custom'][$i]['images'] = $removeAt($structure['custom'][$i]['images'], $index);
        } else {
            $structure[$data['section']]['images'] = $removeAt($structure[$data['section']]['images'], $index);
        }

        $property->update(['rental_images_json' => $structure]);

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

        if (!$property->isOnMarket()) {
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
            'is_live'      => $property->isOnMarket(),
            'available'    => true, // guard() already proved eligibility
            'rental_images' => [
                'in_inspection'  => $this->absolutiseSection($structure['in_inspection']),
                'out_inspection' => $this->absolutiseSection($structure['out_inspection']),
                'custom'         => array_map(
                    fn (array $sec) => $this->absolutiseSection($sec),
                    $structure['custom']
                ),
            ],
        ];
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
