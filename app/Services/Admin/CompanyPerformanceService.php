<?php

namespace App\Services\Admin;

use Carbon\Carbon;
use App\Services\Activity\ActivityPointsService;
use Illuminate\Support\Facades\DB;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\FinanceReadModel;

class CompanyPerformanceService
{
    public function getPeriodRollup(string $period, ?int $agencyId = null): array
    {
        // Safety: ensure no leaked branch-scope vars ever trigger warnings
        $companyIncomeBranchTotal = 0.0;
        $ledgerAgentIncomeTotal = 0.0;


        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();


                  // (AT-192 c) Historical commission of ARCHIVED agents must still
                  // count. The branch TEAM column is grouped by an agent's HOME
                  // branch, so an agent archived mid-book (e.g. the consolidated
                  // "Elize Southbroom" login retired 2026-06-25) still owns every
                  // commission line they earned while active — that money belongs
                  // to their home branch for the period it was earned. Filtering on
                  // is_active=1 silently dropped R248k of real Southbroom take-home
                  // the moment that account was archived. So the grid keeps every
                  // active split-counting agent PLUS any now-inactive split-counting
                  // agent who has a non-declined deal dated in THIS period. Inactive
                  // agents with no production this period stay out.
                  $periodParticipantIds = DB::table('deal_user')
                      ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
                      ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
                      ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
                      ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
                      ->distinct()
                      ->pluck('deal_user.user_id')
                      ->all();

                  $agents = DB::table('users')
              ->whereIn('role', ['agent','branch_manager','admin'])
              ->where('counts_for_branch_split', 1)
              ->whereNotNull('branch_id')
              ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
              ->where(function ($q) use ($periodParticipantIds) {
                  $q->where('is_active', 1);
                  if (!empty($periodParticipantIds)) {
                      $q->orWhereIn('id', $periodParticipantIds);
                  }
              })
              ->select('id','name','email','branch_id','is_active')
              ->orderBy('name')
              ->get();

        $agentIds = $agents->pluck('id')->all();

        $targets = DB::table('targets')
            ->where('period', $period)
            ->whereIn('user_id', $agentIds)
            ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
            ->select('user_id','branch_id','deals_target','listings_target','value_target','points_target')
            ->get()
            ->keyBy('user_id');

        // Deals — split-aware: each deal_user row contributes property_value × side split %.
          $splitExpr = \App\Services\Agent\AgentPerformanceService::splitAwareSalesValueExpr();

          $dealActuals = DB::table('deal_user')
              ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
              ->whereIn('deal_user.user_id', $agentIds)
              ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
              ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
              ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
              ->groupBy('deal_user.user_id')
              ->selectRaw('deal_user.user_id as user_id')
              ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as deals_count')
              ->selectRaw("COALESCE(SUM({$splitExpr}), 0) as sales_value")
              ->selectRaw('COALESCE(SUM(deals.total_commission), 0) as gross_commission')
              ->get()
              ->keyBy('user_id');
        // The one shared points rule (ActivityPointsService): count x weight over every counted entry.
        $daily = ActivityPointsService::counted($agencyId, 'dae', 'ad')
            ->whereIn('dae.user_id', $agentIds)
            ->where('dae.period', $period)
            ->groupBy('dae.user_id')
            ->selectRaw('dae.user_id as user_id, COUNT(*) as rows_count, COALESCE(SUM(' . ActivityPointsService::sqlPoints('dae', 'ad') . '),0) as points_sum'
                . ', COALESCE(SUM(' . ActivityPointsService::splitExpressions('dae', 'ad')[0] . '),0) as points_manual'
                . ', COALESCE(SUM(' . ActivityPointsService::splitExpressions('dae', 'ad')[1] . '),0) as points_auto')
            ->get()
            ->keyBy('user_id');
$branches = DB::table('branches')
    ->select('id','name')
    ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
    ->get()->keyBy('id');

        $rows = [];
        foreach ($agents as $a) {
            $t = $targets->get($a->id);
            $d = $dealActuals->get($a->id);
            $da = $daily->get($a->id);

            $rows[] = [
                'user_id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'branch_id' => $a->branch_id,
                'branch_name' => $a->branch_id ? ($branches[$a->branch_id]->name ?? '—') : '—',
                // (AT-192 c) surfaced so team listings can badge an archived member
                // whose historical production still counts toward the branch total.
                'is_active' => (int) ($a->is_active ?? 1),
                'is_archived' => !((int) ($a->is_active ?? 1)),

                'targets' => [
                    'deals' => (int)($t->deals_target ?? 0),
                    'listings' => (int)($t->listings_target ?? 0),
                    'value' => (float)($t->value_target ?? 0),
                    'points' => (float)($t->points_target ?? 0),
                ],
                'actuals' => [
                    'deals' => (int)($d->deals_count ?? 0),
                    'sales_value' => (float)($d->sales_value ?? 0),
                'company_income' => 0,
                'agent_income' => 0,
                'company_retained' => 0,
                    'daily_rows' => (int)($da->rows_count ?? 0),
                    'points' => (float)($da->points_sum ?? 0),
                    // manual (captured by the agent) / automatic (credited by CoreX work): same rows, same rule
                    'points_manual' => (float)($da->points_manual ?? 0),
                    'points_auto' => (float)($da->points_auto ?? 0),
                ],
            ];
        }



        // Calendar context for pace/status calculations (period-level)
        // --- Finance Engine dual-read: primary source is finance_computed_values ---
        $readModel = app(FinanceReadModel::class);
        $companyEngineResult = $readModel->getCompanyPeriodMap($period, $agencyId);
        $useEngine = !empty($companyEngineResult['data']);

        if ($useEngine) {
            // ENGINE PATH: Read company income from Finance Engine
            $cData = $companyEngineResult['data'];
            $companyIncomeTotal = (float)($cData['company_period.money.total_nondeclined.ledger_company_income_ex_vat'] ?? 0);
            $companyIncomeByBranch = []; // populated per-branch below from engine
        } else {
            // FALLBACK: Inline company income calculation from deals
            $dealsInPeriod = DB::table('deals')
                ->whereBetween('deal_date', [$start->toDateString(), $end->toDateString()])
                  ->whereRaw("COALESCE(accepted_status,'') != 'D'")
                ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
                ->select('id','branch_id','total_commission','listing_external','listing_our_share_percent','selling_external','selling_our_share_percent','listing_split_percent','selling_split_percent')
                ->get();

            $companyIncomeByBranch = [];
            $companyIncomeTotal = 0.0;

            foreach ($dealsInPeriod as $d) {
                $inc = CommissionCalculator::companyIncomeExVat($d);
                $bid = (int)($d->branch_id ?? 0);
                $companyIncomeByBranch[$bid] = ($companyIncomeByBranch[$bid] ?? 0) + $inc;
                $companyIncomeTotal += $inc;
            }
        }

        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $today = Carbon::today();
        $daysInMonth = $start->daysInMonth;
        $daysElapsed = $today->betweenIncluded($start, $end) ? max(1, $start->diffInDays($today) + 1) : 1;
        $daysLeft    = $today->betweenIncluded($start, $end) ? max(0, $today->diffInDays($end)) : 0;

        /* AGENT_INCOME_ALLOCATIONS — Finance Engine primary, inline fallback */
        if ($useEngine) {
            // ENGINE PATH: Read per-agent financial data from finance_computed_values
            foreach ($rows as &$r) {
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid > 0) {
                    $agentMap = $readModel->getAgentPeriodMap($uid, $period, $agencyId);
                    $r['actuals']['company_income']   = (float)($agentMap['agent_period.money.total_nondeclined.company_income_ex_vat'] ?? 0);
                    $r['actuals']['agent_income']     = (float)($agentMap['agent_period.money.total_nondeclined.agent_income_ex_vat'] ?? 0);
                    $r['actuals']['company_retained'] = (float)($agentMap['agent_period.money.total_nondeclined.retained_ex_vat'] ?? 0);
                }
            }
            unset($r);
        } else {
            // FALLBACK: Inline allocation from deal_user table
            $allocByUser = [];
            $allocUserIds = array_values(array_unique(array_map(fn($rr) => (int)($rr['user_id'] ?? 0), $rows)));
            $allocUserIds = array_values(array_filter($allocUserIds, fn($id) => $id > 0));

            if (!empty($allocUserIds)) {
                $allocRows = DB::table('deal_user')
                    ->join('deals','deals.id','=','deal_user.deal_id')
                    ->leftJoin('users','users.id','=','deal_user.user_id')
                    ->whereIn('deal_user.user_id', $allocUserIds)
                    ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
                    ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
                    ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
                    ->select(
                        'deal_user.user_id',
                        'deal_user.deal_id',
                        'deal_user.side',
                        'deal_user.agent_split_percent',
                        'deal_user.agent_cut_percent',
                        'users.agent_cut_percent as user_default_cut_percent',
                        'deals.total_commission',
                        'deals.listing_external',
                        'deals.listing_our_share_percent',
                        'deals.selling_external',
                        'deals.selling_our_share_percent',
                        'deals.listing_split_percent',
                        'deals.selling_split_percent'
                    )
                    ->get();

                foreach ($allocRows as $ar) {
                    $uid = (int)$ar->user_id;
                    if (!isset($allocByUser[$uid])) {
                        $allocByUser[$uid] = ['company_income'=>0.0,'agent_income'=>0.0,'company_retained'=>0.0];
                    }

                    $sideIncomeExVat = (float) CommissionCalculator::companyIncomeExVatForSide($ar, $ar->side ?? null);

                    // Tier 2: agent's share of the side pool
                    $allocPct = (float)($ar->agent_split_percent ?? 0);
                    if ($allocPct < 0) $allocPct = 0;
                    if ($allocPct > 100) $allocPct = 100;
                    $allocation = round($sideIncomeExVat * ($allocPct / 100.0), 2);

                    // Tier 3: agent/company split (pivot → user default → 0)
                    $cutPct = (float)($ar->agent_cut_percent ?? $ar->user_default_cut_percent ?? 0);
                    if ($cutPct < 0) $cutPct = 0;
                    if ($cutPct > 100) $cutPct = 100;

                    $agentIncome = round($allocation * ($cutPct / 100.0), 2);
                    $companyRetained = round($allocation - $agentIncome, 2);

                    $allocByUser[$uid]['company_income'] += $sideIncomeExVat;
                    $allocByUser[$uid]['agent_income'] += $agentIncome;
                    $allocByUser[$uid]['company_retained'] += $companyRetained;
                }
            }

            foreach ($rows as &$r) {
                $uid = (int)($r['user_id'] ?? 0);
                if (isset($allocByUser[$uid])) {
                    $r['actuals']['company_income'] = round((float)$allocByUser[$uid]['company_income'], 2);
                    $r['actuals']['agent_income'] = round((float)$allocByUser[$uid]['agent_income'], 2);
                    $r['actuals']['company_retained'] = round((float)$allocByUser[$uid]['company_retained'], 2);
                }
            }
            unset($r);
        }

foreach ($rows as &$r) {


            $r['progress'] = [
                'deals_pct' => ($r['targets']['deals'] > 0) ? round(($r['actuals']['deals'] / $r['targets']['deals']) * 100, 1) : 0,
                'value_pct' => ($r['targets']['value'] > 0) ? round(($r['actuals']['sales_value'] / $r['targets']['value']) * 100, 1) : 0,
                'points_pct' => ($r['targets']['points'] > 0) ? round(($r['actuals']['points'] / $r['targets']['points']) * 100, 1) : 0,
            ];

            // Per-agent pace + status (must align with Agent dashboard)
            $pointsTarget = (float)($r['targets']['points'] ?? 0);
            $pointsActual = (float)($r['actuals']['points'] ?? 0);
            $pointsRemaining = max(0, $pointsTarget - $pointsActual);
            $pointsPerDayNeeded = ($daysLeft > 0) ? round($pointsRemaining / $daysLeft, 1) : $pointsRemaining;

            $status = '—';
            if ($pointsTarget > 0) {
                $expectedByNow = ($pointsTarget / $daysInMonth) * $daysElapsed;
                if ($pointsActual >= $pointsTarget) $status = 'Achieved';
                elseif ($pointsActual >= $expectedByNow * 1.05) $status = 'Ahead';
                elseif ($pointsActual >= $expectedByNow * 0.95) $status = 'On pace';
                else $status = 'Behind';
            }

            $r['progress']['points_status'] = $status;
            $r['progress']['points_per_day_needed'] = $pointsPerDayNeeded;
        }
        unset($r);

        // Alias sales_value to value for UI consistency (BM + Agent must match)
        foreach ($rows as &$r) {
            if (!isset($r['actuals']['value'])) {
                $r['actuals']['value'] = (float)($r['actuals']['sales_value'] ?? 0);
            }
        }
        unset($r);

        $tot = [
            'targets' => [
                'deals' => array_sum(array_map(fn($r)=>$r['targets']['deals'], $rows)),
                'listings' => array_sum(array_map(fn($r)=>$r['targets']['listings'], $rows)),
                'value' => array_sum(array_map(fn($r)=>$r['targets']['value'], $rows)),
                'points' => array_sum(array_map(fn($r)=>$r['targets']['points'], $rows)),
            ],
            'actuals' => [
                'deals' => array_sum(array_map(fn($r)=>$r['actuals']['deals'], $rows)),
                'sales_value' => array_sum(array_map(fn($r)=>$r['actuals']['sales_value'], $rows)),
                'company_income' => $companyIncomeTotal,
                'value' => array_sum(array_map(fn($r)=>$r['actuals']['sales_value'], $rows)),
                'daily_rows' => array_sum(array_map(fn($r)=>$r['actuals']['daily_rows'], $rows)),
                'points' => array_sum(array_map(fn($r)=>$r['actuals']['points'], $rows)),
                'points_manual' => array_sum(array_map(fn($r)=>$r['actuals']['points_manual'] ?? 0, $rows)),
                'points_auto' => array_sum(array_map(fn($r)=>$r['actuals']['points_auto'] ?? 0, $rows)),
            ],
        ];

        // Company totals: agent income + retained (ex VAT)
        $agentIncomeTotal = array_sum(array_map(fn($r) => (float)($r['actuals']['agent_income'] ?? 0), $rows));
        $tot['actuals']['agent_income'] = round((float)$agentIncomeTotal, 2);
        $tot['actuals']['company_retained'] = round(max(0, (float)($tot['actuals']['company_income'] ?? 0) - (float)$agentIncomeTotal), 2);

        $tot['progress'] = [
            'deals_pct' => ($tot['targets']['deals'] > 0) ? round(($tot['actuals']['deals'] / $tot['targets']['deals']) * 100, 1) : 0,
            'value_pct' => ($tot['targets']['value'] > 0) ? round(($tot['actuals']['sales_value'] / $tot['targets']['value']) * 100, 1) : 0,
            'points_pct' => ($tot['targets']['points'] > 0) ? round(($tot['actuals']['points'] / $tot['targets']['points']) * 100, 1) : 0,
        ];

        // Branch totals
        $branchTotals = [];
        foreach ($rows as $row) {
            $bid = (int)($row['branch_id'] ?? 0);
            $bname = $row['branch_name'] ?? '—';

            if (!isset($branchTotals[$bid])) {
                $branchTotals[$bid] = [
                    'branch_id' => $bid,
                    'branch_name' => $bname,
                    'targets' => ['deals'=>0,'listings'=>0,'value'=>0,'points'=>0],
                    'actuals' => [
                          'deals'=>0,
                          'sales_value'=>0,
                          'value'=>0,
                          // LEDGER (Accounting) income from deals.branch_id
                          'ledger_company_income'=>0,
                          // TEAM (BM reality) money from agent allocations
                          'company_income'=>0,
                          'agent_income'=>0,
                          'company_retained'=>0,
                          'team_company_income'=>0,
                          'team_agent_income'=>0,
                          'team_company_retained'=>0,
                          'daily_rows'=>0,
                          'points'=>0,
                          'points_manual'=>0,
                          'points_auto'=>0
                      ],
                    'progress' => ['deals_pct'=>0,'value_pct'=>0,'points_pct'=>0],
                ];
            }

            foreach (['deals','listings','value','points'] as $k) {
                $branchTotals[$bid]['targets'][$k] += (float)$row['targets'][$k];
            }

            $branchTotals[$bid]['actuals']['deals'] += (int)$row['actuals']['deals'];
            $branchTotals[$bid]['actuals']['sales_value'] += (float)$row['actuals']['sales_value'];
            $branchTotals[$bid]['actuals']['value'] += (float)$row['actuals']['sales_value'];
            $branchTotals[$bid]['actuals']['daily_rows'] += (int)$row['actuals']['daily_rows'];
            $branchTotals[$bid]['actuals']['points'] += (float)$row['actuals']['points'];
            $branchTotals[$bid]['actuals']['points_manual'] += (float)($row['actuals']['points_manual'] ?? 0);
            $branchTotals[$bid]['actuals']['points_auto'] += (float)($row['actuals']['points_auto'] ?? 0);
            // TEAM money totals (sum of agent allocations by agent branch)
            $branchTotals[$bid]['actuals']['team_company_income'] += (float)($row['actuals']['company_income'] ?? 0);
            $branchTotals[$bid]['actuals']['team_agent_income'] += (float)($row['actuals']['agent_income'] ?? 0);
            $branchTotals[$bid]['actuals']['team_company_retained'] += (float)($row['actuals']['company_retained'] ?? 0);
            // Compatibility keys used by Admin cards: treat as TEAM
            $branchTotals[$bid]['actuals']['agent_income'] += (float)($row['actuals']['agent_income'] ?? 0);
            $branchTotals[$bid]['actuals']['company_retained'] += (float)($row['actuals']['company_retained'] ?? 0);

        }

        foreach ($branchTotals as &$b) {
            /* ADMIN_BRANCH_TOTALS_SHAPE */
            // Make branch structure match getBranchRollup() so Admin company view can read $b['totals']['targets'/'actuals'].
            // This is only shaping (no math changes).
            $b['totals'] = [
                'targets' => $b['targets'] ?? [],
                'actuals' => [
                    'deals'       => (int)($b['actuals']['deals'] ?? 0),
                    'sales_value' => (float)($b['actuals']['sales_value'] ?? 0),
                    'value'       => (float)($b['actuals']['value'] ?? ($b['actuals']['sales_value'] ?? 0)),
                    'points'      => (float)($b['actuals']['points'] ?? 0),
                    'points_manual' => (float)($b['actuals']['points_manual'] ?? 0),
                    'points_auto'   => (float)($b['actuals']['points_auto'] ?? 0),
                ],
                'progress' => $b['progress'] ?? [],
            ];
            /* ADMIN_BRANCH_TOTALS_SHAPE_END */
            $bid = (int)($b['branch_id'] ?? 0);

            if ($useEngine) {
                // ENGINE PATH: Read ledger values from Finance Engine
                $branchMapResult = $readModel->getBranchPeriodMap($bid, $period, $agencyId);
                $bData = $branchMapResult['data'] ?? [];
                $b['actuals']['ledger_company_income']   = (float)($bData['branch_period.money.total_nondeclined.ledger_company_income_ex_vat'] ?? 0);
                $b['actuals']['ledger_agent_income']     = (float)($bData['branch_period.money.total_nondeclined.ledger_agent_income_ex_vat'] ?? 0);
                $b['actuals']['ledger_company_retained'] = (float)($bData['branch_period.money.total_nondeclined.ledger_company_retained_ex_vat'] ?? 0);
            } else {
                // FALLBACK: Inline ledger calculation
                $b['actuals']['ledger_company_income'] = (float)($companyIncomeByBranch[$bid] ?? 0);

                $ledgerAgent = 0.0;
                $ledgerRows = \DB::table('deal_user')
                    ->join('deals','deals.id','=','deal_user.deal_id')
                    ->leftJoin('users','users.id','=','deal_user.user_id')
                    ->where('deals.branch_id', $bid)
                    ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
                    ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
                    ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
                    ->select(
                        'deal_user.side',
                        'deal_user.agent_split_percent',
                        'deal_user.agent_cut_percent',
                        'users.agent_cut_percent as user_default_cut_percent',
                        'deals.total_commission',
                        'deals.listing_external',
                        'deals.listing_our_share_percent',
                        'deals.selling_external',
                        'deals.selling_our_share_percent',
                        'deals.listing_split_percent',
                        'deals.selling_split_percent'
                    )->get();

                foreach ($ledgerRows as $lr) {
                    $sideIncome = (float) CommissionCalculator::companyIncomeExVatForSide($lr, $lr->side ?? null);

                    // Tier 2: agent's share of the side pool
                    $allocPct = (float)($lr->agent_split_percent ?? 0);
                    if ($allocPct < 0) $allocPct = 0;
                    if ($allocPct > 100) $allocPct = 100;
                    $allocation = round($sideIncome * ($allocPct / 100.0), 2);

                    // Tier 3: agent/company split (pivot → user default → 0)
                    $cutPct = $lr->agent_cut_percent;
                    if ($cutPct === null || $cutPct === '') {
                        $cutPct = $lr->user_default_cut_percent;
                    }
                    $cutPct = (float)($cutPct ?? 0);
                    if ($cutPct < 0) $cutPct = 0;
                    if ($cutPct > 100) $cutPct = 100;

                    $ledgerAgent += round($allocation * ($cutPct / 100.0), 2);
                }

                $b['actuals']['ledger_agent_income'] = round($ledgerAgent, 2);
                $b['actuals']['ledger_company_retained'] = round(
                    max(0, ($b['actuals']['ledger_company_income'] ?? 0) - $ledgerAgent),
                    2
                );
            }

              // Admin branch cards should reflect TEAM reality (agent allocations)
              $b['actuals']['company_income'] = (float)($b['actuals']['team_company_income'] ?? 0);

            $b['progress']['deals_pct']  = ($b['targets']['deals'] > 0) ? round(($b['actuals']['deals'] / $b['targets']['deals']) * 100, 1) : 0;
            $b['progress']['value_pct']  = ($b['targets']['value'] > 0) ? round(($b['actuals']['sales_value'] / $b['targets']['value']) * 100, 1) : 0;
            $b['progress']['points_pct'] = ($b['targets']['points'] > 0) ? round(($b['actuals']['points'] / $b['targets']['points']) * 100, 1) : 0;
        }
        unset($b);

        usort($branchTotals, fn($a,$b) => strcmp((string)$a['branch_name'], (string)$b['branch_name']));

        // ---- PERIOD TOTALS FROM BRANCH CARDS (single source of truth) ----
        $ledgerCompanyIncome = 0.0;
        $ledgerAgentIncome = 0.0;
        $ledgerCompanyRetained = 0.0;

        $teamCompanyIncome = 0.0;
        $teamAgentIncome = 0.0;
        $teamCompanyRetained = 0.0;

        foreach ($branchTotals as $b) {
            $a = $b['actuals'] ?? [];

            $ledgerCompanyIncome += (float)($a['ledger_company_income'] ?? 0);
            $ledgerAgentIncome += (float)($a['ledger_agent_income'] ?? 0);
            $ledgerCompanyRetained += (float)($a['ledger_company_retained'] ?? max(0, ($a['ledger_company_income'] ?? 0) - ($a['ledger_agent_income'] ?? 0)));

            $teamCompanyIncome += (float)($a['team_company_income'] ?? 0);
            $teamAgentIncome += (float)($a['team_agent_income'] ?? 0);
            $teamCompanyRetained += (float)($a['team_company_retained'] ?? 0);
        }

        $tot['actuals']['ledger_company_income'] = round($ledgerCompanyIncome, 2);
        $tot['actuals']['ledger_agent_income'] = round($ledgerAgentIncome, 2);
        $tot['actuals']['ledger_company_retained'] = round($ledgerCompanyRetained, 2);

        $tot['actuals']['team_company_income'] = round($teamCompanyIncome, 2);
        $tot['actuals']['team_agent_income'] = round($teamAgentIncome, 2);
        $tot['actuals']['team_company_retained'] = round($teamCompanyRetained, 2);
        // -------------------------------------------------------------------


        return [
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'totals' => $tot,
            'branches' => $branchTotals,
            'rows' => $rows,
        ];
    }

    public function getBranchRollup(int $branchId, string $period, ?int $agencyId = null): array
    {
        $rollup = $this->getPeriodRollup($period, $agencyId);

        $rows = array_values(array_filter($rollup['rows'], fn($r) => (int)($r['branch_id'] ?? 0) === (int)$branchId));

        $tot = [
            'targets' => [
                'deals' => array_sum(array_map(fn($r)=>$r['targets']['deals'], $rows)),
                'listings' => array_sum(array_map(fn($r)=>$r['targets']['listings'], $rows)),
                'value' => array_sum(array_map(fn($r)=>$r['targets']['value'], $rows)),
                'points' => array_sum(array_map(fn($r)=>$r['targets']['points'], $rows)),
            ],
            'actuals' => [
                'deals' => array_sum(array_map(fn($r)=>$r['actuals']['deals'], $rows)),
                'sales_value' => array_sum(array_map(fn($r)=>$r['actuals']['sales_value'], $rows)),
                'value' => array_sum(array_map(fn($r)=>$r['actuals']['sales_value'], $rows)),
                'daily_rows' => array_sum(array_map(fn($r)=>$r['actuals']['daily_rows'], $rows)),
                'points' => array_sum(array_map(fn($r)=>$r['actuals']['points'], $rows)),
                'points_manual' => array_sum(array_map(fn($r)=>$r['actuals']['points_manual'] ?? 0, $rows)),
                'points_auto' => array_sum(array_map(fn($r)=>$r['actuals']['points_auto'] ?? 0, $rows)),
            ],
        ];
        $tot['progress'] = [
            'deals_pct' => ($tot['targets']['deals'] > 0) ? round(($tot['actuals']['deals'] / $tot['targets']['deals']) * 100, 1) : 0,
            'value_pct' => ($tot['targets']['value'] > 0) ? round(($tot['actuals']['sales_value'] / $tot['targets']['value']) * 100, 1) : 0,
            'points_pct' => ($tot['targets']['points'] > 0) ? round(($tot['actuals']['points'] / $tot['targets']['points']) * 100, 1) : 0,
        ];

        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();
        // --- BM TEAM DEAL TOTALS (single source for BM "Branch Value Actual") ---
        // Split-aware: sum each branch member's deal_user contributions × side split %.
        $splitExpr = \App\Services\Agent\AgentPerformanceService::splitAwareSalesValueExpr();

        $teamDeals = DB::table('deal_user')
            ->join('users', 'users.id', '=', 'deal_user.user_id')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            // Branch = the branch stamped on the deal, legacy fallback to the
            // agent's current branch (spec branch-archive-reassignment.md §5, AT-420).
            ->whereRaw(\App\Models\Deal::branchAttributionSql() . ' = ?', [$branchId])
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
            ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
            ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as deals_count')
            ->selectRaw("COALESCE(SUM({$splitExpr}), 0) as sales_value")
            ->first();

        $tot['actuals']['deals'] = (int)($teamDeals->deals_count ?? 0);
        $tot['actuals']['sales_value'] = (float)($teamDeals->sales_value ?? 0);
        $tot['actuals']['value'] = (float)($teamDeals->sales_value ?? 0);
        // ---------------------------------------------------------------






        /* BM_TEAM_INCOME_ALLOCATIONS */
        // TEAM truth: already computed in getPeriodRollup() per-agent rows (agent-based, no double-counting).
        // getBranchRollup should not recompute money — it must sum the filtered agent rows so the system talks 1 language.
        $agentIds = array_values(array_filter(array_map(fn($r) => (int)($r['user_id'] ?? 0), $rows)));

        $teamCompanyIncome = round(array_sum(array_map(fn($r) => (float)($r['actuals']['company_income'] ?? 0), $rows)), 2);
        $teamAgentIncome   = round(array_sum(array_map(fn($r) => (float)($r['actuals']['agent_income'] ?? 0), $rows)), 2);
        $teamCompanyRetained = round(array_sum(array_map(fn($r) => (float)($r['actuals']['company_retained'] ?? 0), $rows)), 2);
        /* BM_TEAM_INCOME_ALLOCATIONS_END */

        /* BM_V2_POINTS_PATCH */
        // BM points must match the Agent dashboard: both use ActivityPointsService (count x weight over every
        // counted entry), here for the agents of this branch who count toward the branch split.
        $branchCounted = fn () => ActivityPointsService::counted($agencyId)
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.period', $period)
            ->where('u.branch_id', $branchId)
            ->where('u.counts_for_branch_split', 1);

        $pointsActual = ActivityPointsService::sum($branchCounted());

        $pointsTarget = (float) DB::table('targets')
            ->where('period', $period)
            ->where('branch_id', $branchId)
            ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
            ->sum('points_target');

        $pointsPct = ($pointsTarget > 0) ? round(($pointsActual / $pointsTarget) * 100, 1) : 0.0;

        // Pace/status (match agent logic style)
        $today = \Carbon\Carbon::today();
        $daysInMonth = $start->daysInMonth ?? 30;
        $daysElapsed = $today->betweenIncluded($start, $end) ? max(1, $start->diffInDays($today) + 1) : 1;
        $daysLeft    = $today->betweenIncluded($start, $end) ? max(0, $today->diffInDays($end)) : 0;

        $pointsRemaining = max(0, $pointsTarget - $pointsActual);
        $pointsPerDayNeeded = ($daysLeft > 0) ? round($pointsRemaining / $daysLeft, 1) : $pointsRemaining;

        $pointsStatus = '—';
        if ($pointsTarget > 0) {
            $expectedByNow = ($pointsTarget / $daysInMonth) * $daysElapsed;
            if ($pointsActual >= $pointsTarget) $pointsStatus = 'Achieved';
            elseif ($pointsActual >= $expectedByNow * 1.05) $pointsStatus = 'Ahead';
            elseif ($pointsActual >= $expectedByNow * 0.95) $pointsStatus = 'On pace';
            else $pointsStatus = 'Behind';
        }

        $todayPoints = ActivityPointsService::sum(
            $branchCounted()->where('e.activity_date', $today->toDateString())
        );
        /* BM_V2_POINTS_PATCH_END */


        // --- LEDGER income for this branch (Finance Engine primary, inline fallback) ---
        $readModel = app(FinanceReadModel::class);
        $branchMapResult = $readModel->getBranchPeriodMap($branchId, $period, $agencyId);
        $useBranchEngine = !empty($branchMapResult['data']);

        if ($useBranchEngine) {
            // ENGINE PATH: Read ledger values from Finance Engine
            $bData = $branchMapResult['data'];
            $companyIncomeBranchTotal   = (float)($bData['branch_period.money.total_nondeclined.ledger_company_income_ex_vat'] ?? 0);
            $ledgerAgentIncomeTotal     = (float)($bData['branch_period.money.total_nondeclined.ledger_agent_income_ex_vat'] ?? 0);
            $ledgerCompanyRetainedTotal = (float)($bData['branch_period.money.total_nondeclined.ledger_company_retained_ex_vat'] ?? 0);
        } else {
            // FALLBACK: Inline ledger calculation from deals
            $dealsBranch = DB::table('deals')
                ->where('branch_id', $branchId)
                ->whereBetween('deal_date', [$start->toDateString(), $end->toDateString()])
                ->whereRaw("COALESCE(accepted_status,'') != 'D'")
                ->when($agencyId !== null, fn($q) => $q->where('agency_id', $agencyId))
                ->select('id','total_commission','listing_external','listing_our_share_percent','selling_external','selling_our_share_percent')
                ->get();

            $companyIncomeBranchTotal = 0.0;
            foreach ($dealsBranch as $d) {
                $companyIncomeBranchTotal += CommissionCalculator::companyIncomeExVat($d);
            }

            $ledgerAgentIncomeTotal = 0.0;
            $ledgerRows = DB::table('deal_user')
                ->join('deals','deals.id','=','deal_user.deal_id')
                ->leftJoin('users','users.id','=','deal_user.user_id')
                ->where('deals.branch_id', $branchId)
                ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
                ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
                ->when($agencyId !== null, fn($q) => $q->where('deals.agency_id', $agencyId))
                ->select(
                    'deal_user.user_id',
                    'deal_user.side',
                    'deal_user.agent_split_percent',
                    'deal_user.agent_cut_percent',
                    'users.agent_cut_percent as user_default_cut_percent',
                    'deals.total_commission',
                    'deals.listing_external',
                    'deals.listing_our_share_percent',
                    'deals.selling_external',
                    'deals.selling_our_share_percent',
                    'deals.listing_split_percent',
                    'deals.selling_split_percent'
                )
                ->get();

            foreach ($ledgerRows as $lr) {
                $sideIncomeExVat = (float) CommissionCalculator::companyIncomeExVatForSide($lr, $lr->side ?? null);

                // Tier 2: agent's share of the side pool
                $allocPct = (float)($lr->agent_split_percent ?? 0);
                if ($allocPct < 0) $allocPct = 0;
                if ($allocPct > 100) $allocPct = 100;
                $allocation = round($sideIncomeExVat * ($allocPct / 100.0), 2);

                // Tier 3: agent/company split (pivot → user default → 0)
                $cutPct = $lr->agent_cut_percent;
                if ($cutPct === null || $cutPct === '') {
                    $cutPct = $lr->user_default_cut_percent;
                }
                $cutPct = (float)($cutPct ?? 0);
                if ($cutPct < 0) $cutPct = 0;
                if ($cutPct > 100) $cutPct = 100;

                $ledgerAgentIncomeTotal += round($allocation * ($cutPct / 100.0), 2);
            }

            $ledgerAgentIncomeTotal = round($ledgerAgentIncomeTotal, 2);
            $ledgerCompanyRetainedTotal = round(max(0, (float)$companyIncomeBranchTotal - (float)$ledgerAgentIncomeTotal), 2);
        }

        // Attach LEDGER money into branch totals (accounting truth)
        $tot['actuals']['ledger_company_income']   = round((float)$companyIncomeBranchTotal, 2);
        $tot['actuals']['ledger_agent_income']     = round((float)$ledgerAgentIncomeTotal, 2);
        $tot['actuals']['ledger_company_retained'] = round((float)$ledgerCompanyRetainedTotal, 2);

        // BM / Targets truth = TEAM totals (agent-branch based, regardless of deal.branch_id)
        $tot['actuals']['company_income']   = $teamCompanyIncome;
        $tot['actuals']['agent_income']     = $teamAgentIncome;
        $tot['actuals']['company_retained'] = $teamCompanyRetained;

        // Preserve explicit TEAM keys too (useful for UI clarity)
        $tot['actuals']['team_company_income']   = $teamCompanyIncome;
        $tot['actuals']['team_agent_income']     = $teamAgentIncome;
        $tot['actuals']['team_company_retained'] = $teamCompanyRetained;
        $today = Carbon::today();
        $daysInMonth = $start->daysInMonth;
        $daysElapsed = $today->betweenIncluded($start, $end) ? max(1, $start->diffInDays($today) + 1) : 1;
        $daysLeft    = $today->betweenIncluded($start, $end) ? max(0, $today->diffInDays($end)) : 0;

        // BM points = SUM of agent dashboards (V2 truth)
        $pointsTarget = (float)($tot['targets']['points'] ?? 0);
        $pointsActual = (float)($tot['actuals']['points'] ?? 0);
        $pointsPct = ($pointsTarget > 0) ? round(($pointsActual / $pointsTarget) * 100, 1) : 0;
        $pointsRemaining = max(0, $pointsTarget - $pointsActual);
        $pointsPerDayNeeded = ($daysLeft > 0) ? round($pointsRemaining / $daysLeft, 1) : $pointsRemaining;

        $status = '—';
        if ($pointsTarget > 0) {
            $expectedByNow = ($pointsTarget / $daysInMonth) * $daysElapsed;
            if ($pointsActual >= $pointsTarget) $status = 'Achieved';
            elseif ($pointsActual >= $expectedByNow * 1.05) $status = 'Ahead';
            elseif ($pointsActual >= $expectedByNow * 0.95) $status = 'On pace';
            else $status = 'Behind';
        }
        // Momentum 7d + today breakdown — from the live entries table through the one shared
        // points rule (the old `daily_activities` table is abandoned and holds no rows).
        $from = (clone $today)->subDays(6)->toDateString();
        $to   = $today->toDateString();

        $m = [];
        $todayBreakdown = [];
        if (!empty($agentIds)) {
            $m = ActivityPointsService::sumByDateSplit(
                ActivityPointsService::counted($agencyId)
                    ->whereIn('e.user_id', $agentIds)
                    ->whereBetween('e.activity_date', [$from, $to])
            );

            // Breakdown of today's counted activity (name => count) across the branch.
            $todayBreakdown = ActivityPointsService::countsByActivityName(
                ActivityPointsService::counted($agencyId)
                    ->whereIn('e.user_id', $agentIds)
                    ->where('e.activity_date', $to)
            );
        }

        $momentum7 = [];
        for ($i=0; $i<7; $i++) {
            $d = (clone $today)->subDays(6-$i)->toDateString();
            $momentum7[] = ['date' => $d, 'points' => (float) ($m[$d]['total'] ?? 0), 'manual' => (float) ($m[$d]['manual'] ?? 0), 'automatic' => (float) ($m[$d]['automatic'] ?? 0)];
        }

        $rollup['rows'] = $rows;
        $rollup['totals'] = $tot;
        $rollup['branches'] = array_values(array_filter($rollup['branches'] ?? [], fn($b) => (int)($b['branch_id'] ?? 0) === (int)$branchId));

        $rollup['points'] = [
            'actual' => $pointsActual,
            'target' => $pointsTarget,
            'pct' => $pointsPct,
            'status' => $status,
            'remaining' => $pointsRemaining,
            'per_day_needed' => $pointsPerDayNeeded,
            'today_points' => $todayPoints,
            'days_left' => $daysLeft,
            'days_elapsed' => $daysElapsed,
            // manual / automatic split of the month's actual (manual + automatic = actual)
            'manual' => (float)($tot['actuals']['points_manual'] ?? 0),
            'automatic' => (float)($tot['actuals']['points_auto'] ?? 0),
        ];

        $rollup['momentum_7d'] = $momentum7;
        $rollup['activities_today'] = $todayBreakdown;

        /* ADMIN_V2_POINTS_PATCH */
        // Company-wide PACE must match BM/Agent: use the same totals already computed for this period.
        $pointsTarget = (float)($tot['targets']['points'] ?? 0);
        $pointsActual = (float)($tot['actuals']['points'] ?? 0);
        $pointsPct = ($pointsTarget > 0) ? round(($pointsActual / $pointsTarget) * 100, 1) : 0.0;

        $pointsRemaining = max(0, $pointsTarget - $pointsActual);
        $pointsPerDayNeeded = ($daysLeft > 0) ? round($pointsRemaining / $daysLeft, 1) : $pointsRemaining;

        $pointsStatus = '—';
        if ($pointsTarget > 0) {
            $expectedByNow = ($pointsTarget / $daysInMonth) * $daysElapsed;
            if ($pointsActual >= $pointsTarget) $pointsStatus = 'Achieved';
            elseif ($pointsActual >= $expectedByNow * 1.05) $pointsStatus = 'Ahead';
            elseif ($pointsActual >= $expectedByNow * 0.95) $pointsStatus = 'On pace';
            else $pointsStatus = 'Behind';
        }

        // Today points (company-wide, V2 truth) — the one shared points rule.
        $todayPoints = ActivityPointsService::sum(
            ActivityPointsService::counted($agencyId)
                ->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.period', $period)
                ->whereDate('e.activity_date', $today->toDateString())
                ->where('u.is_active', 1)
                ->whereIn('u.role', ['agent','branch_manager','admin'])
        );

        $rollup['points'] = [
            'actual' => $pointsActual,
            'target' => $pointsTarget,
            'pct' => $pointsPct,
            'status' => $pointsStatus,
            'remaining' => $pointsRemaining,
            'per_day_needed' => $pointsPerDayNeeded,
            'today_points' => $todayPoints,
            'days_left' => $daysLeft,
            'days_elapsed' => $daysElapsed,
            // manual / automatic split of the month's actual (manual + automatic = actual)
            'manual' => (float)($tot['actuals']['points_manual'] ?? 0),
            'automatic' => (float)($tot['actuals']['points_auto'] ?? 0),
        ];
        /* ADMIN_V2_POINTS_PATCH_END */

        return $rollup;
    }
    public function getAgentRollup(int $branchId, int $userId, string $period, ?int $agencyId = null): array
    {
        // Base branch rollup (single source of truth)
        $rollup = $this->getBranchRollup($branchId, $period, $agencyId);

        $rows = $rollup['rows'] ?? [];
        $agent = null;
        foreach ($rows as $r) {
            if ((int)($r['user_id'] ?? 0) === (int)$userId) {
                $agent = $r;
                break;
            }
        }

        if (!$agent) {
            abort(404, 'Agent not found in this branch for this period');
        }

        // Agent-only momentum + today breakdown, from the live entries table through the one shared
        // points rule (the old `daily_activities` table is abandoned and holds no rows).
        $today = Carbon::today();

        $from = (clone $today)->subDays(6)->toDateString();
        $to   = $today->toDateString();

        $m = ActivityPointsService::sumByDateSplit(
            ActivityPointsService::counted($agencyId)
                ->where('e.user_id', $userId)
                ->whereBetween('e.activity_date', [$from, $to])
        );

        $momentum7 = [];
        for ($i = 0; $i < 7; $i++) {
            $d = (clone $today)->subDays(6 - $i)->toDateString();
            $momentum7[] = ['date' => $d, 'points' => (float) ($m[$d]['total'] ?? 0), 'manual' => (float) ($m[$d]['manual'] ?? 0), 'automatic' => (float) ($m[$d]['automatic'] ?? 0)];
        }

        // Today breakdown (activity name => count)
        $todayBreakdown = ActivityPointsService::countsByActivityName(
            ActivityPointsService::counted($agencyId)
                ->where('e.user_id', $userId)
                ->where('e.activity_date', $to)
        );

return [
            'agent' => $agent,
            // keep the same meta block structure used elsewhere (contains pace/status/per_day_needed etc.)
            'points' => $rollup['points'] ?? [],
            'momentum_7d' => $momentum7,
            'activities_today' => $todayBreakdown,
        ];
    }

}
