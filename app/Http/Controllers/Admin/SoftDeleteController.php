<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SoftDeleteRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → Soft Deletes Register. Lists every archived (soft-deleted) record
 * across all models and lets an admin restore them. Restore-only — there is no
 * purge/force-delete path here (non-negotiable #1).
 *
 * Spec: .ai/specs/soft-deletes-admin.md.
 */
class SoftDeleteController extends Controller
{
    public function __construct(private SoftDeleteRegistryService $registry)
    {
    }

    /** Category grid with per-model archived counts. */
    public function index(Request $request)
    {
        $user = $request->user();
        $categories = $this->registry->categoriesWithCounts($user);

        return view('admin.soft-deletes.index', [
            'categories'    => $categories,
            'totalArchived' => $categories->sum('total'),
        ]);
    }

    /** Archived records for one model, each with a Restore action. */
    public function show(Request $request, string $key)
    {
        $user = $request->user();
        $class = $this->registry->resolve($key, $user);
        abort_if($class === null, 404);

        return view('admin.soft-deletes.show', [
            'key'      => $key,
            'label'    => \Illuminate\Support\Str::plural(\Illuminate\Support\Str::headline(class_basename($class))),
            'records'  => $this->registry->trashedRecords($class),
            'registry' => $this->registry,
        ]);
    }

    /** Restore a single archived record. */
    public function restore(Request $request, string $key, int $id): RedirectResponse
    {
        $user = $request->user();
        $class = $this->registry->resolve($key, $user);
        abort_if($class === null, 404);

        $ok = $this->registry->restore($class, $id, $user);

        return redirect()
            ->route('admin.soft-deletes.show', $key)
            ->with($ok ? 'success' : 'error', $ok
                ? 'Record restored successfully.'
                : 'That record could not be found or you do not have access to it.');
    }
}
