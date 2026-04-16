<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\AgentCapPeriod;
use App\Models\AgentSponsorship;
use App\Models\CommissionLedger;
use App\Models\CommissionSetting;
use App\Models\RevenueShareLedger;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;
        $agencyId = $user->effectiveAgencyId() ?? 1;

        // Get or create current cap period
        $capPeriod = AgentCapPeriod::currentForUser($userId, $agencyId);
        $settings = CommissionSetting::forAgency($agencyId);

        // ── Summary figures ──
        $thisMonthGCI = CommissionLedger::forUser($userId)->thisMonth()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('net_agent_amount') ?? 0;

        $thisYearGCI = CommissionLedger::forUser($userId)->thisYear()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('net_agent_amount') ?? 0;

        $capProgress = (float) ($capPeriod->company_dollar_paid ?? 0);
        $capTotal = (float) ($capPeriod->cap_amount ?? 0);
        $capRemaining = $capPeriod->is_capped ? 0 : max(0, $capTotal - $capProgress);
        $capPercent = $capTotal > 0 ? min(100, round(($capProgress / $capTotal) * 100, 1)) : 0;
        $daysUntilReset = $capPeriod->period_end ? (int) now()->diffInDays(Carbon::parse($capPeriod->period_end), false) : 0;
        if ($daysUntilReset < 0) {
            $daysUntilReset = 0;
        }

        // Revenue share
        $currentMonthStart = now()->startOfMonth()->toDateString();
        $thisMonthRevShare = RevenueShareLedger::forAgent($userId)
            ->where('period_month', $currentMonthStart)
            ->sum('share_amount') ?? 0;

        $thisYearRevShare = RevenueShareLedger::forAgent($userId)
            ->whereYear('period_month', now()->year)
            ->sum('share_amount') ?? 0;

        // ── Recent transactions ──
        $recentTransactions = CommissionLedger::forUser($userId)
            ->orderByDesc('deal_date')
            ->orderByDesc('created_at')
            ->paginate(10);

        // ── Monthly chart data (last 12 months) ──
        $monthlyData = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthKey = $month->format('Y-m');
            $monthLabel = $month->format('M');

            $commission = CommissionLedger::forUser($userId)
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->sum('net_agent_amount') ?? 0;

            $revShare = RevenueShareLedger::forAgent($userId)
                ->where('period_month', $month->startOfMonth()->toDateString())
                ->sum('share_amount') ?? 0;

            $monthlyData[] = [
                'label' => $monthLabel,
                'commission' => round((float) $commission, 2),
                'revShare' => round((float) $revShare, 2),
            ];
        }

        // ── Tier 1 agents (sponsored by this user) ──
        $tier1Agents = AgentSponsorship::active()
            ->where('sponsor_user_id', $userId)
            ->with('agent')
            ->get()
            ->map(function ($sponsorship) {
                $agent = $sponsorship->agent;
                if (!$agent) {
                    return null;
                }

                $monthGCI = CommissionLedger::forUser($agent->id)->thisMonth()
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->sum('net_agent_amount') ?? 0;

                return [
                    'name' => $agent->name,
                    'id' => $agent->id,
                    'month_gci' => round((float) $monthGCI, 2),
                ];
            })
            ->filter()
            ->values();

        // Post-cap fee summary (for capped agents)
        $postCapFees = null;
        if ($capPeriod->is_capped) {
            $postCapFees = [
                'transaction_fees_paid' => (float) ($capPeriod->post_cap_fees_paid ?? 0),
                'risk_fees_paid' => (float) ($capPeriod->risk_fees_paid ?? 0),
                'post_cap_fee_cap' => (float) $settings->post_cap_fee_cap,
            ];
        }

        return view('commission.dashboard', compact(
            'user',
            'capPeriod',
            'settings',
            'thisMonthGCI',
            'thisYearGCI',
            'capProgress',
            'capTotal',
            'capRemaining',
            'capPercent',
            'daysUntilReset',
            'thisMonthRevShare',
            'thisYearRevShare',
            'recentTransactions',
            'monthlyData',
            'tier1Agents',
            'postCapFees'
        ));
    }

    // ── Principal Dashboard ──

    public function principalDashboard(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $agencyId = $user->effectiveAgencyId() ?? 1;
        $settings = CommissionSetting::forAgency($agencyId);

        $currentMonthStart = now()->startOfMonth()->toDateString();

        // ── Agency summary — this month ──
        $agencyGCIMonth = (float) (CommissionLedger::where('agency_id', $agencyId)->thisMonth()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('gross_commission') ?? 0);

        $companyDollarMonth = (float) (CommissionLedger::where('agency_id', $agencyId)->thisMonth()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('company_dollar') ?? 0);

        $revSharePaidMonth = (float) (RevenueShareLedger::whereHas('commissionEntry', fn($q) => $q->where('agency_id', $agencyId))
            ->where('period_month', $currentMonthStart)
            ->sum('share_amount') ?? 0);

        $netAgencyMonth = $companyDollarMonth - $revSharePaidMonth;

        // ── Agency summary — this year ──
        $agencyGCIYear = (float) (CommissionLedger::where('agency_id', $agencyId)->thisYear()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('gross_commission') ?? 0);

        $companyDollarYear = (float) (CommissionLedger::where('agency_id', $agencyId)->thisYear()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('company_dollar') ?? 0);

        $revSharePaidYear = (float) (RevenueShareLedger::whereHas('commissionEntry', fn($q) => $q->where('agency_id', $agencyId))
            ->whereYear('period_month', now()->year)
            ->sum('share_amount') ?? 0);

        $netAgencyYear = $companyDollarYear - $revSharePaidYear;

        // ── Agent performance table ──
        $agents = User::agencyMembers()
            ->where('is_active', true)
            ->whereNotNull('agency_id')
            ->orderBy('name')
            ->get()
            ->map(function ($agent) use ($agencyId) {
                $gciMonth = (float) (CommissionLedger::forUser($agent->id)->thisMonth()
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->sum('gross_commission') ?? 0);

                $gciYear = (float) (CommissionLedger::forUser($agent->id)->thisYear()
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->sum('gross_commission') ?? 0);

                $capPeriod = AgentCapPeriod::forUser($agent->id)->current()->first();
                $capPercent = 0;
                $isCapped = false;
                if ($capPeriod) {
                    $capTotal = (float) ($capPeriod->cap_amount ?? 0);
                    $capPaid = (float) ($capPeriod->company_dollar_paid ?? 0);
                    $capPercent = $capTotal > 0 ? min(100, round(($capPaid / $capTotal) * 100)) : 0;
                    $isCapped = $capPeriod->is_capped;
                }

                $txCount = CommissionLedger::forUser($agent->id)->thisYear()
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->count();

                $revShareEarned = (float) (RevenueShareLedger::forAgent($agent->id)
                    ->whereYear('period_month', now()->year)
                    ->sum('share_amount') ?? 0);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'gci_month' => $gciMonth,
                    'gci_year' => $gciYear,
                    'cap_percent' => $capPercent,
                    'is_capped' => $isCapped,
                    'tx_count' => $txCount,
                    'rev_share_earned' => $revShareEarned,
                ];
            })
            ->sortByDesc('gci_month')
            ->values();

        // ── Monthly chart data (last 12 months) ──
        $monthlyData = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);

            $companyDollar = (float) (CommissionLedger::where('agency_id', $agencyId)
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->sum('company_dollar') ?? 0);

            $revShare = (float) (RevenueShareLedger::whereHas('commissionEntry', fn($q) => $q->where('agency_id', $agencyId))
                ->where('period_month', $month->startOfMonth()->toDateString())
                ->sum('share_amount') ?? 0);

            $monthlyData[] = [
                'label' => $month->format('M'),
                'companyDollar' => round($companyDollar, 2),
                'revShare' => round($revShare, 2),
                'netAgency' => round($companyDollar - $revShare, 2),
            ];
        }

        // ── Sponsorship tree (for visualization) ──
        $sponsorshipTree = $this->buildSponsorshipTree($agencyId);

        // ── P&L summary ──
        $activeAgentCount = User::where('is_active', true)->whereNotNull('agency_id')->count();
        $totalAgentSplits = (float) (CommissionLedger::where('agency_id', $agencyId)->thisYear()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('net_agent_amount') ?? 0);
        $platformCosts = $activeAgentCount * (float) $settings->monthly_platform_fee * now()->month;

        $pnl = [
            'total_gci' => $agencyGCIYear,
            'agent_splits' => $totalAgentSplits,
            'rev_share' => $revSharePaidYear,
            'platform_costs' => $platformCosts,
            'net_revenue' => $companyDollarYear - $revSharePaidYear - $platformCosts,
        ];

        return view('commission.principal-dashboard', compact(
            'settings',
            'agencyGCIMonth',
            'companyDollarMonth',
            'revSharePaidMonth',
            'netAgencyMonth',
            'agencyGCIYear',
            'companyDollarYear',
            'revSharePaidYear',
            'netAgencyYear',
            'agents',
            'monthlyData',
            'sponsorshipTree',
            'pnl',
            'activeAgentCount'
        ));
    }

    // ── Commission List (admin) ──

    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $agencyId = $user->effectiveAgencyId() ?? 1;

        $query = CommissionLedger::where('agency_id', $agencyId)
            ->with('user');

        // Filters
        if ($request->filled('agent_id')) {
            $query->where('user_id', $request->agent_id);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('transaction_type') && $request->transaction_type !== 'all') {
            $query->where('transaction_type', $request->transaction_type);
        }
        if ($request->filled('date_from')) {
            $query->where('deal_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('deal_date', '<=', $request->date_to);
        }

        $entries = $query->orderByDesc('deal_date')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        $allAgents = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('commission.index', compact('entries', 'allAgents'));
    }

    // ── Confirm / Pay ──

    public function confirm($id)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $entry = CommissionLedger::findOrFail($id);
        $entry->update(['status' => 'confirmed']);

        return back()->with('success', 'Commission entry confirmed.');
    }

    public function pay($id)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $entry = CommissionLedger::findOrFail($id);
        $entry->update(['status' => 'paid', 'paid_at' => now()]);

        return back()->with('success', 'Commission entry marked as paid.');
    }

    // ── Helpers ──

    private function buildSponsorshipTree(int $agencyId): array
    {
        // Get all active agents in this agency
        $agents = User::where('is_active', true)
            ->whereNotNull('agency_id')
            ->get(['id', 'name']);

        $sponsorships = AgentSponsorship::active()->get();

        // Build lookup: agent_id -> sponsor_id
        $sponsorMap = $sponsorships->pluck('sponsor_user_id', 'agent_user_id')->toArray();

        // Find root agents (not sponsored by anyone, or sponsor is outside agency)
        $agentIds = $agents->pluck('id')->toArray();
        $roots = [];
        $children = [];

        foreach ($agents as $agent) {
            $sponsorId = $sponsorMap[$agent->id] ?? null;
            if (!$sponsorId || !in_array($sponsorId, $agentIds)) {
                $roots[] = $agent->id;
            } else {
                $children[$sponsorId][] = $agent->id;
            }
        }

        $agentLookup = $agents->keyBy('id');
        $tree = [];

        foreach ($roots as $rootId) {
            $tree[] = $this->buildTreeNode($rootId, $children, $agentLookup, 0, 4);
        }

        return $tree;
    }

    private function buildTreeNode(int $agentId, array $children, $agentLookup, int $depth, int $maxDepth): array
    {
        $agent = $agentLookup[$agentId] ?? null;

        $gciMonth = (float) (CommissionLedger::forUser($agentId)->thisMonth()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('gross_commission') ?? 0);

        $capPeriod = AgentCapPeriod::forUser($agentId)->current()->first();
        $isCapped = $capPeriod ? $capPeriod->is_capped : false;

        $node = [
            'id' => $agentId,
            'name' => $agent ? $agent->name : 'Unknown',
            'gci_month' => $gciMonth,
            'is_capped' => $isCapped,
            'children' => [],
        ];

        if ($depth < $maxDepth && isset($children[$agentId])) {
            foreach ($children[$agentId] as $childId) {
                $node['children'][] = $this->buildTreeNode($childId, $children, $agentLookup, $depth + 1, $maxDepth);
            }
        }

        return $node;
    }
}
