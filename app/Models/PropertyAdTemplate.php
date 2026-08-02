<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PropertyAdTemplate extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $fillable = [
        'agency_id','user_id', 'name', 'layout_json', 'is_global'];

    protected $casts = [
        'layout_json' => 'array',
        'is_global'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Edit/delete rights (spec ad-manager.md §6):
     * the original creator always qualifies; any other member needs the
     * `properties.ad_templates.manage` permission. Cross-agency access is
     * already blocked by AgencyScope (route-model binding 404s), so this
     * only ever decides rights within the same agency.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $user->hasPermission('properties.ad_templates.manage');
    }

    /**
     * §18 — property-type template variants. `layout_json` can carry a
     * `variants` map keyed by the exact property_setting_items (group
     * 'property_type') name — a FULL alternate design (its own canvas +
     * element set), built in the Ad Builder by cloning the Default design
     * and editing it independently (e.g. a house needs Bedrooms/Bathrooms; a
     * vacant land listing has no floor size at all, only an erf size).
     *
     * Server-side PHP mirror of the kernel's `CoreXAd.resolveTemplateLayout()`
     * (public/js/corex-ad-render.js) — used where a layout must be resolved
     * BEFORE reaching the browser, i.e. the bulk Ad Manager's per-property
     * server-rendered payload (AdManagerController::generate()). Kept
     * behaviourally identical to the JS version; both are unit-tested against
     * the same cases.
     *
     * No match (blank type, no variants at all, or a type nobody made
     * custom) → the Default design (this template's own top-level fields).
     * Never a broken/empty render over missing classification data.
     */
    public function resolvedLayoutFor(?string $propertyTypeRaw): array
    {
        $base     = (array) $this->layout_json;
        $variants = $base['variants'] ?? null;
        $t        = mb_strtolower(trim((string) $propertyTypeRaw));

        if (is_array($variants) && $t !== '') {
            foreach ($variants as $name => $variant) {
                if (mb_strtolower(trim((string) $name)) === $t) {
                    return [
                        'canvasW'       => $variant['canvasW']       ?? null,
                        'canvasH'       => $variant['canvasH']       ?? null,
                        'canvasBg'      => $variant['canvasBg']      ?? null,
                        'canvasBgMode'  => $variant['canvasBgMode']  ?? null,
                        'canvasBgFrom'  => $variant['canvasBgFrom']  ?? null,
                        'canvasBgTo'    => $variant['canvasBgTo']    ?? null,
                        'canvasBgAngle' => $variant['canvasBgAngle'] ?? null,
                        'canvasPreset'  => $variant['canvasPreset']  ?? null,
                        'elements'      => $variant['elements']      ?? [],
                    ];
                }
            }
        }

        return [
            'canvasW'       => $base['canvasW']       ?? null,
            'canvasH'       => $base['canvasH']       ?? null,
            'canvasBg'      => $base['canvasBg']      ?? null,
            'canvasBgMode'  => $base['canvasBgMode']  ?? null,
            'canvasBgFrom'  => $base['canvasBgFrom']  ?? null,
            'canvasBgTo'    => $base['canvasBgTo']    ?? null,
            'canvasBgAngle' => $base['canvasBgAngle'] ?? null,
            'canvasPreset'  => $base['canvasPreset']  ?? null,
            'elements'      => $base['elements']      ?? [],
        ];
    }
}
