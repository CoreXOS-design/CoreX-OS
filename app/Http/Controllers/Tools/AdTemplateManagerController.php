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
use App\Models\PropertySettingItem;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AiTemplateBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * POST tools.ad-manager.templates.ai.generate — one idea in, three validated options out.
     * Synchronous (QA2 has no queue worker). Every failure is a plain sentence the panel shows.
     */
    public function generateWithAi(Request $request, AiTemplateBuilderService $builder): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('access_properties'), 403);

        $data = $request->validate([
            'idea'            => ['required', 'string', 'max:' . AiTemplateBuilderService::IDEA_MAX_CHARS],
            'ad_size'         => ['required', 'string', 'in:' . implode(',', array_keys(AdLayoutValidator::SIZES))],
            'property_type'   => ['nullable', 'string', 'max:100'],
            'reference_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
        ], [
            'idea.required'            => 'Describe the look you want first.',
            'reference_image.max'      => 'The reference image must be 5 MB or smaller.',
            'reference_image.mimes'    => 'The reference image must be a JPG or PNG.',
            'reference_image.mimetypes' => 'The reference image must be a JPG or PNG.',
        ]);

        $file = $request->file('reference_image');
        if ($file !== null && @getimagesize($file->getRealPath()) === false) {
            return response()->json(['ok' => false, 'kind' => 'invalid', 'message' => 'That reference file is not a readable image.'], 422);
        }

        try {
            $generation = $builder->generate($user, $data['idea'], $data['ad_size'], $data['property_type'] ?? null, $file);
        } catch (AiPausedException $e) {
            return response()->json(['ok' => false, 'kind' => 'paused', 'message' => AiPausedException::MESSAGE], 503);
        } catch (AiTemplateLimitReachedException $e) {
            return response()->json(['ok' => false, 'kind' => 'limit', 'message' => $e->getMessage()] + $this->usagePayload($builder, $user), 429);
        } catch (AiTemplateGenerationFailedException $e) {
            return response()->json(['ok' => false, 'kind' => 'failed', 'message' => $e->getMessage()] + $this->usagePayload($builder, $user), 422);
        }

        $options = [];
        foreach ((array) $generation->options_json as $i => $opt) {
            $options[] = ['index' => $i, 'name' => $opt['name'], 'layout' => $opt['layout']];
        }

        return response()->json([
            'ok'            => true,
            'generation_id' => $generation->id,
            'options'       => $options,
        ] + $this->usagePayload($builder, $user));
    }

    /**
     * POST tools.ad-manager.templates.ai.save — keep one of the options as a normal template.
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
            'sizes' => collect(AdLayoutValidator::SIZES)->map(fn ($s, $k) => ['key' => $k, 'label' => $s['label']])->values()->all(),
            'propertyTypes' => PropertySettingItem::group(PropertySettingItem::GROUP_TYPE)
                ->where('active', true)->orderBy('sort_order')->pluck('name')->all(),
            'sample' => $this->samplePropertyData($user, Agency::query()->find($agencyId)),
        ]];
    }

    /**
     * A made-up listing for the previews. Property photos are neutral generated placeholders;
     * agency name, website and logo are the user's own agency's; the agent is the signed-in user.
     *
     * @return array<string, mixed>
     */
    private function samplePropertyData($user, ?Agency $agency): array
    {
        $photo = function (string $from, string $to) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">'
                . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $from . '"/><stop offset="1" stop-color="' . $to . '"/></linearGradient></defs>'
                . '<rect width="1200" height="800" fill="url(#g)"/>'
                . '<path d="M600 230 380 420h60v200h130V500h60v120h130V420h60z" fill="#ffffff" fill-opacity="0.55"/></svg>';

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        };

        $logoPath = $agency?->logo_path;
        $name     = (string) ($agency?->name ?? '');

        return [
            'image_1' => $photo('#8aa4b8', '#4a6073'), 'image_2' => $photo('#b9a98a', '#6e5f45'),
            'image_3' => $photo('#8fb59b', '#47684f'), 'image_4' => $photo('#a89bc0', '#5b4f78'),
            'image_5' => $photo('#c09b9b', '#774a4a'),
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
