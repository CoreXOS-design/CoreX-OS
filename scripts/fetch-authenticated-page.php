<?php

/**
 * Fetch a page as a REAL authenticated user, through the REAL web server
 * (nginx/php-fpm) — not an in-process Kernel::handle() call, which runs
 * under a completely different PHP process/OPcache instance than what
 * actually serves live traffic. That distinction is not academic: this
 * exact gap let a "verified fixed" report stand while the live page was
 * still broken, because the in-process render used a fresh PHP process
 * that always re-read the current file, while the real php-fpm pool kept
 * serving whatever it had already compiled.
 *
 * Builds a genuine Laravel session (through the app's own SessionManager
 * and auth guard — never a hand-rolled DB row) and a correctly-encrypted
 * session cookie (including Laravel's CookieValuePrefix, which a naive
 * Encrypter::encrypt() call omits and which makes EncryptCookies silently
 * drop the cookie server-side with no visible error), then curls the
 * given URL with that cookie.
 *
 * Usage:
 *   php scripts/fetch-authenticated-page.php \
 *       --app-root=/corex-qa1 \
 *       --user-id=22 \
 *       --url=https://qatesting1.corexos.co.za/corex/rental-applications/107/review \
 *       --out=/tmp/rendered.html \
 *       [--php-bin=php8.2]   # the binary matching the pool that actually serves --url
 *
 * Exits non-zero (and prints the HTTP status) if the fetch didn't return
 * 2xx — callers should treat anything else as "could not verify."
 */

$opts = getopt('', ['app-root:', 'user-id:', 'url:', 'out:', 'php-bin::']);
foreach (['app-root', 'user-id', 'url', 'out'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "Missing required --{$required}\n");
        exit(2);
    }
}

$appRoot = rtrim($opts['app-root'], '/');
$userId = (int) $opts['user-id'];
$url = $opts['url'];
$outPath = $opts['out'];
$phpBin = $opts['php-bin'] ?? PHP_BINARY;

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

// NOT --cookie @file — that flag reads a Netscape-format cookie-JAR file
// (tab-separated columns), not a plain "name=value" string; passing our
// value that way silently sends no cookie at all (curl doesn't error, it
// just parses nothing usable), which looks exactly like an auth failure
// and cost real time to notice. --cookie takes a literal "name=value"
// argument directly.
$headerFile = tempnam(sys_get_temp_dir(), 'authheaders');
$cmd = sprintf(
    'curl -sk -D %s -o %s -w %s --cookie %s %s',
    escapeshellarg($headerFile),
    escapeshellarg($outPath),
    escapeshellarg('%{http_code}'),
    escapeshellarg($cookieName . '=' . $cookieValue),
    escapeshellarg($url)
);
$status = trim(shell_exec($cmd) ?? '');

echo "HTTP_STATUS={$status}\n";
echo "OUT={$outPath}\n";

if ((int) $status < 200 || (int) $status >= 300) {
    fwrite(STDERR, "Fetch did not return 2xx (got {$status}) — cannot treat this as a verified render.\n");
    $headers = @file_get_contents($headerFile);
    if ($headers) fwrite(STDERR, $headers . "\n");
    @unlink($headerFile);
    exit(1);
}

@unlink($headerFile);
exit(0);
