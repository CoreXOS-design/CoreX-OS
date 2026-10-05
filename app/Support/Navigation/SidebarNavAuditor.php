<?php

namespace App\Support\Navigation;

/**
 * Single mechanical cross-reference between the sidebar's $activeGroup
 * resolver chain + per-link active patterns (parsed out of
 * resources/views/layouts/corex-sidebar.blade.php) and the app's
 * authenticated, navigable (GET, non-API) named routes.
 *
 * Framework-free on purpose: this is pure string/regex processing over the
 * Blade source plus a routes array ({name, uri, method, middleware}[]) the
 * caller supplies — no Laravel boot required. That lets the SAME logic run
 * from the plain CLI script (scripts/sidebar-nav-audit.php, routes sourced
 * from `php artisan route:list --json`) and from a PHPUnit test (routes
 * sourced from the booted Router) without two implementations drifting
 * apart — which is exactly the class of bug this tool exists to catch.
 *
 * See .ai/audits/sidebar-active-item-audit-2026-10-05.md for the narrative
 * findings this produced.
 */
class SidebarNavAuditor
{
    /**
     * @param array<int, array{name:?string, uri:?string, method:?string, middleware:array<int,string>}> $routes
     * @return array{summary: array, rows: array, panels: array, rules: array, navEntries: array}
     */
    public static function run(array $routes, string $bladePath): array
    {
        $lines = file($bladePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Could not read sidebar blade file at {$bladePath}");
        }
        $totalLines = count($lines);

        $rules = self::parseActiveGroupChain($lines);
        $panels = self::parsePanels($lines, $totalLines);
        $navEntries = self::parseNavEntries($lines, $totalLines, $panels);
        $authRoutes = self::filterAuthenticatedNavigableRoutes($routes);

        [$rows, $summary] = self::crossReference($authRoutes, $navEntries, $rules, $panels);

        return compact('summary', 'rows', 'panels', 'rules', 'navEntries');
    }

    // ───────────────────────── Str::is() glob matcher ─────────────────────
    // Reimplementation of Illuminate\Support\Str::is() — exactly what
    // Route::named()/routeIs() use internally.
    public static function strIs(string $pattern, string $value): bool
    {
        if ($pattern === $value) {
            return true;
        }
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('#^' . $pattern . '\z#u', $value);
    }

    public static function anyMatch(array $patterns, string $routeName): bool
    {
        foreach ($patterns as $p) {
            if (self::strIs($p, $routeName)) {
                return true;
            }
        }
        return false;
    }

    // ───────────────────────── 1. $activeGroup resolver chain ─────────────
    public static function parseActiveGroupChain(array $lines): array
    {
        $chainStart = null;
        $chainEnd = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Active group detection')) {
                $chainStart = $i;
            }
            if ($chainStart !== null && str_contains($line, 'Nested groups (a panel that lives inside another panel)')) {
                $chainEnd = $i;
                break;
            }
        }
        if ($chainStart === null || $chainEnd === null) {
            throw new \RuntimeException('Could not locate the $activeGroup resolver chain markers — blade file structure changed.');
        }
        $chainLines = array_slice($lines, $chainStart, $chainEnd - $chainStart);
        $chainText = implode("\n", $chainLines);

        // Walk line-by-line tracking the condition text since the last
        // `if (` / `} elseif (` until the line whose paren-depth returns to
        // zero while ending in `) {` (the branch-opening brace).
        $branchConditions = [];
        $cur = null;
        $depth = 0;
        foreach (explode("\n", $chainText) as $line) {
            if (preg_match('/^\s*(?:if|\}\s*elseif)\s*\(/', $line)) {
                $cur = '';
                $depth = 0;
            }
            if ($cur !== null) {
                $cur .= $line . "\n";
                $depth += substr_count($line, '(') - substr_count($line, ')');
                if ($depth <= 0 && preg_match('/\)\s*\{\s*$/', rtrim($line))) {
                    $branchConditions[] = $cur;
                    $cur = null;
                }
            }
        }

        // Strip trailing `// ...` line comments before extracting quoted
        // patterns — several branches annotate entries inline, and some
        // comments contain route-name-shaped quoted text purely as
        // documentation, which would otherwise be mistaken for real
        // routeIs() arguments.
        $stripLineComments = fn (string $text): string => implode("\n", array_map(
            fn ($l) => preg_replace('#(?<!:)//.*$#', '', $l),
            explode("\n", $text)
        ));

        // Extract every `request()->routeIs(...)` call's argument text by
        // paren-depth matching (robust to args spanning many lines), tagging
        // each as negated if immediately preceded by `!`.
        $extractRouteIsCalls = function (string $cond): array {
            $calls = [];
            $offset = 0;
            $anchor = 'request()->routeIs(';
            while (($p = strpos($cond, $anchor, $offset)) !== false) {
                $negated = $p > 0 && substr(rtrim(substr($cond, 0, $p)), -1) === '!';
                $start = $p + strlen($anchor);
                $depth = 1;
                $i = $start;
                $len = strlen($cond);
                while ($i < $len && $depth > 0) {
                    if ($cond[$i] === '(') {
                        $depth++;
                    }
                    if ($cond[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }
                $calls[] = ['args' => substr($cond, $start, $i - 1 - $start), 'negated' => $negated];
                $offset = $i;
            }
            return $calls;
        };

        $rules = [];
        foreach ($branchConditions as $idx => $cond) {
            $cond = $stripLineComments($cond);
            $calls = $extractRouteIsCalls($cond);
            $positive = [];
            $negative = [];
            foreach ($calls as $call) {
                preg_match_all("/'([^']*)'/", $call['args'], $q);
                foreach ($q[1] as $p) {
                    if ($call['negated']) {
                        $negative[] = $p;
                    } else {
                        $positive[] = $p;
                    }
                }
            }
            $hasSession = (bool) preg_match('/\bsession\(/', $cond);
            $hasReferer = (bool) preg_match('/\breferer\b/i', $cond);
            if (!$positive) {
                continue; // branch guards something other than routeIs
            }
            $rules[] = [
                'index' => $idx,
                'positive' => array_values(array_unique($positive)),
                'negative' => array_values(array_unique($negative)),
                'conditional' => $hasSession || $hasReferer,
                'conditional_kind' => $hasSession && $hasReferer ? 'session+referer' : ($hasSession ? 'session' : ($hasReferer ? 'referer' : null)),
            ];
        }

        // Attach the $activeGroup assignment that follows each branch's `{`.
        $chainLines2 = explode("\n", $chainText);
        $branchStartLines = [];
        $cur = null;
        foreach ($chainLines2 as $ln => $line) {
            if (preg_match('/^\s*(?:if|\}\s*elseif)\s*\(/', $line)) {
                $cur = $ln;
            }
            if ($cur !== null && preg_match('/\)\s*\{\s*$/', rtrim($line))) {
                $branchStartLines[] = $cur;
                $cur = null;
            }
        }
        foreach ($rules as &$rule) {
            $startLn = $branchStartLines[$rule['index']] ?? null;
            $rule['group'] = null;
            if ($startLn !== null) {
                for ($ln = $startLn; $ln < count($chainLines2) && $ln < $startLn + 60; $ln++) {
                    if (preg_match("/\\\$activeGroup\s*=\s*'([a-z0-9_-]+)'/", $chainLines2[$ln], $gm)) {
                        $rule['group'] = $gm[1];
                        break;
                    }
                }
            }
        }
        unset($rule);

        // Documented special case: the branch guarding corex.dashboard /
        // corex.dashboard.oversight has a referer-sniffing sub-condition
        // INSIDE its body (not in the matched condition text), deciding
        // whether $activeGroup becomes 'command-center' or stays null.
        foreach ($rules as &$rule) {
            if ($rule['positive'] === ['corex.dashboard', 'corex.dashboard.oversight']) {
                $rule['conditional'] = true;
                $rule['conditional_kind'] = 'referer';
            }
        }
        unset($rule);

        return $rules;
    }

    /**
     * Returns [defaultGroup, conditional, conditionalKind, conditionalAltGroup].
     * See the long comment in the original audit script / the audit doc for
     * why conditional (session/referer-gated) branches are not simply "first
     * match wins" — a CONDITIONAL branch only wins when its extra gate is
     * true, so the first UNCONDITIONAL matching branch is the default, and
     * an earlier conditional match is reported as the alternate.
     */
    public static function resolveActiveGroup(string $routeName, array $rules): array
    {
        $conditionalAlt = null;
        foreach ($rules as $rule) {
            if (!self::anyMatch($rule['positive'], $routeName) || self::anyMatch($rule['negative'], $routeName)) {
                continue;
            }
            if ($rule['conditional']) {
                if ($conditionalAlt === null) {
                    $conditionalAlt = [$rule['group'], $rule['conditional_kind']];
                }
                continue;
            }
            return [$rule['group'], false, null, $conditionalAlt[0] ?? null];
        }
        if ($conditionalAlt !== null) {
            return [$conditionalAlt[0], true, $conditionalAlt[1], null];
        }
        return [null, false, null, null];
    }

    // ───────────────────────── 2. Panels + nav entries ─────────────────────
    public static function parsePanels(array $lines, int $totalLines): array
    {
        $panels = [];
        for ($i = 0; $i < $totalLines; $i++) {
            if (str_contains($lines[$i], 'corex-nav-panel')
                && preg_match("/\\\$activeGroup === '([a-z0-9_-]+)'|\\\$groupOpen\('([a-z0-9_-]+)'\)/", $lines[$i], $gm)) {
                $group = $gm[1] !== '' ? $gm[1] : $gm[2];
                $depth = substr_count($lines[$i], '<div') - substr_count($lines[$i], '</div>');
                $j = $i + 1;
                while ($j < $totalLines && $depth > 0) {
                    $depth += substr_count($lines[$j], '<div') - substr_count($lines[$j], '</div>');
                    $j++;
                }
                $title = null;
                for ($t = $i; $t < min($j, $i + 10); $t++) {
                    if (preg_match('/corex-nav-panel-title[^>]*>([^<]*)</', $lines[$t], $tm)) {
                        $title = trim($tm[1]);
                        break;
                    }
                }
                $panels[] = ['group' => $group, 'title' => $title, 'start' => $i, 'end' => $j - 1];
            }
        }
        return $panels;
    }

    /**
     * A nested panel (e.g. HR → Payroll) has its range fully CONTAINED
     * within its parent's range — both match a link that lives in the
     * innermost one. First-match-wins would always report the OUTER
     * (parent) group for every link in a nested panel, since parsePanels()
     * appends panels in the order their div line is encountered (parent
     * before child). Correct resolution is the panel with the SMALLEST
     * (tightest) span that still contains the line — the standard
     * "nearest enclosing scope" rule. Added 2026-10-05 for the HR menu
     * (HR → Payroll / HR → Documents), the first LIVE three-level nesting
     * this auditor has had to resolve.
     */
    public static function groupAtLine(int $line, array $panels): ?string
    {
        $bestGroup = null;
        $bestSpan = null;
        foreach ($panels as $p) {
            if ($line < $p['start'] || $line > $p['end']) {
                continue;
            }
            $span = $p['end'] - $p['start'];
            if ($bestSpan === null || $span < $bestSpan) {
                $bestSpan = $span;
                $bestGroup = $p['group'];
            }
        }
        return $bestGroup;
    }

    public static function parseNavEntries(array $lines, int $totalLines, array $panels): array
    {
        $navEntries = [];
        for ($i = 0; $i < $totalLines; $i++) {
            if (!preg_match('/<a\s/', $lines[$i])) {
                continue;
            }
            $chunk = $lines[$i];
            $j = $i;
            while (!str_contains($chunk, '>') && $j < $totalLines - 1 && $j < $i + 5) {
                $j++;
                $chunk .= "\n" . $lines[$j];
            }
            if (!preg_match('/class="([^"]*)"/', $chunk, $cm)) {
                continue;
            }
            $classes = $cm[1];
            if (!str_contains($classes, 'corex-nav-item') && !str_contains($classes, 'corex-nav-subitem')) {
                continue;
            }
            $isToggle = str_contains($classes, 'corex-nav-group-toggle');

            $routeName = null;
            if (preg_match("/route\('([a-zA-Z0-9_.\-]+)'\)/", $chunk, $rm)) {
                $routeName = $rm[1];
            }

            $patterns = [];
            if (preg_match("/routeIs\(\s*((?:'[^']*'\s*,?\s*)+)\)/s", $chunk, $pm)) {
                preg_match_all("/'([^']*)'/", $pm[1], $qm);
                $patterns = $qm[1];
            } elseif (preg_match("/\\\$activeGroup === '([a-z0-9_-]+)'/", $chunk, $am)) {
                $patterns = ['::activeGroup=' . $am[1]];
            } elseif (preg_match("/\\\$groupOpen\('([a-z0-9_-]+)'\)/", $chunk, $am2)) {
                $patterns = ['::groupOpen=' . $am2[1]];
            }

            $labelChunk = '';
            $k = $j;
            $closed = false;
            while ($k < $totalLines && $k < $j + 15) {
                $labelChunk .= $lines[$k] . "\n";
                if (str_contains($lines[$k], '</a>')) {
                    $closed = true;
                    break;
                }
                $k++;
            }
            $label = $closed ? trim(preg_replace('/\s+/', ' ', strip_tags($labelChunk))) : null;
            if ($label !== null && strlen($label) > 60) {
                $label = substr($label, 0, 57) . '...';
            }

            $navEntries[] = [
                'line' => $i + 1,
                'route_name' => $routeName,
                'patterns' => $patterns,
                'is_toggle' => $isToggle,
                'group' => self::groupAtLine($i, $panels),
                'label' => $label ?: '(unlabeled)',
            ];
        }
        return $navEntries;
    }

    // ───────────────────────── 3. Route filtering ──────────────────────────
    /** @param array<int, array{name:?string,uri:?string,method:?string,middleware:array<int,string>}> $routes */
    public static function filterAuthenticatedNavigableRoutes(array $routes): array
    {
        $authRoutes = [];
        foreach ($routes as $r) {
            if (empty($r['name'])) {
                continue;
            }
            $mw = $r['middleware'] ?? [];
            $isWeb = in_array('web', $mw, true);
            // Exact 'auth' / 'auth:guard' (the alias form from Route::getRoutes()
            // in a booted app) or the resolved FQCN (the form `route:list --json`
            // emits) — NOT a loose prefix match, which also catches unrelated
            // aliases like 'auth.nocache' (a cache-header middleware shown to
            // GUESTS on login/password pages) and 'auth.wa_capture' (a
            // webhook-token guard, not session auth), both of which would
            // otherwise be misclassified as "authenticated app" routes.
            $isAuth = (bool) array_filter($mw, fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:') || $m === 'Illuminate\\Auth\\Middleware\\Authenticate');
            $isGettable = str_contains($r['method'] ?? '', 'GET');
            $isApi = str_starts_with($r['name'], 'api.') || str_starts_with($r['uri'] ?? '', 'api/');
            if (!$isWeb || !$isAuth || !$isGettable || $isApi) {
                continue;
            }
            $authRoutes[$r['name']] = $r;
        }
        ksort($authRoutes);
        return $authRoutes;
    }

    // ───────────────────────── 4. Cross-reference ──────────────────────────
    public static function crossReference(array $authRoutes, array $navEntries, array $rules, array $panels): array
    {
        $rows = [];
        foreach ($authRoutes as $name => $r) {
            [$resolvedGroup, $conditional, $condKind, $conditionalAltGroup] = self::resolveActiveGroup($name, $rules);

            $matchedEntries = [];
            foreach ($navEntries as $e) {
                if ($e['route_name'] === $name) {
                    $matchedEntries[] = $e;
                    continue;
                }
                foreach ($e['patterns'] as $p) {
                    if (str_starts_with($p, '::')) {
                        continue;
                    }
                    if (self::strIs($p, $name)) {
                        $matchedEntries[] = $e;
                        break;
                    }
                }
            }
            $seen = [];
            $matchedEntries = array_values(array_filter($matchedEntries, function ($e) use (&$seen) {
                if (isset($seen[$e['line']])) {
                    return false;
                }
                $seen[$e['line']] = true;
                return true;
            }));

            $itemGroups = array_values(array_unique(array_map(fn ($e) => $e['group'] ?? '(root)', $matchedEntries)));

            $flags = [];
            if (count($matchedEntries) === 0) {
                $flags[] = 'NO_ITEM';
            }
            if (count($matchedEntries) > 1 && count(array_unique(array_column($matchedEntries, 'line'))) > 1) {
                $distinctLabels = array_unique(array_map(fn ($e) => $e['label'] . '|' . $e['group'], $matchedEntries));
                if (count($distinctLabels) > 1) {
                    $flags[] = 'MULTI_MATCH';
                }
            }
            if (count($matchedEntries) > 0 && $resolvedGroup !== null) {
                $mismatch = false;
                foreach ($itemGroups as $ig) {
                    if ($ig !== $resolvedGroup && $ig !== $conditionalAltGroup && $ig !== '(root)') {
                        $mismatch = true;
                    }
                }
                if ($mismatch) {
                    $flags[] = 'WRONG_GROUP';
                }
            }
            if (count($matchedEntries) > 0 && $resolvedGroup === null && !in_array('(root)', $itemGroups, true)) {
                $flags[] = 'GROUP_NEVER_OPENS';
            }

            $rows[] = [
                'route' => $name,
                'uri' => $r['uri'],
                'resolved_group' => $resolvedGroup,
                'conditional_alt_group' => $conditionalAltGroup,
                'conditional' => $conditional ? $condKind : null,
                'items' => array_map(fn ($e) => ($e['group'] ?? '(root)') . ' » ' . $e['label'] . ' [line ' . $e['line'] . ']' . ($e['is_toggle'] ? ' (toggle)' : ''), $matchedEntries),
                'flags' => $flags,
            ];
        }

        $summary = [
            'total_authenticated_named_routes' => count($authRoutes),
            'total_sidebar_nav_entries' => count($navEntries),
            'total_panels' => count($panels),
            'no_item' => count(array_filter($rows, fn ($r) => in_array('NO_ITEM', $r['flags'], true))),
            'no_item_and_no_group' => count(array_filter($rows, fn ($r) => in_array('NO_ITEM', $r['flags'], true) && !$r['resolved_group'])),
            'no_item_but_group_ok' => count(array_filter($rows, fn ($r) => in_array('NO_ITEM', $r['flags'], true) && $r['resolved_group'])),
            'multi_match' => count(array_filter($rows, fn ($r) => in_array('MULTI_MATCH', $r['flags'], true))),
            'wrong_group' => count(array_filter($rows, fn ($r) => in_array('WRONG_GROUP', $r['flags'], true))),
            'group_never_opens' => count(array_filter($rows, fn ($r) => in_array('GROUP_NEVER_OPENS', $r['flags'], true))),
            'conditional' => count(array_filter($rows, fn ($r) => $r['conditional'] !== null)),
        ];

        return [$rows, $summary];
    }
}
