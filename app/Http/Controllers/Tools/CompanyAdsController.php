<?php

namespace App\Http\Controllers\Tools;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\AiTemplateGenerationFailedException;
use App\Exceptions\AI\AiTemplateLimitReachedException;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AiTemplateGeneration;
use App\Models\CompanyAd;
use App\Models\CompanyAdImage;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AdTemplateStyles;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\AdTemplates\CompanyAdBuilderService;
use App\Services\AdTemplates\CompanyAdLayout;
use App\Services\AdTemplates\CompanyAdLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Company Ads — the second tab of the Template Manager (Tools → Ad Manager → Template Manager → Company ads).
 * Spec: .ai/specs/company-ads.md (E-1: list, CRUD, Ad Builder company mode, uploads, library, download;
 * E-2: the "Create company ad with AI" pop-up - generateWithAi / saveAiOption;
 * E-3: Post now / Schedule from the list, through the Post Scheduler's own endpoints).
 *
 * Scoping: CompanyAd / CompanyAdImage are BelongsToAgency, so every query and every route-model lookup here is
 * confined to the user's agency at the query layer — another agency's id is a 404. Company ads are agency-wide
 * brand assets: the list is the whole agency (no own/branch narrowing, spec §5). Changing one needs
 * `ad_manager.company_ads` (route middleware + re-checked here).
 */
class CompanyAdsController extends Controller
{
    private const PER_PAGE = 15;

    private const SORTS = ['name', 'occasion', 'created', 'updated'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search   = trim((string) $request->query('q', ''));
        $status   = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? $request->query('status') : 'active';
        $creator  = in_array($request->query('creator'), ['mine', 'ai'], true) ? $request->query('creator') : 'all';
        $occasion = (string) $request->query('occasion', '');
        if ($occasion !== 'other' && ! CompanyAdLibrary::isOccasion($occasion)) {
            $occasion = '';
        }
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'updated';
        $dir  = in_array($request->query('dir'), ['asc', 'desc'], true)
            ? $request->query('dir')
            : (in_array($sort, ['updated', 'created'], true) ? 'desc' : 'asc');
        $from = $this->validDate($request->query('from'));
        $to   = $this->validDate($request->query('to'));

        $occasions = CompanyAdLibrary::occasions();

        $query = CompanyAd::query()->with('user:id,name');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            // Occasion is stored as a key; search matches its label.
            $occasionKeys = array_keys(array_filter($occasions, fn ($label) => Str::contains(mb_strtolower($label), mb_strtolower($search))));
            $query->where(function ($q) use ($like, $occasionKeys) {
                $q->where('name', 'like', $like)
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
                if ($occasionKeys) {
                    $q->orWhereIn('occasion', $occasionKeys);
                }
            });
        }

        if ($occasion === 'other') {
            $query->whereNull('occasion');
        } elseif ($occasion !== '') {
            $query->where('occasion', $occasion);
        }

        if ($creator === 'mine') {
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

        match ($sort) {
            'name'     => $query->orderBy('name', $dir),
            // Calendar order of the library (Other first ascending), not the alphabetical order of the keys.
            'occasion' => $occasions
                ? $query->orderByRaw('FIELD(occasion, ' . implode(',', array_fill(0, count($occasions), '?')) . ') ' . $dir, array_keys($occasions))
                : $query->orderBy('occasion', $dir),
            'created'  => $query->orderBy('created_at', $dir),
            default    => $query->orderBy('updated_at', $dir),
        };
        $query->orderBy('id', $dir);

        $ads = $query->paginate(self::PER_PAGE)->withQueryString();

        $ads->getCollection()->transform(function (CompanyAd $ad) {
            $ad->setAttribute('occasion_label', CompanyAdLibrary::label($ad->occasion));
            $ad->setAttribute('canvas_label', CompanyAdLayout::canvasLabel($ad));

            return $ad;
        });

        $agency = Agency::find($user->effectiveAgencyId());

        return view('tools.ad-manager.company-ads', [
            'ads'          => $ads,
            'agencyHasAny' => CompanyAd::withTrashed()->exists(),
            'filters'      => [
                'q' => $search, 'status' => $status, 'creator' => $creator, 'occasion' => $occasion,
                'sort' => $sort, 'dir' => $dir, 'from' => $from, 'to' => $to,
            ],
            'occasions'    => $occasions,
            'canManage'    => CompanyAd::userMayManage($user),
            'brandData'    => CompanyAdLayout::brandData($user, $agency),
            'aiPanel'      => $this->aiPanelData($user, $agency),
            // E-3 — Post now / Schedule: needs the company-ads key AND the scheduling key (company-ads.md §7, §9).
            'postConfig'   => CompanyAd::userMayManage($user) && $user->hasPermission('ad_manager.schedule_posts')
                ? app(\App\Services\Marketing\ScheduledPostService::class)->panelConfig($user) : null,
        ]);
    }

    /**
     * POST api.v1.ad-manager.company-ads.ai.generate (spec §8). One request = one unit of the agency's shared monthly
     * allowance (only when at least one design comes back).
     */
    public function generateWithAi(Request $request, CompanyAdBuilderService $builder, AiTemplateBuilderService $allowance): JsonResponse
    {
        $this->assertMayManage($request);
        $user = $request->user();

        $data = $request->validate([
            'idea'                => ['required', 'string', 'max:' . AiTemplateBuilderService::IDEA_MAX_CHARS],
            'occasion'            => ['nullable', 'string', Rule::in(array_keys(CompanyAdLibrary::occasions()))],
            'ad_size'             => ['required', 'string', Rule::in(array_keys(CompanyAdLayout::CANVAS_SIZES))],
            'style'               => ['nullable', 'string', Rule::in(array_merge([AdTemplateStyles::AUTO], AdTemplateStyles::keys()))],
            'picture'             => ['required', 'string', Rule::in(CompanyAdBuilderService::MODES)],
            'library_picture'     => ['nullable', 'required_if:picture,' . CompanyAdBuilderService::MODE_LIBRARY, 'string', 'max:255'],
            'company_ad_image_id' => ['nullable', 'required_if:picture,' . CompanyAdBuilderService::MODE_PLACE, 'integer'],
            'look_image'          => ['nullable', 'required_if:picture,' . CompanyAdBuilderService::MODE_LOOK, 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
            'must_include'        => ['nullable', 'array', 'max:' . count(CompanyAdBuilderService::MUST_INCLUDES)],
            'must_include.*'      => ['string', Rule::in(array_keys(CompanyAdBuilderService::MUST_INCLUDES))],
        ], [
            'idea.required'                   => 'Say what the ad is for first, for example "Merry Christmas from all of us".',
            'library_picture.required_if'     => 'Please choose a picture from the library.',
            'company_ad_image_id.required_if' => 'Please upload the picture to put in your ad.',
            'look_image.required_if'          => 'Please upload the post whose look you want to copy.',
            'look_image.max'                  => 'The picture must be 5 MB or smaller.',
            'look_image.mimes'                => 'The picture must be a JPG or PNG.',
            'look_image.mimetypes'            => 'The picture must be a JPG or PNG.',
        ]);

        $file = $request->file('look_image');
        if ($file !== null && @getimagesize($file->getRealPath()) === false) {
            return response()->json(['ok' => false, 'kind' => 'invalid', 'message' => 'That file is not a readable picture.'], 422);
        }

        try {
            $generation = $builder->generate(
                $user, $data['idea'], $data['ad_size'], $data['occasion'] ?? null, $data['picture'],
                isset($data['company_ad_image_id']) ? (int) $data['company_ad_image_id'] : null,
                $data['library_picture'] ?? null, $file,
                $data['style'] ?? AdTemplateStyles::AUTO, (array) ($data['must_include'] ?? []),
            );
        } catch (AiPausedException $e) {
            return response()->json(['ok' => false, 'kind' => 'paused', 'message' => AiPausedException::MESSAGE], 503);
        } catch (AiTemplateLimitReachedException $e) {
            return response()->json(['ok' => false, 'kind' => 'limit', 'message' => $e->getMessage()] + $this->usagePayload($allowance, $user), 429);
        } catch (AiTemplateGenerationFailedException $e) {
            return response()->json(['ok' => false, 'kind' => 'failed', 'message' => $e->getMessage()] + $this->usagePayload($allowance, $user), 422);
        }

        $options = [];
        foreach ((array) $generation->options_json as $i => $opt) {
            $options[] = ['index' => $i, 'name' => $opt['name'], 'layout' => $opt['layout'], 'caption' => (string) ($opt['caption'] ?? ''),
                'style' => $opt['style'] ?? null, 'style_name' => AdTemplateStyles::get($opt['style'] ?? null)['name'] ?? null];
        }

        return response()->json(['ok' => true, 'generation_id' => $generation->id, 'options' => $options] + $this->usagePayload($allowance, $user));
    }

    /**
     * POST api.v1.ad-manager.company-ads.ai.save — keep one design as a company ad. The browser sends only the design's
     * number, a name and the (edited) caption; the layout is the one the server stored.
     */
    public function saveAiOption(Request $request, AiTemplateGeneration $generation, CompanyAdBuilderService $builder): JsonResponse
    {
        $this->assertMayManage($request);
        $user = $request->user();
        abort_unless((int) $generation->user_id === (int) $user->id, 403);
        abort_unless($generation->kind === AiTemplateGeneration::KIND_COMPANY_AD, 404);

        $data = $request->validate([
            'option'  => ['required', 'integer', 'min:0', 'max:' . (CompanyAdBuilderService::OPTIONS - 1)],
            'name'    => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $ad = $builder->saveOption($generation, (int) $data['option'], $user, $data['name'] ?? null, array_key_exists('caption', $data) ? (string) $data['caption'] : null);
        } catch (AiTemplateGenerationFailedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'       => true,
            'id'       => $ad->id,
            'name'     => $ad->name,
            'caption'  => (string) $ad->caption,
            'layout'   => $ad->layout_json,
            'edit_url' => route('tools.ad-manager.company-ads.builder.edit', $ad->id),
        ]);
    }

    /** @return array<string, mixed> */
    private function usagePayload(AiTemplateBuilderService $allowance, $user): array
    {
        $usage = $allowance->usage((int) $user->effectiveAgencyId());

        return ['usage' => [
            'limit' => $usage['limit'], 'used' => $usage['used'], 'remaining' => $usage['remaining'],
            'resets_on' => $usage['resets_at']->format('j F Y'),
        ]];
    }

    /**
     * What the "Create company ad with AI" pop-up needs on first paint, or null when this user may not use it. The previews
     * render with the requesting agency's own brand and the signed-in user (CompanyAdLayout::brandData).
     *
     * @return array<string, mixed>|null
     */
    private function aiPanelData($user, ?Agency $agency): ?array
    {
        if (! CompanyAd::userMayManage($user) || ! $agency) {
            return null;
        }
        $usage = app(AiTemplateBuilderService::class)->usage((int) $agency->id);

        return [
            'usage'        => [
                'limit' => $usage['limit'], 'used' => $usage['used'], 'remaining' => $usage['remaining'],
                'resets_on' => $usage['resets_at']->format('j F Y'),
            ],
            'styles'       => AdTemplateStyles::forPanel(),
            'sizes'        => collect(CompanyAdLayout::CANVAS_SIZES)->map(fn ($wh, $k) => ['key' => $k, 'label' => AdLayoutValidator::SIZES[$k]['label'], 'w' => $wh[0], 'h' => $wh[1]])->values()->all(),
            'occasions'    => CompanyAdLibrary::occasions(),
            'library'      => CompanyAdLibrary::grouped(),
            'mustIncludes' => CompanyAdBuilderService::mustIncludesForPanel(),
            'hasContact'   => CompanyAdBuilderService::contactFields($agency) !== [],
            'captionMax'   => CompanyAdBuilderService::CAPTION_MAX,
        ];
    }

    /** The Ad Builder in company mode (spec §6) — the same editor and render kernel as property templates. */
    public function builder(Request $request, ?CompanyAd $companyAd = null)
    {
        $this->assertMayManage($request);

        $agency = Agency::find($request->user()->effectiveAgencyId());

        return view('corex.properties.ad-builder', [
            'template'            => $companyAd,
            'property'            => null,
            'propertyData'        => CompanyAdLayout::brandData($request->user(), $agency),
            'propertyTypeOptions' => [],   // no property-type variants on a company ad
            'fromTemplates'       => false,
            'companyMode'         => true,
            'companyFields'       => CompanyAdLayout::ALLOWED_FIELDS,
            'companyCanvases'     => array_keys(CompanyAdLayout::CANVAS_SIZES),
            'companyLibrary'      => CompanyAdLibrary::grouped(),
            'companyOccasions'    => CompanyAdLibrary::occasions(),
            'companyOccasion'     => $companyAd?->occasion ?? (CompanyAdLibrary::isOccasion($request->query('occasion')) ? $request->query('occasion') : null),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertMayManage($request);
        $data = $this->validated($request);

        [$layout, $canvas] = CompanyAdLayout::sanitize($data['layout_json'], (int) $request->user()->effectiveAgencyId());

        $ad = CompanyAd::create([
            'user_id'     => $request->user()->id,
            'name'        => $data['name'],
            'occasion'    => $data['occasion'] ?? null,
            'canvas'      => $canvas,
            'layout_json' => $layout,
        ]);

        return response()->json(['id' => $ad->id, 'name' => $ad->name]);
    }

    public function update(Request $request, CompanyAd $companyAd): JsonResponse
    {
        $this->assertMayManage($request);
        $data = $this->validated($request);

        [$layout, $canvas] = CompanyAdLayout::sanitize($data['layout_json'], (int) $companyAd->agency_id);

        $companyAd->update([
            'name'        => $data['name'],
            'occasion'    => $data['occasion'] ?? null,
            'canvas'      => $canvas,
            'layout_json' => $layout,
        ]);

        return response()->json(['id' => $companyAd->id, 'name' => $companyAd->name]);
    }

    /** Copy an ad (the usual way to reuse last year's Christmas ad). The copy belongs to whoever made it. */
    public function duplicate(Request $request, CompanyAd $companyAd): RedirectResponse
    {
        $this->assertMayManage($request);

        $copy = CompanyAd::create([
            'user_id'      => $request->user()->id,
            'name'         => Str::limit('Copy of ' . $companyAd->name, 100, ''),
            'occasion'     => $companyAd->occasion,
            'canvas'       => $companyAd->canvas,
            'layout_json'  => $companyAd->layout_json,
            'caption'      => $companyAd->caption,
            'ai_generated' => false,
        ]);

        return redirect()->route('tools.ad-manager.company-ads.builder.edit', $copy->id)
            ->with('success', '"' . $companyAd->name . '" copied. You are now editing the copy.');
    }

    public function archive(Request $request, CompanyAd $companyAd): RedirectResponse
    {
        $this->assertMayManage($request);
        $companyAd->delete(); // SoftDeletes — never a hard delete (non-negotiable #1)

        return back()->with('success', '"' . $companyAd->name . '" archived. Restore it any time from the Archived filter.');
    }

    public function restore(Request $request, CompanyAd $companyAd): RedirectResponse
    {
        $this->assertMayManage($request);
        $companyAd->restore();

        return back()->with('success', '"' . $companyAd->name . '" restored.');
    }

    /**
     * POST api.v1.ad-manager.company-ads.images — a picture to PLACE in a company ad (spec §4).
     * Decoded and re-encoded server-side (strips anything that is not pixels), capped at 2400 px.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $this->assertMayManage($request);

        $request->validate([
            'file' => 'required|file|max:8192|mimetypes:image/jpeg,image/png,image/webp',
        ]);

        $file = $request->file('file');
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return response()->json(['ok' => false, 'message' => 'That file is not a picture we can read. Please use a JPG, PNG or WebP.'], 422);
        }
        if ($info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 40_000_000) {
            return response()->json(['ok' => false, 'message' => 'That picture is too large. Please use one under 8000 pixels on each side.'], 422);
        }

        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            return response()->json(['ok' => false, 'message' => 'That file is not a picture we can read. Please use a JPG, PNG or WebP.'], 422);
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, 2400 / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        // Flatten any transparency onto white (JPEG has no alpha).
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($out, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($out);

        $agencyId = (int) $request->user()->effectiveAgencyId();
        $path     = 'company-ads/' . $agencyId . '/' . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, $bytes);

        $image = CompanyAdImage::create([
            'user_id' => $request->user()->id,
            'path'    => $path,
            'width'   => $nw,
            'height'  => $nh,
        ]);

        return response()->json(['ok' => true, 'id' => $image->id, 'url' => $image->url(), 'width' => $nw, 'height' => $nh]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:100',
            'occasion'    => ['nullable', 'string', Rule::in(array_keys(CompanyAdLibrary::occasions()))],
            'layout_json' => 'required|array',
        ]);
    }

    private function assertMayManage(Request $request): void
    {
        abort_unless(CompanyAd::userMayManage($request->user()), 403);
    }

    private function validDate(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
}
