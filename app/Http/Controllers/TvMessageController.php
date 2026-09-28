<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\TvMessage;
use Illuminate\Http\Request;

class TvMessageController extends Controller
{
    /**
     * AT-424 — the agency a TV message belongs to. A branch message takes its
     * branch's agency, and the branch must be one this user can see (Branch is
     * agency-scoped, so another agency's branch id resolves to nothing). An
     * "all branches" message takes the viewer's agency, or $fallback when
     * editing an existing message. An owner who has not switched into an
     * agency must pick one first — a global message needs an owner.
     */
    private function resolveAgencyForBranch($branchId, ?int $fallback = null): int
    {
        if ($branchId) {
            $branch = Branch::find((int) $branchId);
            if (!$branch) {
                throw \Illuminate\Validation\ValidationException::withMessages(['branch_id' => 'That branch is not in your agency.']);
            }
            return (int) $branch->agency_id;
        }

        $agencyId = $fallback ?: (int) auth()->user()?->effectiveAgencyId();
        if (!$agencyId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branch_id' => 'Switch into an agency before adding an all-branches message.']);
        }
        return $agencyId;
    }

    // -------------------------
    // Admin (all branches + global)
    // -------------------------

    public function adminIndex(Request $request)
    {
        $branches = Branch::query()->orderBy('name')->get();

        $status = $request->input('status', 'active');
        $showArchived = $status === 'archived';

        if ($showArchived) {
            $messages = TvMessage::onlyTrashed()
                ->with(['branch', 'creator'])
                ->orderByRaw('case when branch_id is null then 0 else 1 end')
                ->orderBy('id', 'desc')
                ->get();
        } else {
            $messages = TvMessage::query()
                ->with(['branch', 'creator'])
                ->orderByRaw('case when branch_id is null then 0 else 1 end')
                ->orderBy('id', 'desc')
                ->get();
        }

        return view('admin.tv-messages.index', [
            'branches' => $branches,
            'messages' => $messages,
            'status' => $status,
            'showArchived' => $showArchived,
        ]);
    }

    public function adminStore(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'display_area' => ['nullable', 'in:hero,ticker,both'],
            'is_enabled' => ['nullable', 'in:0,1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $data['agency_id'] = $this->resolveAgencyForBranch($data['branch_id'] ?? null);
        $data['created_by_user_id'] = auth()->id();
        $data['is_enabled'] = (bool)($data['is_enabled'] ?? false);
        $data['display_area'] = $data['display_area'] ?? 'both';


        TvMessage::create($data);

        return redirect()->route('admin.tv-messages')->with('status', 'TV message added.');
    }

    public function adminUpdate(Request $request, TvMessage $tvMessage)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'display_area' => ['nullable', 'in:hero,ticker,both'],
            'is_enabled' => ['nullable', 'in:0,1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $data['is_enabled'] = (bool)($data['is_enabled'] ?? false);
        $data['display_area'] = $data['display_area'] ?? 'both';


        // A message stays with its agency; a branch may only be one of that agency's.
        $agencyId = $this->resolveAgencyForBranch($data['branch_id'] ?? null, (int) $tvMessage->agency_id);
        abort_unless($agencyId === (int) $tvMessage->agency_id, 403);
        unset($data['agency_id'], $data['created_by_user_id']);

        $tvMessage->update($data);

        return redirect()->route('admin.tv-messages')->with('status', 'TV message saved.');
    }

    public function adminDelete(TvMessage $tvMessage)
    {
        $tvMessage->delete();

        return redirect()->route('admin.tv-messages')->with('status', 'TV message archived.');
    }

    // -------------------------
    // Branch Manager (own branch only)
    // -------------------------

    private function bmBranchId(): int
    {
        $u = auth()->user();
        $branchId = (int)($u?->effectiveBranchId() ?? ($u?->branch_id ?? 0));
        if ($branchId === 0 && $u?->isOwnerRole()) {
            $branchId = (int) \DB::table('branches')->orderBy('id')->value('id');
        }
        return $branchId;
    }

    public function bmIndex(Request $request)
    {
        $branchId = $this->bmBranchId();
        abort_unless($branchId > 0, 403);

        $messages = TvMessage::query()
            ->where('branch_id', $branchId) // BM cannot see global or other branches here
            ->with(['branch', 'creator'])
            ->orderBy('id', 'desc')
            ->get();

        $globalMessages = TvMessage::query()
            ->where('agency_id', (int) \DB::table('branches')->where('id', $branchId)->value('agency_id'))
            ->whereNull('branch_id')
            ->with(['branch', 'creator'])
            ->orderBy('id', 'desc')
            ->get();

        return view('bm.tv-messages.index', [
            'branchId' => $branchId,
            'messages' => $messages,
            'globalMessages' => $globalMessages,
        ]);
    }

    public function bmStore(Request $request)
    {
        $branchId = $this->bmBranchId();
        abort_unless($branchId > 0, 403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'display_area' => ['nullable', 'in:hero,ticker,both'],
            'is_enabled' => ['nullable', 'in:0,1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $data['branch_id'] = $branchId;
        $data['agency_id'] = $this->resolveAgencyForBranch($branchId);
        $data['created_by_user_id'] = auth()->id();
        $data['is_enabled'] = (bool)($data['is_enabled'] ?? false);
        $data['display_area'] = $data['display_area'] ?? 'both';


        TvMessage::create($data);

        return redirect()->route('bm.tv-messages')->with('status', 'TV message added.');
    }

    public function bmUpdate(Request $request, TvMessage $tvMessage)
    {
        $branchId = $this->bmBranchId();
        abort_unless($branchId > 0, 403);

        // BM can only edit their own branch messages (never global)
        abort_unless((int)$tvMessage->branch_id === $branchId, 403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'display_area' => ['nullable', 'in:hero,ticker,both'],
            'is_enabled' => ['nullable', 'in:0,1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $data['is_enabled'] = (bool)($data['is_enabled'] ?? false);
        $data['display_area'] = $data['display_area'] ?? 'both';


        // never allow BM to change branch_id or creator
        unset($data['branch_id'], $data['created_by_user_id']);

        $tvMessage->update($data);

        return redirect()->route('bm.tv-messages')->with('status', 'TV message saved.');
    }

    public function bmDelete(TvMessage $tvMessage)
    {
        $branchId = $this->bmBranchId();
        abort_unless($branchId > 0, 403);

        abort_unless((int)$tvMessage->branch_id === $branchId, 403);

        $tvMessage->delete();

        return redirect()->route('bm.tv-messages')->with('status', 'TV message archived.');
    }

    // ── Restore soft-deleted (admin) ──

    public function adminRestore($id)
    {
        abort_unless(auth()->user()->hasPermission('manage_system'), 403);
        $record = TvMessage::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
