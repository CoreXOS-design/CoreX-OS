<?php

/**
 * Print a correctly-signed Laravel session cookie value for a real user, for
 * tools (scripts/rental-smoke.mjs) that need to hand a cookie to a real
 * browser (Puppeteer) rather than curl a single page.
 *
 * Same technique as scripts/fetch-authenticated-page.php (that script's own
 * docblock has the full writeup of why a naive Encrypter::encrypt() call is
 * not enough — Laravel's CookieValuePrefix HMAC, silently dropped by
 * EncryptCookies with no error if missing): a genuine Laravel session via
 * the app's own SessionManager + auth guard, never a hand-rolled DB row.
 * This script only does the minting half — no curl — because a browser
 * needs the raw cookie value to set on its own Page, not an HTTP response.
 *
 * Usage:
 *   php8.2 scripts/mint-session-cookie.php --app-root=/corex-qa1 --user-id=22
 * Prints two lines to stdout:
 *   COOKIE_NAME=<name>
 *   COOKIE_VALUE=<value>
 */

$opts = getopt('', ['app-root:', 'user-id:']);
foreach (['app-root', 'user-id'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "Missing required --{$required}\n");
        exit(2);
    }
}

$appRoot = rtrim($opts['app-root'], '/');
$userId = (int) $opts['user-id'];

chdir($appRoot);
require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find($userId);
if (!$user) {
    fwrite(STDERR, "User {$userId} not found in {$appRoot}\n");
    exit(2);
}

$store = $app->make('session')->driver();
$store->start();
$store->regenerate(true);
$app->instance('session.store', $store);

$guard = $app->make('auth')->guard('web');
$guard->login($user);
$store->save();

$sessionId = $store->getId();
$cookieName = config('session.cookie');
$encrypter = $app->make('encrypter');
$prefixed = \Illuminate\Cookie\CookieValuePrefix::create($cookieName, $encrypter->getKey()) . $sessionId;
$cookieValue = $encrypter->encrypt($prefixed, false);

echo "COOKIE_NAME={$cookieName}\n";
echo "COOKIE_VALUE={$cookieValue}\n";
