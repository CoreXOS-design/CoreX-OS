<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

class DealRegisterController extends Controller
{
    private function userCanAccessDeal(Deal $deal, int $userId): bool
    {
        // deal_user pivot via Deal->agents() relationship
        return $deal->agents()->where('users.id', $userId)->exists();
    }

    /**
     * My Deals — the signed-in agent's OWN deals (deal_user pivot, listing or selling side), with
     * search / status / commission / deal-date-range filters, sort and pagination
     * (.ai/specs/deal-register-v2-spec.md "My Deals — search, filters, sort, pagination").
     * The agent is always auth()->user(); no request parameter can widen the set.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $mine = fn () => Deal::query()->whereHas('agents', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });

        // Header count: ALL of the agent's own deals, regardless of filters.
        $totalMine = $mine()->count();

        $query = $mine();

        // Search — Deal Register fields.
        if (filled($search = trim((string) $request->input('search', '')))) {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('property_address', 'like', $like)
                  ->orWhere('seller_name', 'like', $like)
                  ->orWhere('buyer_name', 'like', $like)
                  ->orWhere('deal_no', 'like', $like)
                  ->orWhere('file_no', 'like', $like);
            });
        }

        // Status — Deal Register vocabulary (stored as P/G/R/D; long forms also matched).
        $statusMap = [
            'Pending'    => ['Pending', 'P'],
            'Granted'    => ['Granted', 'G'],
            'Registered' => ['Registered', 'R'],
            'Declined'   => ['Declined', 'D'],
        ];
        $status = (string) $request->input('status', '');
        if (isset($statusMap[$status])) {
            $query->whereIn('accepted_status', $statusMap[$status]);
        }

        $commission = (string) $request->input('commission', '');
        if (in_array($commission, ['Not Paid', 'Paid', 'Loss'], true)) {
            $query->where('commission_status', $commission);
        }

        // Deal date range (inclusive). Invalid dates are ignored.
        $dateFrom = $this->validDate($request->input('date_from'));
        $dateTo   = $this->validDate($request->input('date_to'));
        if ($dateFrom) {
            $query->whereDate('deal_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('deal_date', '<=', $dateTo);
        }

        // Sort — allow-listed; default Deal number, newest first.
        $allowedSorts = ['deal_no', 'deal_date', 'property_address', 'property_value'];
        $sort = (string) $request->input('sort', 'deal_no');
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'deal_no';
        }
        $direction = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction)->orderBy('id', 'desc');

        $deals = $query->paginate(20)->withQueryString();

        $branches = Branch::orderBy('name')->get();

        $filtersActive = filled($search)
            || isset($statusMap[$status])
            || in_array($commission, ['Not Paid', 'Paid', 'Loss'], true)
            || $dateFrom !== null
            || $dateTo !== null;

        return view('agent.deals.index', compact('deals', 'branches', 'totalMine', 'filtersActive', 'dateFrom', 'dateTo', 'sort', 'direction'));
    }

    private function validDate($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function log(Deal $deal)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        abort_unless($this->userCanAccessDeal($deal, (int)$user->id), 403);

        $logs = DealLog::query()
            ->where('deal_id', $deal->id)
            ->orderBy('created_at', 'desc')
            ->get();

        
        $actorIds = $logs->pluck('actor_user_id')->filter()->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : User::whereIn('id', $actorIds)->get()->keyBy('id');

        return view('agent.deals.log', compact('deal', 'logs', 'actors'));
}

    public function addRemark(Request $request, Deal $deal)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        abort_unless($this->userCanAccessDeal($deal, (int)$user->id), 403);

        $data = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        $remark = trim((string)($data['remark'] ?? ''));
        if ($remark === '') {
            return redirect()->route('agent.deals.log', $deal)->withErrors('Remark cannot be blank.');
        }

        // Backwards compatibility: keep latest remark on deal row
        $deal->remarks = $remark;
        $deal->save();

        // Audit trail entry (never block UX if logging fails)
        try {
            DealLog::create([
                'deal_id' => $deal->id,
                'actor_user_id' => $user->id,
                'user_name' => $user->name,
                'event_type' => 'remark_added',
                'from_value' => null,
                'to_value' => null,
                'message' => $remark,
            ]);
        } catch (\Throwable $e) {
            // swallow
        }

        return redirect()->route('agent.deals.log', $deal)->with('status', 'Remark added.');
    }
}
