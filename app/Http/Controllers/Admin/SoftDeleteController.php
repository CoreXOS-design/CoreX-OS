<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SeatReinstatementLockedException;
use App\Http\Controllers\Controller;
use App\Models\Billing\AgentSeatRelease;
use App\Models\User;
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

        $records = $this->registry->trashedRecords($class);
        $isUserModel = $class === User::class;

        // AT-278 — a User carries the 30-day seat reinstatement hold. Show it
        // here, before the click, same as Archived Agents (STANDARDS: No Silent
        // Locks) — rather than letting the admin find out only after a refused
        // restore attempt on this generic page.
        $seatReleases = $isUserModel
            ? AgentSeatRelease::query()
                ->whereIn('user_id', $records->pluck('id'))
                ->open()
                ->get()
                ->keyBy('user_id')
            : collect();

        return view('admin.soft-deletes.show', [
            'key'          => $key,
            'label'        => \Illuminate\Support\Str::plural(\Illuminate\Support\Str::headline(class_basename($class))),
            'records'      => $records,
            'registry'     => $this->registry,
            'isUserModel'  => $isUserModel,
            'seatReleases' => $seatReleases,
            'canOverride'  => (bool) $user?->isOwnerRole(),
        ]);
    }

    /** Restore a single archived record. */
    public function restore(Request $request, string $key, int $id): RedirectResponse
    {
        $user = $request->user();
        $class = $this->registry->resolve($key, $user);
        abort_if($class === null, 404);

        // AT-278 — a User under the 30-day seat reinstatement hold refuses here
        // exactly as it does on Archived Agents: UserObserver::restoring() is a
        // structural, class-level backstop, so THIS generic restore path hits the
        // same gate rather than routing around it. Without this catch, the block
        // was correct but surfaced as an unhandled 500 instead of the same plain
        // CoreX message the Archived Agents page already shows.
        try {
            $ok = $this->registry->restore($class, $id, $user);
        } catch (SeatReinstatementLockedException $e) {
            return redirect()
                ->route('admin.soft-deletes.show', $key)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.soft-deletes.show', $key)
            ->with($ok ? 'success' : 'error', $ok
                ? 'Record restored successfully.'
                : 'That record could not be found or you do not have access to it.');
    }
}
