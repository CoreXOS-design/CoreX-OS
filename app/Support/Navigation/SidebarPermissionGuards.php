<?php

namespace App\Support\Navigation;

/**
 * "A sidebar entry only renders when the user holds the permission its page needs."
 *
 * Rule (Johan, 14 Oct 2026, from the new-agency walk: Calendar, Tasks and portal-access
 * links showed for a minimal agent role and opened a 403). A dead link is a worse
 * experience than no link, so every sidebar link whose page is protected by a
 * `permission:` route middleware must sit inside a Blade guard that holds the SAME key
 * (`@permission('<key>')`, `@if($user->hasPermission('<key>'))`, `@if($user->hasAnyPermission([...]))`).
 *
 * Same shape as SidebarNavAuditor / SidebarFeatureCoverage: pure string work over the
 * Blade source plus a routes array supplied by the caller, so the PHPUnit test and any
 * CLI use one implementation.
 *
 * The guard parser tracks the real open/close stack of Blade conditionals. An `@else` /
 * `@elseif` branch does NOT inherit the permission of its `@if` (the user does not hold
 * it there), and a condition that ORs the permission with anything else guards nothing.
 */
final class SidebarPermissionGuards
{
    /**
     * Per 0-indexed line: the guards in force. Each guard is
     *   ['keys' => list<string>, 'any' => bool]
     * `any` = the guard passes when the user holds ANY one of the keys (hasAnyPermission),
     * so every key in it must be one the route also accepts; otherwise the guard needs
     * ALL its keys, so ONE of them being accepted by the route is enough.
     *
     * @param  array<int,string> $lines
     * @return array<int, list<array{keys:list<string>, any:bool}>>
     */
    public static function guardStacks(array $lines): array
    {
        $src = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            implode("\n", $lines)
        );
        // @php ... @endphp bodies are code, not template directives.
        $src = preg_replace_callback(
            '/@php\b.*?@endphp/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $src
        );

        $openers = ['if', 'unless', 'isset', 'auth', 'guest', 'production', 'env', 'permission', 'feature', 'can', 'cannot', 'canany'];
        $names   = implode('|', array_merge($openers, ['else', 'elseif', 'elseauth', 'elseguest']));
        $closers = implode('|', array_map(fn ($o) => 'end' . $o, $openers));

        preg_match_all('/@(' . $closers . '|' . $names . ')\b/', $src, $m, PREG_OFFSET_CAPTURE);

        // line (0-indexed) for a byte offset
        $lineOf = function (int $offset) use ($src): int {
            return substr_count($src, "\n", 0, $offset);
        };

        $total = substr_count($src, "\n") + 1;
        // events: [line, kind, guard|null]
        $events = [];
        foreach ($m[1] as [$word, $offset]) {
            $line = $lineOf($offset);
            $after = $offset + strlen($word);   // position just past the word (the '@' sits at $offset-1)
            if (str_starts_with($word, 'end')) {
                $events[] = [$line, 'close', null];
                continue;
            }
            $cond = '';
            if (isset($src[$after]) && $src[$after] === '(') {
                $cond = self::balanced($src, $after);
            }
            if ($word === 'else') {
                $events[] = [$line, 'else', null];
                continue;
            }
            if ($word === 'elseif') {
                $events[] = [$line, 'else', self::guardFromCondition('if', $cond)];
                continue;
            }
            if ($word === 'elseauth' || $word === 'elseguest') {
                $events[] = [$line, 'else', null];
                continue;
            }
            $events[] = [$line, 'open', self::guardFromCondition($word, $cond)];
        }

        $stack = [];       // each frame: ?guard (null = guards nothing)
        $out = [];
        $e = 0;
        $nEvents = count($events);
        for ($line = 0; $line < $total; $line++) {
            // A link on a line is inside every block opened on or before that line and
            // not yet closed BEFORE it. Directives on the line are replayed in order;
            // the snapshot is the state after the last `open` on the line, or — when the
            // line only closes things (`</a>@endpermission`) — the state before them.
            $before = $stack;
            $snapshot = null;
            while ($e < $nEvents && $events[$e][0] === $line) {
                [, $kind, $guard] = $events[$e];
                if ($kind === 'open') {
                    $stack[] = $guard;
                    $snapshot = $stack;
                } elseif ($kind === 'else') {
                    array_pop($stack);
                    $stack[] = $guard;
                } else {
                    array_pop($stack);
                }
                $e++;
            }
            $out[$line] = array_values(array_filter($snapshot ?? $before));
        }

        return $out;
    }

    /** The text inside a balanced (...) starting at $open (which must be '('); '' when unbalanced. */
    private static function balanced(string $src, int $open): string
    {
        $depth = 0;
        $len = strlen($src);
        $quote = null;
        for ($i = $open; $i < $len; $i++) {
            $c = $src[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open + 1, $i - $open - 1);
                }
            }
        }

        return '';
    }

    /**
     * @return array{keys:list<string>, any:bool}|null  null = this block guards no permission
     */
    private static function guardFromCondition(string $directive, string $cond): ?array
    {
        if ($directive === 'permission') {
            if (preg_match("/^\s*'([^']+)'\s*$/", $cond, $k)) {
                return ['keys' => [$k[1]], 'any' => false];
            }

            return null;
        }
        if ($directive !== 'if') {
            return null;
        }
        // A negation of the permission test never guards anything.
        if (preg_match('/!\s*[\$\w>()?-]*\s*hasA?n?y?Permission/', $cond)) {
            return null;
        }
        $flat = preg_replace("/'[^']*'|\"[^\"]*\"/", "''", $cond);
        if (str_contains($flat, '||')) {
            // An OR guards the block only when EVERY alternative is itself a permission test
            // (`hasPermission('a') || hasPermission('b')`, optionally ANDed with a Route::has()
            // existence check) — then it is an "any of these keys" guard. An OR with anything
            // else in it (a role test, a feature flag) is reachable without the key: no guard.
            $rest = preg_replace('/[\\\\\w]*Route::has\(\s*\'[^\']*\'\s*\)/', '', $cond);
            $rest = preg_replace("/[\$\w()>?-]*?hasPermission\(\s*'[^']+'\s*\)/", '', $rest);
            if (!preg_match('/^[\s&|()]*$/', $rest) || !preg_match_all("/hasPermission\(\s*'([^']+)'\s*\)/", $cond, $alts)) {
                return null;
            }

            return ['keys' => array_values(array_unique($alts[1])), 'any' => true];
        }
        $keys = [];
        $any = false;
        if (preg_match_all("/hasPermission\(\s*'([^']+)'\s*\)/", $cond, $one)) {
            $keys = array_merge($keys, $one[1]);
        }
        if (preg_match_all('/hasAnyPermission\(\s*\[([^\]]*)\]\s*\)/', $cond, $many)) {
            foreach ($many[1] as $list) {
                preg_match_all("/'([^']+)'/", $list, $q);
                if ($keys === [] && $q[1] !== []) {
                    $keys = $q[1];
                    $any = true;
                } else {
                    // mixed AND of several tests: the single-key test already narrows it
                }
            }
        }

        return $keys === [] ? null : ['keys' => array_values(array_unique($keys)), 'any' => $any];
    }

    /**
     * Every sidebar link whose route needs a permission but which is NOT inside a guard that
     * holds one of the keys the route accepts.
     *
     * @param  array<int,string> $lines                      the sidebar source lines
     * @param  array<string, list<string>> $routePermissions route name => permission keys (any one grants access)
     * @return list<array{line:int,label:string,route:string,needs:list<string>}>
     */
    public static function unguardedLinks(array $lines, array $routePermissions, array $exempt = []): array
    {
        $stacks = self::guardStacks($lines);
        $panels = SidebarNavAuditor::parsePanels($lines, count($lines));
        $entries = SidebarNavAuditor::parseNavEntries($lines, count($lines), $panels);
        $bad = [];
        foreach ($entries as $entry) {
            $i = $entry['line'] - 1;
            $chunk = $lines[$i] . (isset($lines[$i + 1]) ? "\n" . $lines[$i + 1] : '') . (isset($lines[$i + 2]) ? "\n" . $lines[$i + 2] : '');
            if (!preg_match("/route\(\s*'([a-zA-Z0-9_.\-]+)'/", $chunk, $rm)) {
                continue;
            }
            $route = $rm[1];
            $needs = $routePermissions[$route] ?? [];
            if ($needs === [] || !empty($entry['owner_only']) || in_array($route, $exempt, true)) {
                continue;
            }
            $ok = false;
            foreach ($stacks[$i] ?? [] as $guard) {
                $inRoute = array_intersect($guard['keys'], $needs);
                if ($guard['any'] ? count($inRoute) === count($guard['keys']) : $inRoute !== []) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $bad[] = ['line' => $entry['line'], 'label' => $entry['label'], 'route' => $route, 'needs' => $needs];
            }
        }

        return $bad;
    }
}
