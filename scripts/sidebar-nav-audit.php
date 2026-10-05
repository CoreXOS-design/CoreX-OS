<?php
/**
 * Sidebar active-item/active-group regression audit — CLI entry point.
 *
 * Mechanically cross-references every sidebar <a> link in
 * resources/views/layouts/corex-sidebar.blade.php (route name, its
 * routeIs() active-pattern(s), and which visual panel/group it lives in)
 * against every authenticated, navigable (GET, non-API) named web route,
 * and against the top-of-file $activeGroup resolver chain.
 *
 * All parsing/matching logic lives in App\Support\Navigation\SidebarNavAuditor
 * so this script and tests/Feature/Navigation/SidebarNavMappingTest.php share
 * one implementation — exactly so this can never silently drift into two
 * disagreeing answers about the same sidebar.
 *
 * Usage:
 *   php scripts/sidebar-nav-audit.php              # human-readable report
 *   php scripts/sidebar-nav-audit.php --json        # full machine-readable dump
 *   php scripts/sidebar-nav-audit.php --dump-rules  # just the parsed $activeGroup chain
 *   php scripts/sidebar-nav-audit.php --refresh-routes  # force-regenerate the routes cache
 *
 * Exit code is always 0 (this is a reporting tool, not a gate) — the gate
 * lives in the PHPUnit test, which calls the same class directly.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Navigation\SidebarNavAuditor;

$root = dirname(__DIR__);
$bladePath = $root . '/resources/views/layouts/corex-sidebar.blade.php';
$routesJsonPath = $root . '/storage/app/_sidebar_audit_routes.json';

if (!is_file($routesJsonPath) || in_array('--refresh-routes', $argv, true)) {
    fwrite(STDERR, "Generating routes cache via `php artisan route:list --json` ...\n");
    $cmd = 'php ' . escapeshellarg($root . '/artisan') . ' route:list --json > ' . escapeshellarg($routesJsonPath);
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($routesJsonPath)) {
        fwrite(STDERR, "Failed to generate routes cache.\n");
        exit(1);
    }
}
$allRoutes = json_decode(file_get_contents($routesJsonPath), true) ?: [];

$result = SidebarNavAuditor::run($allRoutes, $bladePath);

if (in_array('--dump-rules', $argv, true)) {
    echo json_encode($result['rules'], JSON_PRETTY_PRINT);
    exit(0);
}

if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit(0);
}

echo "=== Sidebar nav audit summary ===\n";
foreach ($result['summary'] as $k => $v) {
    echo str_pad($k, 32) . ": $v\n";
}
echo "\n=== Flagged routes ===\n";
foreach ($result['rows'] as $r) {
    if (!$r['flags']) {
        continue;
    }
    echo sprintf(
        "[%s] %-55s group=%-22s alt=%-20s items=%s\n",
        implode(',', $r['flags']),
        $r['route'],
        $r['resolved_group'] ?? '(none)',
        $r['conditional_alt_group'] ?? '-',
        $r['items'] ? implode('; ', $r['items']) : '(none)'
    );
}
