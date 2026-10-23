<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TvAccessCode;
use App\Models\TvMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TV Display settings (agency-tracker-rehome.md 10.2, step 8; Johan's ruling 20 Oct 2026): the one home for everything an
 * agency sets up for its office TVs - the TV access codes (they used to be made on the two Performance pages) and the TV
 * messages (they used to have an admin screen and a branch-manager screen). Both old screens collapse into this one,
 * scoped at the query layer:
 *
 *   - a whole-agency viewer (admin / owner: `view_company_stats`, or a platform owner) works the company code, every branch's code
 *     and every message;
 *   - a branch manager works their OWN branch's code and messages, and sees (read-only) the agency-wide messages that also play
 *     on their branch's TV;
 *   - anyone without `manage_tv_messages` (an agent) gets 403 on the route middleware.
 *
 * Every write re-checks the breadth here, not in the view, so a branch manager cannot reach another branch's code or message by
 * posting its id. Messages are archived (soft delete) and restored, never hard-deleted.
 */
class TvDisplaySettingsController extends Controller
{
    private const PAGE_SIZE = 20;

    /** @return array{wide: bool, branchId: int} */
    private function breadth(): array
    {
        $u = auth()->user();
        $wide = (bool) ($u && ($u->isOwnerRole() || $u->hasPermission('view_company_stats')));
        $branchId = (int) ($u?->effectiveBranchId() ?? ($u?->branch_id ?? 0));

        return ['wide' => $wide, 'branchId' => $branchId];
    }

    /** The branch a branch-scope user works (403 if they have none). */
    private function ownBranchOr403(): int
    {
        $b = $this->breadth();
        abort_unless($b['branchId'] > 0, 403, 'No branch is assigned to your account.');

        return $b['branchId'];
    }

    public function index(Request $request): View
    {
        ['wide' => $wide, 'branchId' => $ownBranch] = $this->breadth();

        // ── Codes ──
        $codeQuery = TvAccessCode::query()->where('is_active', true)->with(['branch', 'creator']);
        if ($wide) {
            $companyCode = TvAccessCode::forCompany()->where('is_active', true)->with('creator')->latest('id')->first();
            $branchCodes = (clone $codeQuery)->whereNotNull('branch_id')->orderBy('branch_id')->get();
            $branches = Branch::query()->orderBy('name')->get();
        } else {
            $companyCode = null;
            $branchCodes = $ownBranch > 0 ? (clone $codeQuery)->where('branch_id', $ownBranch)->get() : collect();
            $branches = $ownBranch > 0 ? Branch::query()->whereKey($ownBranch)->get() : collect();
        }

        // ── Messages: search / sort / filter / page ──
        $status = $request->input('status') === 'archived' ? 'archived' : 'active';
        $search = trim((string) $request->input('q', ''));
        $branchFilter = $wide ? (string) $request->input('branch', '') : '';
        $sorts = ['newest' => ['id', 'desc'], 'oldest' => ['id', 'asc'], 'starts' => ['starts_at', 'desc'], 'ends' => ['ends_at', 'asc'], 'title' => ['title', 'asc']];
        $sort = array_key_exists((string) $request->input('sort'), $sorts) ? (string) $request->input('sort') : 'newest';

        $q = $status === 'archived' ? TvMessage::onlyTrashed() : TvMessage::query();
        $q->with(['branch', 'creator']);
        if (! $wide) {
            // own branch's messages, plus the agency-wide ones that play on this branch's TV
            $q->where(fn ($x) => $x->where('branch_id', $ownBranch)->orWhereNull('branch_id'));
        } elseif ($branchFilter === 'all') {
            $q->whereNull('branch_id');
        } elseif ($branchFilter !== '' && ctype_digit($branchFilter)) {
            $q->where('branch_id', (int) $branchFilter);
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $q->where(fn ($x) => $x->where('title', 'like', $like)->orWhere('message', 'like', $like));
        }
        [$col, $dir] = $sorts[$sort];
        if ($sort === 'newest') {
            $q->orderByRaw('case when branch_id is null then 0 else 1 end'); // the default view keeps agency-wide messages on top, as the old screen did
        }
        $q->orderBy($col, $dir)->orderBy('id', 'desc');
        $messages = $q->paginate(self::PAGE_SIZE)->withQueryString();

        return view('corex.settings.tv-display', [
            'wide' => $wide,
            'ownBranch' => $ownBranch,
            'companyCode' => $companyCode,
            'branchCodes' => $branchCodes,
            'branches' => $branches,
            'messages' => $messages,
            'status' => $status,
            'search' => $search,
            'branchFilter' => $branchFilter,
            'sort' => $sort,
            'sorts' => array_keys($sorts),
            'canEditMessage' => fn (TvMessage $m): bool => $wide || (int) $m->branch_id === $ownBranch,
        ]);
    }

    // ───────────────────────── codes ─────────────────────────

    /** Generate (replacing any live one) a code: company-wide, or for a branch. A branch manager can only do their own branch. */
    public function generateCode(Request $request): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        $request->validate(['branch_id' => ['nullable', 'integer'], 'company' => ['nullable', 'boolean']]);

        if ($request->boolean('company')) {
            abort_unless($wide, 403, 'Only the whole agency can have a company TV code.');
            TvAccessCode::forCompany()->where('is_active', true)->update(['is_active' => false]);
            TvAccessCode::create(['branch_id' => null, 'code' => TvAccessCode::generateUniqueCode(), 'created_by' => auth()->id(), 'is_active' => true]);

            return back()->with('status', 'New company TV code generated.');
        }

        $branchId = $wide ? (int) $request->input('branch_id') : $this->ownBranchOr403();
        // Branch is agency-scoped: another agency's branch resolves to nothing (404), so a code is never minted for it.
        $branch = Branch::findOrFail($branchId);
        if (! $wide) {
            abort_unless((int) $branch->id === $this->ownBranchOr403(), 403);
        }

        TvAccessCode::where('branch_id', $branch->id)->where('is_active', true)->update(['is_active' => false]);
        TvAccessCode::create(['branch_id' => $branch->id, 'code' => TvAccessCode::generateUniqueCode(), 'created_by' => auth()->id(), 'is_active' => true]);

        return back()->with('status', 'New TV code generated.');
    }

    /** Revoke a code by id, the company code (`company`), or - for a branch manager - their own branch's live code. */
    public function revokeCode(Request $request): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        $request->validate(['code_id' => ['nullable', 'integer'], 'company' => ['nullable', 'boolean']]);

        if ($request->boolean('company')) {
            abort_unless($wide, 403);
            $n = TvAccessCode::forCompany()->where('is_active', true)->update(['is_active' => false]);

            return back()->with('status', $n > 0 ? 'Company TV code revoked.' : 'No active company TV code to revoke.');
        }

        if ($request->filled('code_id')) {
            $code = TvAccessCode::findOrFail((int) $request->input('code_id')); // agency-scoped: another agency's code is a 404
            abort_unless($wide ? true : (int) $code->branch_id === $this->ownBranchOr403(), 403);
            $code->update(['is_active' => false]);

            return back()->with('status', 'TV code revoked.');
        }

        $branchId = $this->ownBranchOr403();
        $n = TvAccessCode::where('branch_id', $branchId)->where('is_active', true)->update(['is_active' => false]);

        return back()->with('status', $n > 0 ? 'TV code revoked. TVs using this code will be disconnected.' : 'No active TV code to revoke.');
    }

    // ───────────────────────── messages ─────────────────────────

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'display_area' => ['nullable', 'in:hero,ticker,both'],
            'is_enabled' => ['nullable', 'in:0,1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? false);
        $data['display_area'] = $data['display_area'] ?? 'both';

        return $data;
    }

    /** The agency a message belongs to: its branch's agency, or the viewer's for an all-branches message. */
    private function agencyFor($branchId, ?int $fallback = null): int
    {
        if ($branchId) {
            $branch = Branch::find((int) $branchId);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'That branch is not in your agency.']);
            }

            return (int) $branch->agency_id;
        }
        $agencyId = $fallback ?: (int) auth()->user()?->effectiveAgencyId();
        if (! $agencyId) {
            throw ValidationException::withMessages(['branch_id' => 'Switch into an agency before adding an all-branches message.']);
        }

        return $agencyId;
    }

    public function storeMessage(Request $request): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        $data = $this->validated($request);
        // A branch manager only ever writes to their own branch, whatever was posted.
        $data['branch_id'] = $wide ? ($data['branch_id'] ?? null) : $this->ownBranchOr403();
        $data['agency_id'] = $this->agencyFor($data['branch_id']);
        $data['created_by_user_id'] = auth()->id();

        TvMessage::create($data);

        return redirect()->route('corex.settings.tv-display.index')->with('status', 'TV message added.');
    }

    public function updateMessage(Request $request, TvMessage $tvMessage): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        abort_unless($wide || (int) $tvMessage->branch_id === $this->ownBranchOr403(), 403);
        $data = $this->validated($request);

        if ($wide) {
            // A message stays with its agency; a branch may only be one of that agency's.
            abort_unless($this->agencyFor($data['branch_id'] ?? null, (int) $tvMessage->agency_id) === (int) $tvMessage->agency_id, 403);
        } else {
            unset($data['branch_id']); // never move a branch manager's message to another branch or make it agency-wide
        }
        unset($data['agency_id'], $data['created_by_user_id']);

        $tvMessage->update($data);

        return redirect()->route('corex.settings.tv-display.index')->with('status', 'TV message saved.');
    }

    public function archiveMessage(TvMessage $tvMessage): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        abort_unless($wide || (int) $tvMessage->branch_id === $this->ownBranchOr403(), 403);
        $tvMessage->delete();

        return redirect()->route('corex.settings.tv-display.index')->with('status', 'TV message archived.');
    }

    public function restoreMessage(int $id): RedirectResponse
    {
        ['wide' => $wide] = $this->breadth();
        $message = TvMessage::onlyTrashed()->findOrFail($id); // agency-scoped
        abort_unless($wide || (int) $message->branch_id === $this->ownBranchOr403(), 403);
        $message->restore();

        return redirect()->route('corex.settings.tv-display.index', ['status' => 'archived'])->with('status', 'TV message restored.');
    }
}
