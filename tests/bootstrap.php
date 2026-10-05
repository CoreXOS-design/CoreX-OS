<?php

/*
|--------------------------------------------------------------------------
| Test bootstrap — per-lane test-database routing WITH the safety guard
|--------------------------------------------------------------------------
|
| Historically phpunit.xml hard-pinned DB_DATABASE=hfc_dash_test so the suite
| could never run against a real/dev database. That pin was also a force-set
| that Laravel's immutable dotenv could not override — which meant EVERY
| worktree/lane (cc1, cc2, cc3, ...) shared the ONE hfc_dash_test schema. Two
| lanes running `php artisan test` at once collided under RefreshDatabase.
|
| This bootstrap makes the test DB env-driven per worktree while STRENGTHENING
| the guard rather than weakening it:
|
|   1. The name is resolved from a DEDICATED key, TEST_DB_DATABASE — never from
|      DB_DATABASE — so a lane's real .env (DB_DATABASE=corex_dev3) can never
|      leak into the suite by accident.
|   2. Precedence: shell env TEST_DB_DATABASE  ->  the worktree's .env
|      TEST_DB_DATABASE  ->  the safe default 'hfc_dash_test'.
|   3. The resolved name MUST match the test-DB whitelist (hfc_dash_test or
|      hfc_dash_test_<N>). Anything else aborts the run before a single query.
|   4. The result is force-set into the process environment so Laravel's
|      immutable dotenv loader leaves it untouched.
|
| Per-lane wiring lives in each worktree's gitignored .env:
|     dev-1 -> TEST_DB_DATABASE=hfc_dash_test
|     dev-2 -> TEST_DB_DATABASE=hfc_dash_test_2
|     dev-3 -> TEST_DB_DATABASE=hfc_dash_test_3
| A lane that sets nothing falls back to the shared hfc_dash_test (safe, just
| not isolated) — never to a dev database.
|
| A second, runtime copy of the same guard lives in Tests\TestCase::setUp()
| so the whitelist is enforced again after the app has fully booted.
*/

require __DIR__.'/../vendor/autoload.php';

(static function (): void {
    $allowed = '/^hfc_dash_test(_[0-9]+)?$/';
    $default = 'hfc_dash_test';

    // 1. Shell export wins (handy for CI / one-off overrides).
    $name = getenv('TEST_DB_DATABASE') ?: null;

    // 2. Otherwise read the dedicated key straight out of the worktree's .env
    //    (bootstrap runs before Laravel loads any env file, so parse it here).
    if ($name === null) {
        $envFile = __DIR__.'/../.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*TEST_DB_DATABASE\s*=\s*(.*)$/', $line, $m)) {
                    $name = trim(trim($m[1]), "\"'"); // last assignment wins
                }
            }
        }
    }

    // 3. Safe default.
    $name = ($name !== null && $name !== '') ? $name : $default;

    // 4. Whitelist guard — refuse anything that is not a throwaway test schema.
    if (! preg_match($allowed, $name)) {
        fwrite(STDERR, PHP_EOL
            .'  [TEST SAFETY GUARD] TEST_DB_DATABASE resolved to "'.$name.'", which is'.PHP_EOL
            .'  not an allowed test database. Allowed: hfc_dash_test or hfc_dash_test_<N>.'.PHP_EOL
            .'  Refusing to run the suite.'.PHP_EOL.PHP_EOL);
        exit(1);
    }

    // 5. Force-set so the immutable dotenv loader keeps our value.
    putenv('DB_DATABASE='.$name);
    $_ENV['DB_DATABASE'] = $name;
    $_SERVER['DB_DATABASE'] = $name;
})();

/*
|--------------------------------------------------------------------------
| Tests connect ONLY to the dedicated tests-only MySQL instance — never
| the shared instance that also serves live/Staging/QA1/QA2/demo.
| (2026-10-05, Johan-approved — see /root/LANETEST-MYSQL.md)
|--------------------------------------------------------------------------
|
| A second MySQL instance (Docker container corex-lanetest-mysql, bound to
| 127.0.0.1:3317 only) exists purely for hfc_dash_test_* schemas, with
| durability settings relaxed (no binlog, no doublewrite, no fsync-per-
| commit) because this data is always throwaway and rebuilt from
| database/schema/mysql-schema.sql. Its connection details are forced here
| the same way DB_DATABASE is forced above — unconditionally, regardless
| of what DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD a worktree's own .env
| says — so NO test run can reach the shared instance, not even by
| accident. Credentials come from /root/.lanetest-mysql-credentials
| (root-only, outside any worktree, never committed, never echoed).
|
| DB_SOCKET is force-EMPTY here too (2026-10-05, cc2 bug report). Laravel's
| mysql connection config is 'unix_socket' => env('DB_SOCKET', ''), and the
| MySQL PDO connector prefers a non-empty unix_socket over host/port
| entirely. A worktree whose .env sets DB_SOCKET (pointing at the SHARED
| instance's socket) silently routed the connection there as the
| 'lanetest' user, who doesn't exist on that instance -- "Access denied",
| not a hostname problem. Clearing it here removes that path for good.
*/
(static function (): void {
    // ONLY ever active on a box that actually has this file -- the shared
    // cc1-cc6 Linux box, not a universal requirement. This same bootstrap
    // runs on every developer's own machine too (Windows/Laragon included,
    // per CLAUDE.md's "mysql on PATH" section), where this file correctly
    // does not exist and tests have always used that machine's own local
    // MySQL via its own .env. Missing file -> do nothing here and let
    // DB_HOST/PORT/USERNAME/PASSWORD fall through to .env exactly as
    // before. This must never become a hard failure on a machine that was
    // never meant to have this file.
    $credsFile = '/root/.lanetest-mysql-credentials';
    if (! is_file($credsFile)) {
        return;
    }

    $vars = [];
    foreach (file($credsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            $vars[$m[1]] = trim($m[2]);
        }
    }

    $force = static function (string $key, string $value): void {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    };

    $force('DB_HOST', '127.0.0.1');
    $force('DB_PORT', '3317');
    $force('DB_USERNAME', $vars['LANETEST_DB_USER'] ?? 'lanetest');
    $force('DB_PASSWORD', $vars['LANETEST_DB_PASSWORD'] ?? '');
    $force('DB_SOCKET', '');

    // Guard: refuse to proceed if DB_SOCKET somehow isn't empty after the
    // force-set above -- a non-empty unix_socket silently wins over
    // host/port in Laravel's MySQL connector, which is exactly the bug
    // this block exists to close. Never let a test run fall through to
    // the shared instance's socket by any path.
    if (getenv('DB_SOCKET') !== '' || ($_ENV['DB_SOCKET'] ?? '') !== '') {
        fwrite(STDERR, PHP_EOL
            .'  [TEST SAFETY GUARD] DB_SOCKET is not empty after being forced empty --'.PHP_EOL
            .'  refusing to risk a test run connecting via a unix socket instead of'.PHP_EOL
            .'  the dedicated tests-only instance at 127.0.0.1:3317.'.PHP_EOL.PHP_EOL);
        exit(1);
    }
})();

/*
|--------------------------------------------------------------------------
| Persistent-schema fast path — skip RefreshDatabase's own migrate:fresh
| when scripts/lane-test.sh has ALREADY confirmed this schema is current
| (2026-10-05, see .ai/STANDARDS.md Standard -1x).
|--------------------------------------------------------------------------
|
| RefreshDatabase (used individually by every *Test.php file, never from
| this base) always calls migrate:fresh on the first test of a process --
| that's a FULL wipe + reload of the whole schema, every single `artisan
| test` invocation, even when nothing changed since the last one. On this
| box that costs ~270s+ (MySQL durability settings under multi-lane load,
| not a tooling bug -- see the Standard for the measured numbers).
|
| scripts/lane-test.sh now keeps ONE persistent schema per lane and only
| touches it when a fingerprint (hash of the schema dump + every migration
| file) says something actually changed. When it's confirmed current, it
| sets LANE_TEST_SCHEMA_READY=1 for this process. Trust that signal ONLY
| here, and ONLY by pre-setting RefreshDatabaseState::$migrated -- that is
| the exact flag RefreshDatabase itself checks before calling
| migrateDatabases(), so setting it true makes the trait skip straight to
| wrapping each test in a transaction against the already-correct schema,
| with zero change to RefreshDatabase or to any of the 978 test files that
| `use` it.
|
| Fails safe: anyone running tests outside lane-test.sh never has this env
| var set, so RefreshDatabase behaves exactly as it always has (full
| migrate:fresh on first test) -- this is purely additive.
|
| Known trade-off, not hidden: a test that escapes its own wrapping
| transaction (raw DDL, an explicit commit, multi-connection work) can now
| leave residue for the NEXT run, where migrate:fresh previously wiped it
| unconditionally. `scripts/lane-test.sh --fresh` forces a full rebuild to
| recover from exactly that.
*/
if (getenv('LANE_TEST_SCHEMA_READY') === '1') {
    \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
}
