<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AuctionSellerSubmission as S;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Screen "Seller leads" (.ai/specs/auctions.md §22.3): every "Sell at auction" questionnaire from the public auction page. Search, sort, filter,
 * assign, mark contacted / closed, archive and restore. Own / branch / agency visibility is enforced in the query (S::visibleTo), so a lead
 * the person may not see is a 404 by direct URL, not just an unlinked row.
 */
class SellerLeadController extends Controller
{
    private const SORTABLE = ['created_at', 'name', 'suburb', 'price_expectation', 'status'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('property_type', ''));
        $format = trim((string) $request->query('auction_format', ''));
        $assigned = trim((string) $request->query('assigned', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $archived = $request->boolean('archived');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = S::visibleTo($user)->with(['assignee', 'contact']);
        if ($archived) {
            $query->onlyTrashed();
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                    ->orWhere('address', 'like', $like)->orWhere('suburb', 'like', $like)
                    ->orWhere('answers->sale_reason', 'like', $like)->orWhere('answers->defects', 'like', $like);
            });
        }
        if (in_array($status, S::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (array_key_exists($type, S::PROPERTY_TYPES)) {
            $query->where('property_type', $type);
        }
        if (array_key_exists($format, S::FORMATS)) {
            $query->where('auction_format', $format);
        }
        if ($assigned === 'none') {
            $query->whereNull('assigned_user_id');
        } elseif ($assigned === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $query->where('assigned_user_id', (int) $assigned);
        }
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        $query->orderBy($sort, $dir);

        return view('corex.auctions.sellers.index', [
            'leads' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => compact('search', 'status', 'type', 'format', 'assigned', 'dateFrom', 'dateTo', 'archived', 'sort', 'dir'),
            'agents' => $this->agents($user),
            'newCount' => S::visibleTo($user)->where('status', S::STATUS_NEW)->count(),
        ]);
    }

    public function show(Request $request, int $lead)
    {
        $row = S::visibleTo($request->user())->withTrashed()->with(['assignee', 'contact', 'trackedProperty'])->findOrFail($lead);

        return view('corex.auctions.sellers.show', ['lead' => $row, 'agents' => $this->agents($request->user())]);
    }

    /** Change status and/or assignee. */
    public function update(Request $request, int $lead)
    {
        $row = S::visibleTo($request->user())->findOrFail($lead);

        $data = $request->validate([
            'status' => 'sometimes|in:' . implode(',', S::STATUSES),
            'assigned_user_id' => 'sometimes|nullable|integer',
        ]);
        if (array_key_exists('assigned_user_id', $data) && $data['assigned_user_id'] !== null
            && ! $this->agents($request->user())->contains('id', (int) $data['assigned_user_id'])) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Pick someone from your agency.']);
        }

        if (isset($data['status'])) {
            $row->status = $data['status'];
            if ($data['status'] === S::STATUS_CONTACTED && ! $row->contacted_at) {
                $row->contacted_at = now();
            }
        }
        if (array_key_exists('assigned_user_id', $data)) {
            $row->assigned_user_id = $data['assigned_user_id'];
        }
        $row->save();

        return back()->with('status', 'Lead updated.');
    }

    public function archive(Request $request, int $lead)
    {
        S::visibleTo($request->user())->findOrFail($lead)->delete();

        return redirect()->route('corex.auctions.sellers.index')->with('status', 'Lead archived. You can restore it from "Archived".');
    }

    public function restore(Request $request, int $lead)
    {
        S::visibleTo($request->user())->onlyTrashed()->findOrFail($lead)->restore();

        return redirect()->route('corex.auctions.sellers.show', $lead)->with('status', 'Lead restored.');
    }

    private function agents(User $user)
    {
        return User::query()->where('agency_id', $user->effectiveAgencyId())->orderBy('name')->get(['id', 'name']);
    }
}
