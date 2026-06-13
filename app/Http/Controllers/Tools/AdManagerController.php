<?php

namespace App\Http\Controllers\Tools;

use App\Exceptions\AiCopyUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Services\MarketingCopyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk Ad Manager (Tools → Ad Manager). Spec: .ai/specs/ad-manager.md §10b.
 *
 * Select many properties (optionally agent-by-agent), pick a template, and
 * generate an ad per property: a rendered image (download) + a grounded AI
 * description (copy). Scope is permission-driven:
 *   - access_ad_manager       → can use the page
 *   - ad_manager.all_agents    → ads for ANY agency agent; without it, own only
 */
class AdManagerController extends Controller
{
    /** Platform sizes the ad can be generated at (w/h in px, base font for em scaling). */
    private function platforms(): array
    {
        return [
            'facebook'  => ['label' => 'Facebook — 1200 × 628',  'w' => 1200, 'h' => 628,  'base' => 16],
            'instagram' => ['label' => 'Instagram — 1080 × 1080', 'w' => 1080, 'h' => 1080, 'base' => 28],
            'story'     => ['label' => 'Story — 1080 × 1920',     'w' => 1080, 'h' => 1920, 'base' => 50],
            'whatsapp'  => ['label' => 'WhatsApp — 900 × 900',    'w' => 900,  'h' => 900,  'base' => 23],
        ];
    }

    /** Pre-built template catalogue — mirrors corex/properties/ad.blade.php. */
    private function prebuiltTemplates(): array
    {
        return [
            ['key' => 'power',           'name' => 'Power'],
            ['key' => 'luxe',            'name' => 'Luxe'],
            ['key' => 'split',           'name' => 'Split'],
            ['key' => 'just_listed',     'name' => 'Just Listed'],
            ['key' => 'open_house',      'name' => 'Open House'],
            ['key' => 'editorial',       'name' => 'Editorial'],
            ['key' => 'feature_grid',    'name' => 'Feature Grid'],
            ['key' => 'price_spotlight', 'name' => 'Price Spotlight'],
            ['key' => 'coming_soon',     'name' => 'Coming Soon'],
            ['key' => 'sold',            'name' => 'Sold / Under Offer'],
            ['key' => 'for_rent',        'name' => 'For Rent'],
            ['key' => 'agent_spotlight', 'name' => 'Agent Spotlight'],
            ['key' => 'showcase',        'name' => 'Showcase'],
        ];
    }

    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user      = auth()->user();
        $allAgents = $user->hasPermission('ad_manager.all_agents');

        // Only ACTIVE listings that are LIVE somewhere (company website / P24 / PP) —
        // never drafts, sold or rented. "Live somewhere" mirrors Property::portalLinks().
        $websiteLiveIds = \App\Models\PropertyWebsiteSyndication::where('enabled', true)
            ->pluck('property_id')->all();

        // Property queries are agency-scoped (AgencyScope), so an all-agents user
        // gets every property in THEIR agency; an own-only user gets their own.
        $query = Property::with('agent:id,name')
            ->whereNotIn('status', ['Sold', 'sold', 'Rented', 'rented', 'Draft', 'draft', 'Withdrawn', 'withdrawn', 'Expired', 'expired', 'Cancelled', 'cancelled', 'Archived', 'archived'])
            ->where(function ($q) use ($websiteLiveIds) {
                $q->where(function ($w) {
                    $w->whereNotNull('p24_ref')->where('p24_ref', '<>', '')->where('p24_syndication_status', 'active');
                })->orWhere(function ($w) {
                    $w->whereNotNull('pp_ref')->where('pp_ref', '<>', '')->where('pp_syndication_status', 'active');
                });
                if (! empty($websiteLiveIds)) {
                    $q->orWhereIn('properties.id', $websiteLiveIds);
                }
            })
            ->orderBy('title');

        if (! $allAgents) {
            $query->where('agent_id', $user->id);
        }

        $props = $query->get()->map(function (Property $p) {
            $imgs = $p->displayImages();
            return [
                'id'         => $p->id,
                'title'      => $p->title,
                'address'    => trim((string) ($p->street_address ?? $p->address ?? '')),
                'suburb'     => trim(((string) $p->suburb) . ($p->city ? ', ' . $p->city : ''), ', '),
                'price'      => $p->price ? 'R ' . number_format((int) $p->price, 0, '.', ' ') : 'POA',
                'agent_id'   => (int) $p->agent_id,
                'agent_name' => $p->agent?->name ?? 'Unassigned',
                'thumb'      => $imgs[0] ?? null,
            ];
        })->values();

        // Agents present among these properties, for the agent-by-agent grouping.
        $agents = $allAgents
            ? $props->groupBy('agent_id')
                ->map(fn ($g) => ['id' => $g->first()['agent_id'], 'name' => $g->first()['agent_name'], 'count' => $g->count()])
                ->sortBy('name')->values()
            : collect();

        $customTemplates = PropertyAdTemplate::orderByDesc('updated_at')
            ->get(['id', 'name', 'layout_json'])
            ->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'layout_json' => $t->layout_json])
            ->values();

        return view('tools.ad-manager', [
            'allAgents'       => $allAgents,
            'properties'      => $props,
            'agents'          => $agents,
            'prebuilt'        => $this->prebuiltTemplates(),
            'customTemplates' => $customTemplates,
            'platforms'       => $this->platforms(),
        ]);
    }

    /**
     * Render every pre-built template for ONE property (the first selected) so the
     * template picker can show real thumbnails. No AI — just the template images.
     */
    public function previews(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => 'required|integer',
            'platform'    => 'sometimes|string',
        ]);

        /** @var \App\Models\User $user */
        $user      = auth()->user();
        $allAgents = $user->hasPermission('ad_manager.all_agents');

        $platforms = $this->platforms();
        $plat      = $platforms[$data['platform'] ?? 'facebook'] ?? $platforms['facebook'];

        $p = Property::with(['agent', 'branch', 'agency'])->find($data['property_id']);
        if (! $p) {
            abort(404);
        }
        if (! $allAgents && (int) $p->agent_id !== (int) $user->id) {
            abort(403);
        }

        $vars     = $p->adTemplateVars();
        $prebuilt = [];
        foreach ($this->prebuiltTemplates() as $t) {
            $prebuilt[$t['key']] = view('corex.properties._ad-templates', array_merge(
                ['tpl' => $t['key'], 'baseFontPx' => $plat['base']],
                $vars,
            ))->render();
        }

        return response()->json([
            'ok'       => true,
            'prebuilt' => $prebuilt,
            'data'     => $p->adData(),
            'canvas'   => ['w' => $plat['w'], 'h' => $plat['h']],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_ids'   => 'required|array|min:1|max:50',
            'property_ids.*' => 'integer',
            'template'       => 'required|string|max:60',
            'emojis'         => 'sometimes|boolean',
            'platform'       => 'sometimes|string',
        ]);

        /** @var \App\Models\User $user */
        $user      = auth()->user();
        $allAgents = $user->hasPermission('ad_manager.all_agents');
        $emojis    = (bool) ($data['emojis'] ?? false);
        $tpl       = $data['template'];

        $platforms = $this->platforms();
        $plat      = $platforms[$data['platform'] ?? 'facebook'] ?? $platforms['facebook'];

        // Custom template (numeric id) vs pre-built (known key). AgencyScope keeps
        // a custom template within the current agency.
        $customLayout = null;
        if (ctype_digit((string) $tpl)) {
            $custom = PropertyAdTemplate::find((int) $tpl);
            if (! $custom) {
                abort(404, 'Template not found.');
            }
            $customLayout = $custom->layout_json;
        } elseif (! in_array($tpl, array_column($this->prebuiltTemplates(), 'key'), true)) {
            abort(422, 'Unknown template.');
        }

        // AgencyScope limits these to the current agency already.
        $props = Property::with(['agent', 'branch', 'agency'])
            ->whereIn('id', $data['property_ids'])
            ->orderBy('title')
            ->get();

        $svc     = app(MarketingCopyService::class);
        $results = [];

        foreach ($props as $p) {
            // Server-side scope enforcement — own-only users cannot advertise
            // another agent's listings even if a client sends the id.
            if (! $allAgents && (int) $p->agent_id !== (int) $user->id) {
                continue;
            }

            $row = [
                'id'          => $p->id,
                'title'       => $p->title,
                'agent_name'  => $p->agent?->name ?? 'Unassigned',
                'description' => null,
                'ai_error'    => null,
            ];

            if ($customLayout !== null) {
                $row['custom'] = true;
                $row['layout'] = $customLayout;
                $row['data']   = $p->adData();
                $row['cw']     = (int) ($customLayout['canvasW'] ?? $plat['w']);
                $row['ch']     = (int) ($customLayout['canvasH'] ?? $plat['h']);
            } else {
                $row['html'] = view('corex.properties._ad-templates', array_merge(
                    ['tpl' => $tpl, 'baseFontPx' => $plat['base']],
                    $p->adTemplateVars(),
                ))->render();
                $row['cw'] = $plat['w'];
                $row['ch'] = $plat['h'];
            }

            try {
                $copy               = $svc->generateAdCopy($p, 'facebook', $emojis);
                $row['description'] = $copy['primary'];
            } catch (AiCopyUnavailableException $e) {
                $row['ai_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                $row['ai_error'] = "Couldn't generate the description for this one — try again.";
            }

            $results[] = $row;
        }

        if (empty($results)) {
            return response()->json([
                'ok'    => false,
                'error' => 'None of the selected properties are yours to advertise.',
            ], 403);
        }

        return response()->json(['ok' => true, 'results' => $results]);
    }
}
