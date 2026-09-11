<?php

use App\Models\User;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportDrilldownService;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use App\Services\Performance\HierarchyResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

$periods = app(PeriodResolver::class);
$scopeResolver = app(BuyersReportScopeResolver::class);
$hierarchy = app(HierarchyResolver::class);
$drill = app(BuyersReportDrilldownService::class);

$period = $periods->resolve('custom', '2026-06-01', '2026-08-21');

$johan = User::find(22);
Auth::login($johan);
$scope = $scopeResolver->resolve($johan, null, null, null);
$perfScope = new PerformanceScope($scope->agencyId, $scope->branchId, $scope->userId);
$agents = $hierarchy->agents($perfScope);
$userIds = $agents->pluck('id')->map(fn ($i) => (int) $i)->all();

$reportJson = json_decode(file_get_contents('/tmp/verifier-buyers-oracle/johan-report.json'), true);
$tiles = $reportJson['company'];

// Map tile keys to drilldown metric keys (from controller's known METRICS list)
$metricMap = [
    'buyers' => 'buyers',
    'buyers_added' => 'buyers_added',
    'buyers_won' => 'buyers_won',
    'appointments' => 'appointments',
    'lost' => 'lost',
    'lost_value' => 'lost_value',
];

echo "Available drilldown metrics: " . implode(',', BuyersReportDrilldownService::METRICS) . "\n\n";

foreach ($metricMap as $tileKey => $metricKey) {
    if (!in_array($metricKey, BuyersReportDrilldownService::METRICS, true)) {
        echo "SKIP {$tileKey} -> {$metricKey} (not a valid drilldown metric)\n";
        continue;
    }
    $res = $drill->rows($metricKey, $userIds, $period, $scope->agencyId);
    $tileVal = $tiles[$tileKey] ?? 'N/A';
    $match = ((string) $tileVal === (string) $res['count']) ? 'MATCH' : 'MISMATCH';
    echo "{$tileKey}: tile={$tileVal}  drilldown_count={$res['count']}  truncated=" . ($res['truncated'] ? 'yes' : 'no') . "  [{$match}]\n";
    if ($match === 'MISMATCH') {
        echo "  ROWS: " . json_encode($res['rows']) . "\n";
    }
}
