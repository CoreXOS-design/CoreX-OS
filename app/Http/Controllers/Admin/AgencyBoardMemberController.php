<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\AgencyBoardMember;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Company Settings > Compliance: the agency's board / senior management list - the people who sign the RMCP board approval
 * letter. Create, edit, archive (soft delete) and restore; every row belongs to the agency in the URL, which must be the
 * signed-in user's own agency (an owner-role user may act for any). Archived members stay on letters already sent.
 * The list is capped (MAX) so a search/sort/page screen would add nothing; archived rows are shown on request.
 */
class AgencyBoardMemberController extends Controller
{
    public const MAX = 20;

    public function store(Request $request, Agency $agency)
    {
        $this->authorizeAgency($agency);
        $data = $this->validated($request, $agency);

        if (AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agency->id)->count() >= self::MAX) {
            return $this->back()->withErrors(['board' => 'The board list is full (' . self::MAX . ' people). Archive someone first.']);
        }

        AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create($data + [
            'agency_id'  => $agency->id,
            'sort_order' => (int) AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->withTrashed()->where('agency_id', $agency->id)->max('sort_order') + 1,
        ]);

        return $this->back()->with('success', $data['name'] . ' is on the board list.');
    }

    public function update(Request $request, Agency $agency, int $member)
    {
        $this->authorizeAgency($agency);
        $row = $this->find($agency, $member);
        $data = $this->validated($request, $agency, $row->id);
        $row->update($data);

        return $this->back()->with('success', 'Saved.');
    }

    public function destroy(Agency $agency, int $member)
    {
        $this->authorizeAgency($agency);
        $row = $this->find($agency, $member);
        $row->delete();

        return $this->back()->with('success', $row->name . ' is archived. Letters already sent keep their signature line.');
    }

    public function restore(Agency $agency, int $member)
    {
        $this->authorizeAgency($agency);
        $row = $this->find($agency, $member, true);
        $row->restore();

        return $this->back()->with('success', $row->name . ' is back on the board list.');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function validated(Request $request, Agency $agency, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:150'],
            'title'      => ['required', 'string', 'max:150'],
            'email'      => ['required', 'email', 'max:190'],
            'user_id'    => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['name' => 'name', 'title' => 'title', 'email' => 'email address']);

        // A linked CoreX user must belong to this agency; anything else is dropped rather than trusted.
        if (! empty($data['user_id'])) {
            $ok = User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('id', $data['user_id'])->where('agency_id', $agency->id)->whereNull('deleted_at')->exists();
            $data['user_id'] = $ok ? (int) $data['user_id'] : null;
        } else {
            $data['user_id'] = null;
        }
        if (! isset($data['sort_order'])) {
            unset($data['sort_order']);
        }

        return $data;
    }

    private function find(Agency $agency, int $id, bool $withTrashed = false): AgencyBoardMember
    {
        $q = AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agency->id);
        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->findOrFail($id);
    }

    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('manage_rmcp_board_approval'), 403);
        abort_unless($user->isOwnerRole() || (int) $user->effectiveAgencyId() === (int) $agency->id, 403);
    }

    private function back()
    {
        $agencyId = (int) request()->route('agency')?->id;
        $own = (int) auth()->user()?->effectiveAgencyId();

        return redirect()->to(route('admin.company-settings', $agencyId && $agencyId !== $own ? ['agency' => $agencyId] : []) . '#compliance');
    }
}
