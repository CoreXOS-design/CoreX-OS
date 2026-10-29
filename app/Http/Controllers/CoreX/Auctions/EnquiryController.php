<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionEnquiry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Screen S3 — Enquiries (.ai/specs/auctions.md §5): every enquiry from the public auction site, across all auctions and
 * lots. Assign, mark contacted / closed, archive and restore, and jump to the Contact.
 */
class EnquiryController extends Controller
{
    private const SORTABLE = ['created_at', 'name', 'status', 'auction'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $assignedId = trim((string) $request->query('assigned_user_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $archived = $request->boolean('archived');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionEnquiry::visibleTo($user)->with(['auction', 'lot', 'assignee', 'contact']);
        if ($archived) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhereHas('lot', fn ($l) => $l->where('lot_number', $search)->orWhere('title', 'like', "%{$search}%"));
            });
        }
        if (in_array($status, AuctionEnquiry::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($auctionId !== '' && ctype_digit($auctionId)) {
            $query->where('auction_id', (int) $auctionId);
        }
        if ($assignedId === 'none') {
            $query->whereNull('assigned_user_id');
        } elseif ($assignedId !== '' && ctype_digit($assignedId)) {
            $query->where('assigned_user_id', (int) $assignedId);
        }
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($sort === 'auction') {
            $query->orderBy(Auction::withTrashed()->select('title')->whereColumn('auctions.id', 'auction_enquiries.auction_id')->limit(1), $dir);
        } else {
            $query->orderBy($sort, $dir);
        }

        return view('corex.auctions.enquiries.index', [
            'enquiries' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => compact('search', 'status', 'auctionId', 'assignedId', 'dateFrom', 'dateTo', 'archived', 'sort', 'dir'),
            'auctions' => Auction::visibleTo($user)->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
            'agents' => $this->agents($user),
            'newCount' => AuctionEnquiry::visibleTo($user)->where('status', AuctionEnquiry::STATUS_NEW)->count(),
        ]);
    }

    /** Change status and/or assignee. Only enquiries the user may see can be touched (a hidden one is a 404). */
    public function update(Request $request, int $enquiry)
    {
        $row = AuctionEnquiry::visibleTo($request->user())->findOrFail($enquiry);

        $data = $request->validate([
            'status' => 'sometimes|in:' . implode(',', AuctionEnquiry::STATUSES),
            'assigned_user_id' => 'sometimes|nullable|integer',
        ]);

        if (array_key_exists('assigned_user_id', $data) && $data['assigned_user_id'] !== null
            && ! $this->agents($request->user())->contains('id', (int) $data['assigned_user_id'])) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Pick someone from your agency.']);
        }

        if (isset($data['status'])) {
            $row->status = $data['status'];
            if ($data['status'] === AuctionEnquiry::STATUS_CONTACTED && ! $row->contacted_at) {
                $row->contacted_at = now();
            }
        }
        if (array_key_exists('assigned_user_id', $data)) {
            $row->assigned_user_id = $data['assigned_user_id'];
        }
        $row->save();

        return back()->with('status', 'Enquiry updated.');
    }

    public function archive(Request $request, int $enquiry)
    {
        AuctionEnquiry::visibleTo($request->user())->findOrFail($enquiry)->delete();

        return back()->with('status', 'Enquiry archived.');
    }

    public function restore(Request $request, int $enquiry)
    {
        AuctionEnquiry::visibleTo($request->user())->onlyTrashed()->findOrFail($enquiry)->restore();

        return back()->with('status', 'Enquiry restored.');
    }

    private function agents(User $user)
    {
        return User::query()->where('agency_id', $user->effectiveAgencyId())->orderBy('name')->get(['id', 'name']);
    }
}
