<?php

use App\Models\User;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\Period;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

$periods = app(PeriodResolver::class);
$scopeResolver = app(BuyersReportScopeResolver::class);
$service = app(BuyersReportService::class);

$period = $periods->resolve('custom', '2026-06-01', '2026-08-21');
echo "PERIOD RESOLVED: start=" . $period->start->toDateTimeString() . " end=" . $period->end->toDateTimeString() . " label=" . $period->label . " preset=" . $period->preset . "\n";

function dumpReport($label, $report) {
    echo "\n=== {$label} ===\n";
    echo "COMPANY: " . json_encode($report['company']) . "\n";
    echo "BRANCHES:\n";
    foreach ($report['branches'] as $bkey => $b) {
        echo "  [{$bkey}] " . ($b['label'] ?? '?') . " => " . json_encode($b['metrics']) . "\n";
    }
    echo "AGENTS:\n";
    foreach ($report['agents'] as $a) {
        echo "  uid={$a['user_id']} ({$a['name']}) branch={$a['branch_id']} => " . json_encode($a['metrics']) . "\n";
    }
}

// 1. Retha Kelly (24) - own scope
$retha = User::find(24);
Auth::login($retha);
$scope = $scopeResolver->resolve($retha, null, null, null);
echo "Retha scope level=" . $scope->level . " agencyId=" . $scope->agencyId . " branchId=" . var_export($scope->branchId, true) . " userId=" . var_export($scope->userId, true) . "\n";
$report = $service->build($scope, $period);
dumpReport('RETHA (own)', $report);

// 2. Johan (22) admin - agency scope
$johan = User::find(22);
Auth::login($johan);
$scope = $scopeResolver->resolve($johan, null, null, null);
echo "\nJohan scope level=" . $scope->level . " agencyId=" . $scope->agencyId . " branchId=" . var_export($scope->branchId, true) . "\n";
$report = $service->build($scope, $period);
dumpReport('JOHAN (agency)', $report);

// Save for later use
file_put_contents('/tmp/verifier-buyers-oracle/johan-report.json', json_encode($report, JSON_PRETTY_PRINT));

// 3. Falan Du Bois (25) branch_manager - branch scope
$falan = User::find(25);
Auth::login($falan);
$scope = $scopeResolver->resolve($falan, null, null, null);
echo "\nFalan scope level=" . $scope->level . " agencyId=" . $scope->agencyId . " branchId=" . var_export($scope->branchId, true) . "\n";
$report = $service->build($scope, $period);
dumpReport('FALAN (branch)', $report);
