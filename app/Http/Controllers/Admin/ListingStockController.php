<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingStock;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListingStockController extends Controller
{
    public function agents(Request $request)
    {
        // v1: treat these as "active-ish" unless user filters explicitly
        $status = trim((string) $request->get('status', 'active'));
        $source = trim((string) $request->get('source', 'propcon'));

        // Build a base query for totals
        $base = ListingStock::query()->where('source', $source);
        if ($status !== 'all') {
            // Keep flexible: Propcon may say "Active", "For Sale", etc.
            // v1 default is "active" = status contains "active" or "for sale"
            if ($status === 'active') {
                $base->where(function ($q) {
                    $q->whereRaw("lower(coalesce(status,'')) like '%active%'")
                      ->orWhereRaw("lower(coalesce(status,'')) like '%for sale%'");
                });
            } else {
                $base->whereRaw("lower(coalesce(status,'')) = ?", [strtolower($status)]);
            }
        }

        $totals = (clone $base)
            ->selectRaw('count(*) as listing_count, coalesce(sum(price_cents),0) as total_value_cents')
            ->first();

        // Group by agent/user
        $byAgent = (clone $base)
            ->selectRaw('user_id, count(*) as listing_count, coalesce(sum(price_cents),0) as total_value_cents')
            ->groupBy('user_id')
            ->orderByDesc('listing_count')
            ->get()
            ->keyBy('user_id');

        $userIds = $byAgent->keys()->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->select('id','name','email','branch_id','role')
            ->get()
            ->keyBy('id');

        // Mandate breakdown (EATS/OATS etc) per user
        $mandateRows = (clone $base)
            ->selectRaw("user_id, coalesce(nullif(trim(mandate),''),'(none)') as mandate_key, count(*) as c")
            ->groupBy('user_id','mandate_key')
            ->get();

        $mandates = [];
        foreach ($mandateRows as $r) {
            $mandates[$r->user_id][$r->mandate_key] = (int) $r->c;
        }

        // Type breakdown per user (top 3)
        $typeRows = (clone $base)
            ->selectRaw("user_id, coalesce(nullif(trim(type),''),'(none)') as type_key, count(*) as c")
            ->groupBy('user_id','type_key')
            ->get();

        $types = [];
        foreach ($typeRows as $r) {
            $types[$r->user_id][] = ['type' => $r->type_key, 'c' => (int) $r->c];
        }
        foreach ($types as $uid => $arr) {
            usort($arr, fn($a,$b) => $b['c'] <=> $a['c']);
            $types[$uid] = array_slice($arr, 0, 3);
        }

        // Build rows in a stable order
        $rows = [];
        foreach ($byAgent as $uid => $agg) {
            $u = $users[$uid] ?? null;
            $rows[] = [
                'user_id' => (int) $uid,
                'name' => $u?->name ?? "User #{$uid}",
                'email' => $u?->email,
                'branch_id' => $u?->branch_id,
                'role' => $u?->role,
                'listing_count' => (int) $agg->listing_count,
                'total_value_cents' => (int) $agg->total_value_cents,
                'mandates' => $mandates[$uid] ?? [],
                'top_types' => $types[$uid] ?? [],
            ];
        }

        return view('admin.listings.agents', [
            'rows' => $rows,
            'totals' => $totals,
            'status' => $status,
            'source' => $source,
        ]);
    }

    public function agentShow(Request $request, User $user)
    {
        $status = trim((string) $request->get('status', 'active'));
        $source = trim((string) $request->get('source', 'propcon'));

        $q = ListingStock::query()
            ->where('source', $source)
            ->where('user_id', $user->id);

        if ($status !== 'all') {
            if ($status === 'active') {
                $q->where(function ($x) {
                    $x->whereRaw("lower(coalesce(status,'')) like '%active%'")
                      ->orWhereRaw("lower(coalesce(status,'')) like '%for sale%'");
                });
            } else {
                $q->whereRaw("lower(coalesce(status,'')) = ?", [strtolower($status)]);
            }
        }

        $listings = $q->orderByDesc('modified_at')
            ->orderByDesc('listed_at')
            ->paginate(50)
            ->withQueryString();

        $summary = (clone $q)
            ->selectRaw('count(*) as listing_count, coalesce(sum(price_cents),0) as total_value_cents')
            ->first();

        return view('admin.listings.agent_show', [
            'user' => $user,
            'listings' => $listings,
            'summary' => $summary,
            'status' => $status,
            'source' => $source,
        ]);
    }
    public function editAgents(Request $request, ListingStock $listing)
    {
        // Active users for assignment (agents, admins, BMs if needed)
        $users = User::query()
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id','name','email','role']);

        // Current multi-agent selections (pivot)
        $selectedAgentIds = $listing->agents()->pluck('users.id')->all();

        return view('admin.listings.stock_edit_agents', [
            'listing' => $listing,
            'users' => $users,
            'selectedAgentIds' => $selectedAgentIds,
        ]);
    }

    public function updateAgents(Request $request, ListingStock $listing)
    {
        $data = $request->validate([
            'primary_user_id' => ['required','integer','exists:users,id'],
            'agent_ids' => ['array'],
            'agent_ids.*' => ['integer','exists:users,id'],
        ]);

        $primaryId = (int) $data['primary_user_id'];
        $agentIds = array_values(array_unique(array_map('intval', $data['agent_ids'] ?? [])));

        // If primary wasn't changed but other agents were selected,
        // promote the first non-current agent to be the new primary.
        $currentPrimary = (int) ($listing->user_id ?? 0);
        if ($currentPrimary && $primaryId === $currentPrimary) {
            foreach ($agentIds as $aid) {
                if ((int)$aid !== $currentPrimary) {
                    $primaryId = (int) $aid;
                    break;
                }
            }
        }

        // Always include primary in the pivot set
        if (!in_array($primaryId, $agentIds, true)) {
            $agentIds[] = $primaryId;
        }

        DB::transaction(function () use ($listing, $primaryId, $agentIds) {
            DB::table('listing_stocks')
                ->where('id', $listing->id)
                ->update([
                    'user_id' => $primaryId,
                    'updated_at' => now(),
                ]);

            $listing->agents()->sync($agentIds);
        });

        return redirect()
            ->route('admin.listings.stock.agents.edit', $listing)
            ->with('status', 'Listing agents updated.');
    }


}
