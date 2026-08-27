// E-sign regression harness — session cookie minting.
//
// Shells out to `php artisan tinker` to mint a real, server-side session for
// a fixed test agent (user id 22, Johan — the same account every manual
// e-sign verification pass this week used to click through the real screens).
// Read-only against the product: this does not create a user, it logs an
// EXISTING one in via the same session mechanism a browser login produces.

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const COOKIE_FILE = path.join(__dirname, '..', '.session_cookie.txt');
const COOKIE_NAME_FILE = path.join(__dirname, '..', '.session_cookie_name.txt');

function mintSessionCookie(repoRoot, userId = 22) {
    const script = `
$user = \\App\\Models\\User::find(${userId});
$store = app('session')->driver();
$store->start();
auth()->guard('web')->login($user);
$store->put('login_web_' . sha1(\\Illuminate\\Auth\\SessionGuard::class), $user->getAuthIdentifier());
$store->save();
$sessionId = $store->getId();
$cookieName = config('session.cookie');
$encrypter = app('encrypter');
$prefixed = \\Illuminate\\Cookie\\CookieValuePrefix::create($cookieName, $encrypter->getKey()) . $sessionId;
$encrypted = $encrypter->encrypt($prefixed, false);
file_put_contents('${COOKIE_FILE}', $encrypted);
file_put_contents('${COOKIE_NAME_FILE}', $cookieName);
echo "minted:" . $cookieName . PHP_EOL;
`.trim();

    const tmpFile = path.join(__dirname, '..', '.mint_tmp.php');
    fs.writeFileSync(tmpFile, `<?php\n${script}\n`);
    try {
        const out = execSync(`php artisan tinker --execute="require '${tmpFile}';"`, {
            cwd: repoRoot, encoding: 'utf8', timeout: 30000,
        });
        if (!out.includes('minted:')) {
            throw new Error('Cookie mint did not report success: ' + out);
        }
    } finally {
        fs.unlinkSync(tmpFile);
    }

    return {
        name: fs.readFileSync(COOKIE_NAME_FILE, 'utf8').trim(),
        value: fs.readFileSync(COOKIE_FILE, 'utf8').trim(),
    };
}

module.exports = { mintSessionCookie };
