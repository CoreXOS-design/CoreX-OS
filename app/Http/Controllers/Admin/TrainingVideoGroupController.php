<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingVideoGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Training Video Groups — the owner-made sections of the public Training Videos page.
 *
 * Spec: .ai/specs/training-videos.md §4.2
 *
 * OWNER-ONLY, NO PERMISSION KEY — same reasoning as TrainingVideoController.
 */
class TrainingVideoGroupController extends Controller
{
    private const SORTS = [
        'name'    => 'name',
        'videos'  => 'videos_count',
        'created' => 'created_at',
    ];

    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** GET /corex/admin/integrations/training-videos/groups */
    public function index(Request $request)
    {
        $this->assertOwner();

        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? $request->query('status') : 'active';
        $sort   = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'name';
        $dir    = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $q      = trim((string) $request->query('q', ''));

        // videos_count = active videos only (soft-deleted ones are excluded by the relation).
        $query = TrainingVideoGroup::query()->withCount('videos');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($q !== '') {
            $query->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
        }
        if ($from = $this->date($request->query('from'))) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $this->date($request->query('to'))) {
            $query->whereDate('created_at', '<=', $to);
        }

        $groups = $query
            ->orderBy(self::SORTS[$sort], $dir)
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $filtered = $q !== '' || $status !== 'active' || $request->filled('from') || $request->filled('to');
        $hasAny   = TrainingVideoGroup::withTrashed()->exists();

        return view('admin.training-videos.groups.index', compact('groups', 'status', 'sort', 'dir', 'q', 'filtered', 'hasAny'));
    }

    /** GET /corex/admin/integrations/training-videos/groups/create */
    public function create()
    {
        $this->assertOwner();

        return view('admin.training-videos.groups.form', ['group' => null]);
    }

    /** POST /corex/admin/integrations/training-videos/groups */
    public function store(Request $request)
    {
        $this->assertOwner();

        TrainingVideoGroup::create($this->validated($request, null) + [
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.training-videos.groups.index')
            ->with('status', 'Group created. Pick it when you add or edit a video.');
    }

    /** GET /corex/admin/integrations/training-videos/groups/{group}/edit */
    public function edit(TrainingVideoGroup $group)
    {
        $this->assertOwner();

        return view('admin.training-videos.groups.form', ['group' => $group]);
    }

    /** PUT /corex/admin/integrations/training-videos/groups/{group} */
    public function update(Request $request, TrainingVideoGroup $group)
    {
        $this->assertOwner();

        $group->update($this->validated($request, $group) + ['updated_by' => Auth::id()]);

        return redirect()
            ->route('admin.training-videos.groups.index')
            ->with('status', 'Group updated.');
    }

    /**
     * DELETE …/groups/{group} — ARCHIVES (non-negotiable #1).
     *
     * The videos keep their group id, so they leave the public page with the group
     * and come back with it on restore (spec D10).
     */
    public function destroy(TrainingVideoGroup $group)
    {
        $this->assertOwner();

        $group->delete();

        return back()->with('status', 'Group archived. It and its videos are no longer on the public page — restore it any time from Archived.');
    }

    /** POST …/groups/{group}/restore */
    public function restore(TrainingVideoGroup $group)
    {
        $this->assertOwner();

        // Restoring must not create a second active group with the same name (D11).
        $clash = TrainingVideoGroup::query()
            ->where('id', '!=', $group->id)
            ->where('name', $group->name)
            ->exists();
        if ($clash) {
            return back()->withErrors(['name' => 'An active group is already called "' . $group->name . '". Rename or archive that one first.']);
        }

        $group->restore();

        return back()->with('status', 'Group restored. Its videos are back on the public page.');
    }

    private function validated(Request $request, ?TrainingVideoGroup $group): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('training_video_groups', 'name')->whereNull('deleted_at')->ignore($group?->id),
            ],
        ], [
            'name.required' => 'Give the group a name.',
            'name.unique'   => 'There is already a group with that name.',
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
