<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Docuperfect\Clause;
use Illuminate\Http\Request;

class ClauseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Clause::visibleTo($user)->with(['owner', 'branches']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('text', 'like', "%{$search}%");
            });
        }

        // Visibility filter
        if ($visibility = $request->input('visibility')) {
            if ($visibility === 'global') {
                $query->where('is_global', true);
            } elseif ($visibility === 'branch') {
                $query->where('is_global', false);
            }
        }

        // Sort
        $sortField = in_array($request->input('sort'), ['name', 'created_at']) ? $request->input('sort') : 'name';
        $sortDir = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir);

        $clauses = $query->paginate(20)->withQueryString();

        $branches = Branch::orderBy('name')->get();
        $canEdit = $user->hasPermission('clauses.edit');

        return view('docuperfect.clauses.index', compact('clauses', 'branches', 'canEdit', 'user'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clauses.edit'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'text' => 'required|string',
            'category' => 'nullable|string|in:' . implode(',', array_keys(Clause::CATEGORIES)),
            'is_global' => 'boolean',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $clause = Clause::create([
            'name' => $request->input('name'),
            'text' => $request->input('text'),
            'category' => Clause::normaliseCategory($request->input('category')),
            'is_global' => $request->boolean('is_global'),
            'owner_id' => $user->id,
        ]);

        if (!$request->boolean('is_global') && $request->has('branch_ids')) {
            $clause->branches()->sync($request->input('branch_ids'));
        }

        return redirect()->route('docuperfect.clauses.index')
            ->with('status', "Clause \"{$clause->name}\" created.");
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clauses.edit'), 403);

        $clause = Clause::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'text' => 'required|string',
            'category' => 'nullable|string|in:' . implode(',', array_keys(Clause::CATEGORIES)),
            'is_global' => 'boolean',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $clause->update([
            'name' => $request->input('name'),
            'text' => $request->input('text'),
            'category' => Clause::normaliseCategory($request->input('category')),
            'is_global' => $request->boolean('is_global'),
        ]);

        if ($request->boolean('is_global')) {
            $clause->branches()->detach();
        } elseif ($request->has('branch_ids')) {
            $clause->branches()->sync($request->input('branch_ids'));
        }

        return redirect()->route('docuperfect.clauses.index')
            ->with('status', "Clause \"{$clause->name}\" updated.");
    }

    public function copy(Request $request, $id)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clauses.edit'), 403);

        $original = Clause::with('branches')->findOrFail($id);

        $copy = $original->replicate();
        $copy->name = $original->name . ' (Copy)';
        $copy->owner_id = $user->id;
        $copy->save();

        $copy->branches()->sync($original->branches->pluck('id'));

        return redirect()->route('docuperfect.clauses.index')
            ->with('status', "Clause duplicated as \"{$copy->name}\".");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clauses.edit'), 403);

        $clause = Clause::findOrFail($id);
        $name = $clause->name;
        $clause->delete();

        return redirect()->route('docuperfect.clauses.index')
            ->with('status', "Clause \"{$name}\" archived.");
    }

    public function listJson(Request $request)
    {
        $user = $request->user();

        $clauses = Clause::visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name', 'text', 'category', 'is_global', 'is_system'])
            ->map(function (Clause $c) {
                // Surface a normalised category key + UI label so the pickers
                // can group without re-deriving the category map client-side.
                $key = Clause::normaliseCategory($c->category);
                $c->setAttribute('category', $key);
                $c->setAttribute('category_label', Clause::CATEGORIES[$key]);
                return $c;
            });

        return response()->json($clauses);
    }

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('clauses.edit'), 403);
        $record = Clause::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
