<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceType;
use App\Models\DealV2\AgencyServiceTypeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-229 — the agency's COC / service-type list settings screen.
 *
 * Agency-scoped CRUD (add / edit / soft-delete / restore). The work-order
 * "service type" dropdown in the pipeline step-config reads from this list
 * instead of a hardcoded array. AgencyScope keeps each agency to its own list.
 */
class AgencyServiceTypeController extends Controller
{
    public function index(Request $request): View
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        if ($agencyId > 0) {
            AgencyServiceTypeGroup::seedDefaultsFor($agencyId);   // the two default groups; never re-creates a service type the agency archived
        }

        $groups = AgencyServiceTypeGroup::orderBy('sort_order')->orderBy('id')->get();
        $archivedGroups = AgencyServiceTypeGroup::onlyTrashed()->orderBy('sort_order')->orderBy('id')->get();

        // list: filter by group, sort by order / name / group / stored value (default = the agency's own order)
        $groupFilter = ctype_digit((string) $request->query('group', '')) ? (int) $request->query('group') : null;
        $sort = in_array($request->query('sort'), ['order', 'label', 'group', 'code'], true) ? $request->query('sort') : 'order';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $q = AgencyServiceType::query()->with('group');
        if ($groupFilter) {
            $q->where('group_id', $groupFilter);
        }
        match ($sort) {
            'label' => $q->orderBy('label', $dir),
            'code' => $q->orderBy('code', $dir),
            'group' => $q->orderBy(AgencyServiceTypeGroup::withoutGlobalScopes()->select('label')->whereColumn('agency_service_type_groups.id', 'agency_service_types.group_id')->limit(1), $dir)->orderBy('sort_order'),
            default => $q->orderBy('sort_order', $dir),
        };
        $types = $q->orderBy('id')->get();
        $archived = AgencyServiceType::onlyTrashed()->with('group')->orderBy('sort_order')->orderBy('id')->get();
        $typeCounts = AgencyServiceType::query()->selectRaw('group_id, count(*) c')->groupBy('group_id')->pluck('c', 'group_id');

        return view('deals-v2.settings.service-types.index', compact('types', 'archived', 'groups', 'archivedGroups', 'groupFilter', 'sort', 'dir', 'typeCounts'));
    }

    // ── groups ───────────────────────────────────────────────────────────────

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100']]);
        $code = Str::limit(Str::slug($data['label'], '_'), 40, '');
        if ($code === '') {
            return back()->withErrors(['label' => 'Enter a group name with at least one letter or number.']);
        }
        if (AgencyServiceTypeGroup::where('label', $data['label'])->exists() || AgencyServiceTypeGroup::where('code', $code)->exists()) {
            return back()->withErrors(['label' => "A group '{$data['label']}' already exists."]);
        }
        AgencyServiceTypeGroup::create(['code' => $code, 'label' => $data['label'], 'sort_order' => (int) (AgencyServiceTypeGroup::max('sort_order') ?? 0) + 1, 'is_active' => true]);

        return back()->with('success', "Group '{$data['label']}' added.");
    }

    public function updateGroup(Request $request, AgencyServiceTypeGroup $group): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100']]);
        if (AgencyServiceTypeGroup::where('label', $data['label'])->where('id', '!=', $group->id)->exists()) {
            return back()->withErrors(['label' => "A group '{$data['label']}' already exists."]);
        }
        $group->update(['label' => $data['label']]);   // the stored code never changes

        return back()->with('success', "Group renamed to '{$group->label}'.");
    }

    /** Move a group one place up or down in the agency's order (swap with its neighbour). */
    public function moveGroup(Request $request, AgencyServiceTypeGroup $group): RedirectResponse
    {
        $dir = $request->input('dir') === 'up' ? 'up' : 'down';
        $all = AgencyServiceTypeGroup::orderBy('sort_order')->orderBy('id')->get()->values();
        $i = $all->search(fn ($g) => $g->id === $group->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($all[$j])) {
            $order = $all->pluck('id')->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $pos => $id) {
                AgencyServiceTypeGroup::where('id', $id)->update(['sort_order' => $pos + 1]);
            }
        }

        return back();
    }

    /** A group with service types in it cannot be archived - nothing is ever orphaned. */
    public function destroyGroup(AgencyServiceTypeGroup $group): RedirectResponse
    {
        $n = AgencyServiceType::where('group_id', $group->id)->count();
        if ($n > 0) {
            return back()->withErrors(['label' => "'{$group->label}' still has {$n} service " . ($n === 1 ? 'type' : 'types') . ' in it. Move them to another group, or archive them, first - a group can only be archived when it is empty.']);
        }
        $group->delete();   // soft delete - never a hard delete

        return back()->with('success', "Group '{$group->label}' archived.");
    }

    public function restoreGroup(int $id): RedirectResponse
    {
        $group = AgencyServiceTypeGroup::onlyTrashed()->findOrFail($id);
        $group->restore();

        return back()->with('success', "Group '{$group->label}' restored.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label'     => ['required', 'string', 'max:100'],
            'group_id'  => ['required', 'integer', \Illuminate\Validation\Rule::exists('agency_service_type_groups', 'id')->where('agency_id', (int) $request->user()?->effectiveAgencyId())->whereNull('deleted_at')],
            'is_active' => ['nullable', 'boolean'],
        ], ['group_id.required' => 'Choose which group this service type belongs to.', 'group_id.exists' => 'Choose one of the agency\'s groups.']);

        $code = Str::limit(Str::slug($data['label'], '_'), 40, '');
        if ($code === '') {
            return back()->withErrors(['label' => 'Enter a name with at least one letter or number.']);
        }

        // One LIVE code per agency (archived rows may share it — restore instead).
        if (AgencyServiceType::where('code', $code)->exists()) {
            return back()->withErrors(['label' => "A service type '{$data['label']}' already exists."]);
        }

        $maxSort = (int) (AgencyServiceType::max('sort_order') ?? 0);

        AgencyServiceType::create([
            'code'       => $code,
            'label'      => $data['label'],
            'group_id'   => (int) $data['group_id'],
            'sort_order' => $maxSort + 1,
            'is_active'  => $request->has('is_active') ? $request->boolean('is_active') : true,
        ]);

        return back()->with('success', "Service type '{$data['label']}' added.");
    }

    public function update(Request $request, AgencyServiceType $service_type): RedirectResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'group_id'   => ['required', 'integer', \Illuminate\Validation\Rule::exists('agency_service_type_groups', 'id')->where('agency_id', (int) $request->user()?->effectiveAgencyId())->whereNull('deleted_at')],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ], ['group_id.required' => 'Choose which group this service type belongs to.', 'group_id.exists' => 'Choose one of the agency\'s groups.']);

        // The CODE is stable — renaming the label never rewrites the value that
        // configured steps already store. Only label / order / active change.
        $service_type->update([
            'label'      => $data['label'],
            'group_id'   => (int) $data['group_id'],
            'sort_order' => $data['sort_order'],
            'is_active'  => $request->boolean('is_active'),
        ]);

        return back()->with('success', "'{$service_type->label}' updated.");
    }

    public function destroy(AgencyServiceType $service_type): RedirectResponse
    {
        $label = $service_type->label;
        $service_type->delete(); // soft delete — never a hard delete

        return back()->with('success', "'{$label}' archived. Steps already using it keep working; it just leaves the dropdown.");
    }

    public function restore(int $id): RedirectResponse
    {
        $type = AgencyServiceType::onlyTrashed()->findOrFail($id);
        $type->restore();
        // its group was archived meanwhile: it comes back into "Your trades" rather than into a hidden group
        if (! AgencyServiceTypeGroup::where('id', $type->group_id)->exists()) {
            $type->update(['group_id' => AgencyServiceTypeGroup::tradesIdFor((int) $type->agency_id)]);
        }

        return back()->with('success', "'{$type->label}' restored.");
    }
}
