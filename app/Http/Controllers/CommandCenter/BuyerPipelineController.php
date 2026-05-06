<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use App\Services\BuyerStateService;
use Illuminate\Http\Request;

class BuyerPipelineController extends Controller
{
    public function index(Request $request)
    {
        $view = $request->get('view', 'kanban');
        $stateFilter = $request->get('state');
        $agentFilter = $request->get('agent_id');

        $query = Contact::buyers()->with('createdBy');

        if ($stateFilter) {
            $query->where('buyer_state', $stateFilter);
        }
        if ($agentFilter) {
            $query->where('created_by_user_id', (int) $agentFilter);
        }

        if ($view === 'list') {
            $sortBy = $request->get('sort', 'last_activity_at');
            $sortDir = $request->get('dir', 'desc');
            $buyers = $query->orderBy($sortBy, $sortDir)->paginate(25)->withQueryString();

            return view('command-center.buyers.pipeline', [
                'view' => 'list',
                'buyers' => $buyers,
                'counts' => $this->stateCounts(),
            ]);
        }

        // Kanban view — group by state
        $allBuyers = $query->orderByDesc('last_activity_at')->get();
        $columns = [
            'new' => $allBuyers->where('buyer_state', 'new')->values(),
            'warm' => $allBuyers->where('buyer_state', 'warm')->values(),
            'cold' => $allBuyers->where('buyer_state', 'cold')->values(),
            'lost' => $allBuyers->where('buyer_state', 'lost')->values(),
        ];

        // Load latest risk scores for all buyers
        $riskScores = \Illuminate\Support\Facades\DB::table('buyer_lost_risk_scores as brs')
            ->joinSub(
                \Illuminate\Support\Facades\DB::table('buyer_lost_risk_scores')->selectRaw('contact_id, MAX(id) as max_id')->groupBy('contact_id'),
                'latest', fn($j) => $j->on('brs.id', '=', 'latest.max_id')
            )
            ->pluck('brs.score', 'brs.contact_id');

        return view('command-center.buyers.pipeline', [
            'view' => 'kanban',
            'columns' => $columns,
            'counts' => $this->stateCounts(),
            'riskScores' => $riskScores,
        ]);
    }

    public function updateState(Request $request, Contact $contact)
    {
        $request->validate([
            'state' => 'required|in:new,warm,cold,lost',
            'reason' => 'nullable|string|max:500',
        ]);

        $service = app(BuyerStateService::class);
        $service->transitionTo($contact, $request->input('state'), 'manual_override', auth()->id());

        return response()->json(['success' => true, 'new_state' => $request->input('state')]);
    }

    private function stateCounts(): array
    {
        return Contact::buyers()
            ->selectRaw('buyer_state, count(*) as cnt')
            ->groupBy('buyer_state')
            ->pluck('cnt', 'buyer_state')
            ->toArray();
    }
}
