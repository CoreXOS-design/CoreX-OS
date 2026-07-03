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
