<?php

namespace App\Http\Controllers\Tools;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Exceptions\AI\AiTemplateLimitReachedException;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AiTemplateGeneration;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AdTemplateDemoPhotos;
use App\Services\AdTemplates\AdTemplateMustIncludes;
use App\Services\AdTemplates\AdTemplatePropertyTypes;
use App\Services\AdTemplates\AdTemplateStyles;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AdTemplates\ListingQrMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Template Manager (Tools → Ad Manager → Template Manager).
 * Spec: .ai/specs/ad-manager.md §19.
 *
 * The one place to see, find, edit, archive and restore every custom ad template
 * the agency owns. Create / Edit hand off to the Ad Builder
 * (PropertyAdTemplateController) — this controller only lists and archives/restores.
 *
 * Scoping: PropertyAdTemplate is BelongsToAgency, so every query here — the list,
 * and the route-model lookup behind archive/restore — is confined to the user's
 * agency at the query layer. A template id from another agency is a 404, not a 403.
 * Whether the user may CHANGE a given template is PropertyAdTemplate::canBeManagedBy()
 * (creator, or `properties.ad_templates.manage`), re-checked here on every write.
 *
 * AT-456 Part B adds "Create with AI" (generateWithAi / saveAiOption): the user describes a
 * look, three validated options come back, and each one saved becomes a normal template
 * (ai_generated = true). Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §B.
 */
class AdTemplateManagerController extends Controller
{
    private const PER_PAGE = 15;

    private const SORTS = ['name', 'creator', 'created', 'updated'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search  = trim((string) $request->query('q', ''));
        $status  = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status') : 'active';
        // "Created" filter: everyone · only mine · AI-made (AT-456 §B.5).
        $creator = in_array($request->query('creator'), ['mine', 'ai'], true) ? $request->query('creator') : 'all';
        $mine    = $creator === 'mine';
        $sort    = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'updated';
        // Dates default newest-first, text columns A→Z, unless the URL says otherwise.
        $dir     = in_array($request->query('dir'), ['asc', 'desc'], true)
            ? $request->query('dir')
            : (in_array($sort, ['updated', 'created'], true) ? 'desc' : 'asc');
        $from    = $this->validDate($request->query('from'));
        $to      = $this->validDate($request->query('to'));

        $query = PropertyAdTemplate::query()->with('user:id,name');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
            });
        }

        if ($mine) {
            $query->where('user_id', $user->id);
        } elseif ($creator === 'ai') {
            $query->where('ai_generated', true);
        }
        if ($from) {
            $query->whereDate('updated_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('updated_at', '<=', $to);
        }

        // Creator sort uses a raw users lookup (not the User model) so no global scope
        // can silently drop a creator's row and scramble the order.
        match ($sort) {
            'name'    => $query->orderBy('name', $dir),
            'creator' => $query->orderBy(
                DB::table('users')->select('name')->whereColumn('users.id', 'property_ad_templates.user_id'),
                $dir
            ),
            'created' => $query->orderBy('created_at', $dir),
            default   => $query->orderBy('updated_at', $dir),
        };
        $query->orderBy('id', $dir); // deterministic tie-breaker

        $templates = $query->paginate(self::PER_PAGE)->withQueryString();

        // Per-row facts the view needs, computed once here so the Blade stays dumb.
        $templates->getCollection()->transform(function (PropertyAdTemplate $t) use ($user) {
            $layout   = (array) $t->layout_json;
            $variants = $layout['variants'] ?? [];
            $t->setAttribute('canvas_label', ! empty($layout['canvasW']) && ! empty($layout['canvasH'])
                ? ((int) $layout['canvasW'] . ' × ' . (int) $layout['canvasH']) : '—');
            $t->setAttribute('variant_count', is_array($variants) ? count($variants) : 0);
            $t->setAttribute('can_manage', $t->canBeManagedBy($user));

            return $t;
        });

        // "Is the library empty" (vs. "nothing matches") for the right empty state.
        $agencyHasAny = PropertyAdTemplate::withTrashed()->exists();

        return view('tools.ad-manager.templates', [
            'templates'    => $templates,
            'agencyHasAny' => $agencyHasAny,
            'filters'      => [
                'q' => $search, 'status' => $status, 'creator' => $creator,
                'sort' => $sort, 'dir' => $dir, 'from' => $from, 'to' => $to,
            ],
            'canBuild'     => $user->hasPermission('access_properties'),
        ] + $this->aiPanelData($user));
    }

    /**
     * POST api.v1.ad-manager.templates.ai.generate — one idea in, three validated options out.
     * Synchronous (QA2 has no queue worker). Every failure is a plain sentence the panel shows.
     */
    public function generateWithAi(Request $request, AiTemplateBuilderService $builder): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('access_properties'), 403);

        $data = $request->validate([
            'idea'            => ['required', 'string', 'max:' . AiTemplateBuilderService::IDEA_MAX_CHARS],
            'ad_size'         => ['required', 'string', 'in:' . implode(',', array_keys(AdLayoutValidator::SIZES))],
            'property_type'   => ['nullable', 'string', 'max:100'],       // legacy: context only, never a variant
            // §B.10 - the agency's own property types (checked against its list by the service) and the must-include ticks.
            'property_types'   => ['nullable', 'array', 'max:' . AdTemplatePropertyTypes::MAX_TYPES],
            'property_types.*' => ['string', 'max:100'],
            'must_include'     => ['nullable', 'array', 'max:' . count(AdTemplateMustIncludes::keys())],
            'must_include.*'   => ['string', Rule::in(AdTemplateMustIncludes::keys())],
            'ribbon_text'      => ['nullable', 'string', 'max:200'],
            'style'           => ['nullable', 'string', 'in:' . implode(',', array_merge([AdTemplateStyles::AUTO], AdTemplateStyles::keys()))],
            'match_existing'  => ['nullable', 'boolean'],
            'reference_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
        ], [
            'idea.required'            => 'Describe the look you want first.',
            'property_types.max'       => 'Please pick at most ' . AdTemplatePropertyTypes::MAX_TYPES . ' property types for one request.',
            'reference_image.max'      => 'The reference image must be 5 MB or smaller.',
            'reference_image.mimes'    => 'The reference image must be a JPG or PNG.',
            'reference_image.mimetypes' => 'The reference image must be a JPG or PNG.',
        ]);

        $file = $request->file('reference_image');
        if ($file !== null && @getimagesize($file->getRealPath()) === false) {
            return response()->json(['ok' => false, 'kind' => 'invalid', 'message' => 'That reference file is not a readable image.'], 422);
        }

        try {
            $generation = $builder->generate(
                $user, $data['idea'], $data['ad_size'], $data['property_type'] ?? null, $file,
                $data['style'] ?? AdTemplateStyles::AUTO, (bool) ($data['match_existing'] ?? false),
                (array) ($data['property_types'] ?? []), (array) ($data['must_include'] ?? []), $data['ribbon_text'] ?? null,
            );
        } catch (AiPausedException $e) {
            return response()->json(['ok' => false, 'kind' => 'paused', 'message' => AiPausedException::MESSAGE], 503);
        } catch (AiTemplateLimitReachedException $e) {
            return response()->json(['ok' => false, 'kind' => 'limit', 'message' => $e->getMessage()] + $this->usagePayload($builder, $user), 429);
        } catch (AiTemplateGenerationFailedException $e) {
            return response()->json(['ok' => false, 'kind' => 'failed', 'message' => $e->getMessage()] + $this->usagePayload($builder, $user), 422);
        }

        // The versions are listed in the order the types were ticked (the JSON column does not keep key order).
        $typeOrder = array_values((array) $generation->property_types_json);
        $options = [];
        foreach ((array) $generation->options_json as $i => $opt) {
            $styleKey  = $opt['style'] ?? null;
            $variants  = (array) ($opt['variants'] ?? []);
            $ordered   = [];
            foreach ($typeOrder as $type) {
                if (isset($variants[$type])) {
                    $ordered[$type] = $variants[$type];
                }
            }
            $options[] = ['index' => $i, 'name' => $opt['name'], 'layout' => $opt['layout'],
                'style' => $styleKey, 'style_name' => AdTemplateStyles::get($styleKey)['name'] ?? null,
                // §B.10 - its per-property-type versions and the "uses the main design" notes. Every design shown holds
                // everything that was ticked (decision 19), so there is nothing to mark as missing.
                'variants'      => (object) $ordered,
                'variant_notes' => (object) (array) ($opt['variant_notes'] ?? [])];
        }

        return response()->json([
            'ok'            => true,
            'generation_id' => $generation->id,
            'property_types' => array_values((array) $generation->property_types_json),
            'options'       => $options,
        ] + $this->usagePayload($builder, $user));
    }

    /**
     * POST api.v1.ad-manager.templates.ai.save — keep one of the options as a normal template.
     * The layout is read from the stored, already-validated generation; nothing is taken from
     * the browser but the option number and an optional name. AgencyScope makes another
     * agency's generation id a 404; another user's generation in the same agency is a 403.
     */
    public function saveAiOption(Request $request, AiTemplateGeneration $generation, AiTemplateBuilderService $builder): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('access_properties'), 403);
        abort_unless((int) $generation->user_id === (int) $user->id, 403);

        $data = $request->validate([
            'option' => ['required', 'integer', 'min:0', 'max:' . (AiTemplateBuilderService::OPTIONS - 1)],
            'name'   => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $template = $builder->saveOption($generation, (int) $data['option'], $user, $data['name'] ?? null);
        } catch (AiTemplateGenerationFailedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'       => true,
            'id'       => $template->id,
            'name'     => $template->name,
            'edit_url' => route('corex.ad-templates.builder.edit', [$template->id, 'from' => 'templates']),
        ]);
    }

    /** @return array<string, mixed> */
    private function usagePayload(AiTemplateBuilderService $builder, $user): array
    {
        $usage = $builder->usage((int) $user->effectiveAgencyId());

        return ['usage' => [
            'limit' => $usage['limit'], 'used' => $usage['used'], 'remaining' => $usage['remaining'],
            'resets_on' => $usage['resets_at']->format('j F Y'),
        ]];
    }

    /**
     * Everything the "Create with AI" panel needs on first paint: this month's allowance, the
     * agency's own property types, the ad sizes, and a SAMPLE property the previews render
     * against (the user's own details + the agency's own name/logo — never anyone else's).
     *
     * @return array<string, mixed>
     */
    private function aiPanelData($user): array
    {
        $canUseAi = $user->hasPermission('access_properties') && $user->hasPermission('access_ad_manager');
        if (! $canUseAi || ! $user->effectiveAgencyId()) {
            return ['aiPanel' => null];
        }

        $agencyId = (int) $user->effectiveAgencyId();
        $usage    = app(AiTemplateBuilderService::class)->usage($agencyId);

        return ['aiPanel' => [
            'usage' => [
                'limit' => $usage['limit'], 'used' => $usage['used'], 'remaining' => $usage['remaining'],
                'resets_on' => $usage['resets_at']->format('j F Y'),
            ],
            'styles' => AdTemplateStyles::forPanel(),
            // "Match our existing templates": offered (and ticked by default) only when the agency has at least one saved,
            // non-archived template of its own. Pinned to the agency explicitly - an owner-role user is not scope-limited.
            'templateCount' => PropertyAdTemplate::query()->where('agency_id', $agencyId)->count(),
            'sizes' => collect(AdLayoutValidator::SIZES)->map(fn ($s, $k) => ['key' => $k, 'label' => $s['label'], 'w' => $s['w'], 'h' => $s['h']])->values()->all(),
            // The agency's OWN active Property Type list (the one the property form uses) - pinned to the agency in the query.
            'propertyTypes' => $propertyTypes = AdTemplatePropertyTypes::forAgency($agencyId),
            // Per property type: the demo photos that fit it (a land version opens on a lawn, a flat on an apartment block).
            'typePhotos'    => AdTemplateDemoPhotos::forTypes($propertyTypes),
            'maxTypes'      => AdTemplatePropertyTypes::MAX_TYPES,
            'mustIncludes'  => AdTemplateMustIncludes::forPanel(),
            'ribbonMax'     => AdLayoutValidator::MAX_RIBBON_TEXT,
            'hasLogo'       => (bool) Agency::query()->whereKey($agencyId)->value('logo_path'),
            'sample' => $this->samplePropertyData($user, Agency::query()->find($agencyId)),
        ]];
    }

    /**
     * A made-up listing for the previews. Property photos are generic licensed demo photos (AdTemplateDemoPhotos);
     * agency name, website and logo are the user's own agency's; the agent is the signed-in user.
     *
     * @return array<string, mixed>
     */
    private function samplePropertyData($user, ?Agency $agency): array
    {
        $logoPath = $agency?->logo_path;
        $name     = (string) ($agency?->name ?? '');

        // image_1 ... image_5: generic licensed demo photos (public/images/ad-demo) - never any agency's own listing photos.
        return AdTemplateDemoPhotos::set() + [
            'price' => 'R 2 450 000', 'title' => 'MODERN FAMILY HOME', 'suburb' => 'SAMPLE SUBURB, YOUR CITY',
            'property_type' => 'HOUSE', 'property_type_raw' => 'House',
            'features' => '4 Bed · 3 Bath · 2 Garage', 'features_list' => ['Pool', 'Garden', 'Security'],
            'beds' => '4', 'baths' => '3', 'garages' => '2', 'parking' => '2',
            'size_m2' => '320 M²', 'floor_size_m2' => '320', 'land_size_m2' => '800',
            'reference' => 'REF 12345', 'address' => '12 Sample Street', 'status_badge' => 'FOR SALE',
            'agent_name' => strtoupper((string) $user->name), 'agent_email' => (string) $user->email,
            'agent_phone' => (string) ($user->cell ?: $user->phone ?: ''),
            'agent_designation' => (string) ($user->designation ?: 'Property Practitioner'),
            'agent_avatar' => Property::adSafeImageUrl($user->profilePhotoUrl()),
            'agency_name' => $name, 'website' => (string) ($agency?->website_url ?? ''),
            'logo' => $logoPath ? Property::adSafeImageUrl(asset('storage/' . $logoPath)) : null,
            'watermark' => strtoupper($name),
            // AT-456 §B.9-1 — neutral made-up values for the new facts, so a preview of a template that uses them
            // looks sensible. (A rental-only fact is left '' here; the preview then shows the element's own sample text.)
            'erf_size' => '800 m²', 'levy' => 'R 1 250 pm', 'rates_taxes' => 'R 900 pm', 'price_per_m2' => 'R 7 656 per m²',
            'pet_friendly' => 'Pet friendly', 'available_from' => '', 'rent_per_month' => '',
            'show_day' => now()->next('Saturday')->format('D j M') . ' · 10:00–13:00',
            'auction_date' => now()->addWeeks(3)->format('j M Y') . ' · 11:00', 'auction_venue' => 'Sample Venue, Sample Town',
            // The QR on a preview points at the agency's OWN website when it has one; otherwise the kernel draws its neutral sample code.
            'qr_matrix' => ListingQrMatrix::forUrl((string) ($agency?->website_url ?? '')),
        ];
    }

    public function archive(PropertyAdTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($template);
        $template->delete(); // SoftDeletes — never a hard delete (non-negotiable #1)

        return back()->with('success', '"' . $template->name . '" archived. Restore it any time from the Archived filter.');
    }

    public function restore(PropertyAdTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($template);
        $template->restore();

        return back()->with('success', '"' . $template->name . '" restored — it is back in the template pickers.');
    }

    private function authorizeTemplate(PropertyAdTemplate $template): void
    {
        if (! $template->canBeManagedBy(auth()->user())) {
            abort(403);
        }
    }

    private function validDate(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
}
