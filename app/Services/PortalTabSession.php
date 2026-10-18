<?php

namespace App\Services;

use App\Models\ClientUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * PER-TAB PORTAL SIGN-IN - .ai/specs/rental-portal-access.md §28.5.
 *
 * The web portal used to hold ONE login per browser (a session cookie), so signing a second person in on another tab signed the first one
 * out. Now every tab holds its OWN login: a short-lived Sanctum token the page keeps in that tab (sessionStorage) and sends as a bearer on
 * every portal call. Two tabs can be two different people, or one person on two sides, at the same time.
 *
 * The browser also remembers WHICH logins have signed in on it, in an encrypted HttpOnly cookie that holds only ids (never a credential and
 * never readable by the page). That is what lets a NEW tab for someone who already signed in here start signed in, without a password. The
 * cookie is only read to choose among logins that already proved themselves in this browser; it cannot sign anyone new in. Each entry is bound
 * to the login's password (a password change ends it) and has an absolute maximum age (audit 2026-10-18 A-L1).
 */
class PortalTabSession
{
    public static function tokenName(): string
    {
        return (string) config('clientauth.tab.token_name', 'Web portal (tab)');
    }

    /** A fresh tab token for this login (plain text, shown once). Expired tab tokens of this login are cleared on the way. */
    public function issue(ClientUser $login): string
    {
        $login->tokens()->where('name', self::tokenName())->where('expires_at', '<', now())->delete();

        return $login->createToken(
            self::tokenName(),
            [config('clientauth.token.ability', 'client')],
            now()->addHours(max(1, (int) config('clientauth.tab.token_hours', 12)))
        )->plainTextToken;
    }

    /**
     * @return int[] ids of the logins remembered in this browser, oldest first.
     * An entry only counts while (a) it is younger than `tab.remember_max_days` - an ABSOLUTE age, set when the person really signed in and never
     * refreshed by reuse - and (b) the login's password is still the one it was remembered under, so changing the password ends every browser's
     * memory of that login at once.
     */
    public function rememberedIds(Request $request): array
    {
        return array_map(fn (array $e) => $e['i'], $this->validEntries($request));
    }

    /** This login is now the most recent one signed in on this browser. `$freshSignIn` false = reusing an existing memory: keeps its original sign-in time. */
    public function remember(Request $request, ClientUser $login, bool $freshSignIn = true): void
    {
        $existing = $this->validEntries($request);
        $signedInAt = null;
        foreach ($existing as $e) {
            if ($e['i'] === (int) $login->id) {
                $signedInAt = $e['t'];
            }
        }
        $entries = array_values(array_filter($existing, fn (array $e) => $e['i'] !== (int) $login->id));
        $entries[] = ['i' => (int) $login->id, 't' => ($freshSignIn || $signedInAt === null) ? now()->timestamp : $signedInAt, 'v' => self::passwordVersion($login)];
        $this->write(array_slice($entries, -max(1, (int) config('clientauth.tab.remember_max', 8))));
    }

    /** Signed out: this browser no longer starts a new tab as that person. */
    public function forget(Request $request, ClientUser $login): void
    {
        $this->write(array_values(array_filter($this->validEntries($request), fn (array $e) => $e['i'] !== (int) $login->id)));
    }

    /** The password was changed or set: every tab token of this login is revoked (except `$exceptTokenId`, the one making the change). */
    public function revokeTabTokens(ClientUser $login, ?int $exceptTokenId = null): void
    {
        $q = $login->tokens()->where('name', self::tokenName());
        if ($exceptTokenId) {
            $q->where('id', '!=', $exceptTokenId);
        }
        $q->delete();
    }

    /** A short fingerprint of the login's CURRENT password hash: any change of password (a fresh salted hash) changes it. Never reversible. */
    public static function passwordVersion(ClientUser $login): string
    {
        return substr(hash_hmac('sha256', (string) $login->password, (string) config('app.key')), 0, 16);
    }

    /** @return array<int, array{i:int, t:int, v:string}> */
    private function validEntries(Request $request): array
    {
        $raw = $request->cookie((string) config('clientauth.tab.remember_cookie', 'portal_logins'));
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            return [];
        }

        $maxAge = max(1, (int) config('clientauth.tab.remember_max_days', 7)) * 86400;
        $now = now()->timestamp;
        $entries = [];
        foreach ($decoded as $e) {
            // anything not in the current shape (including the id-only cookies issued before this was bound to a password) is dropped: sign in again
            if (! is_array($e) || ! isset($e['i'], $e['t'], $e['v']) || ! is_numeric($e['i']) || ! is_numeric($e['t']) || ! is_string($e['v'])) {
                continue;
            }
            $id = (int) $e['i'];
            $t = (int) $e['t'];
            if ($id < 1 || $t > $now + 300 || ($now - $t) > $maxAge) {
                continue;
            }
            $entries[$id] = ['i' => $id, 't' => $t, 'v' => $e['v']];
        }
        if ($entries === []) {
            return [];
        }

        $versions = ClientUser::whereIn('id', array_keys($entries))->get()->mapWithKeys(fn (ClientUser $u) => [(int) $u->id => self::passwordVersion($u)]);

        return array_values(array_filter($entries, fn (array $e) => isset($versions[$e['i']]) && hash_equals((string) $versions[$e['i']], $e['v'])));
    }

    private function write(array $entries): void
    {
        $minutes = max(1, (int) config('clientauth.tab.token_hours', 12)) * 60;
        Cookie::queue(Cookie::make((string) config('clientauth.tab.remember_cookie', 'portal_logins'), json_encode(array_values($entries)), $minutes, '/', null, null, true, false, 'lax'));
    }
}
