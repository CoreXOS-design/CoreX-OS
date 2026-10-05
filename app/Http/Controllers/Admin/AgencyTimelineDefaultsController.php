<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\AgencyTimelineDefaultItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dev Settings → Agency timeline defaults. The editable plan every NEW agency
 * timeline starts from. Editing never touches a timeline already running (those
 * hold their own snapshot). owner-only; spec §7.2.
 */
class AgencyTimelineDefaultsController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user() && $r->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function index(Request $request)
    {
        $this->owner($request);
        $showArchived = $request->boolean('archived');

        return view('admin.dev-settings.timeline-defaults', [
            'blocks'     => AgencyTimelineDefaultItem::where('kind', 'block')->orderBy('sort_order')->orderBy('id')->get(),
            'milestones' => AgencyTimelineDefaultItem::where('kind', 'milestone')->orderBy('offset_days')->orderBy('sort_order')->get(),
            'archived'   => $showArchived ? AgencyTimelineDefaultItem::onlyTrashed()->orderBy('kind')->orderBy('sort_order')->get() : collect(),
            'archivedCount' => AgencyTimelineDefaultItem::onlyTrashed()->count(),
            'triggers'   => AgencyTimelineDefaultItem::TRIGGERS,
        ]);
    }

    private function rules(Request $request, ?string $kind = null): array
    {
        $kind ??= (string) $request->input('kind');

        return [
            'kind'                  => ['sometimes', Rule::in(['block', 'milestone'])],
            'title'                 => 'required|string|max:255',
            'body'                  => 'nullable|string|max:5000',
            'offset_days'           => ['nullable', 'integer', 'min:0', 'max:365', $kind === 'milestone' ? 'required' : 'nullable'],
            'auto_complete_trigger' => ['nullable', Rule::in(array_keys(AgencyTimelineDefaultItem::TRIGGERS))],
        ];
    }

    public function store(Request $request)
    {
        $this->owner($request);
        $request->validate(['kind' => ['required', Rule::in(['block', 'milestone'])]]);
        $data = $request->validate($this->rules($request));
        $data['is_public'] = $request->boolean('is_public', true);
        $data['is_go_live'] = $data['kind'] === 'milestone' && $request->boolean('is_go_live');
        if ($data['kind'] === 'block') {
            $data['offset_days'] = null;
            $data['auto_complete_trigger'] = null;
        }
        $data['sort_order'] = (int) AgencyTimelineDefaultItem::withTrashed()->where('kind', $data['kind'])->max('sort_order') + 10;

        $item = AgencyTimelineDefaultItem::create($data);
        $this->enforceSingleGoLive($item);

        return back()->with('success', 'Default added. New timelines will include it; running timelines are unchanged.');
    }

    public function update(Request $request, int $item)
    {
        $this->owner($request);
        $item = AgencyTimelineDefaultItem::withTrashed()->findOrFail($item);
        $data = $request->validate($this->rules($request, $item->kind));
        unset($data['kind']); // a default never changes kind
        $data['is_public'] = $request->boolean('is_public');
        $data['is_go_live'] = $item->kind === 'milestone' && $request->boolean('is_go_live');
        if ($item->kind === 'block') {
            $data['offset_days'] = null;
            $data['auto_complete_trigger'] = null;
        }
        $item->update($data);
        $this->enforceSingleGoLive($item);

        return back()->with('success', 'Default saved. Running timelines are unchanged.');
    }

    public function move(Request $request, int $item)
    {
        $this->owner($request);
        $request->validate(['direction' => 'required|in:up,down']);
        $item = AgencyTimelineDefaultItem::findOrFail($item);
        $siblings = AgencyTimelineDefaultItem::where('kind', $item->kind)->orderBy('sort_order')->orderBy('id')->get()->values();
        $i = $siblings->search(fn ($s) => $s->id === $item->id);
        $j = $request->direction === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($siblings[$j])) {
            foreach ($siblings as $n => $s) {
                $s->sort_order = ($n + 1) * 10;
            }
            [$siblings[$i]->sort_order, $siblings[$j]->sort_order] = [$siblings[$j]->sort_order, $siblings[$i]->sort_order];
            $siblings->each->save();
        }

        return back();
    }

    public function destroy(Request $request, int $item)
    {
        $this->owner($request);
        AgencyTimelineDefaultItem::findOrFail($item)->delete();

        return back()->with('success', 'Archived. Restore it from "Show archived".');
    }

    public function restore(Request $request, int $item)
    {
        $this->owner($request);
        $row = AgencyTimelineDefaultItem::onlyTrashed()->findOrFail($item);
        $row->restore();
        $this->enforceSingleGoLive($row);

        return back()->with('success', 'Restored.');
    }

    /** Exactly one go-live default: the one just saved wins; every other loses the flag. */
    private function enforceSingleGoLive(AgencyTimelineDefaultItem $saved): void
    {
        if ($saved->is_go_live) {
            AgencyTimelineDefaultItem::withTrashed()->where('id', '!=', $saved->id)->where('is_go_live', true)->update(['is_go_live' => false]);
        }
    }
}
