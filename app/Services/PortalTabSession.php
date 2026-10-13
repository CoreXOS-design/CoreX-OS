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
 * cookie is only read to choose among logins that already proved themselves in this browser; it cannot sign anyone new in.
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

    /** @return int[] ids of the logins remembered in this browser, oldest first */
    public function rememberedIds(Request $request): array
    {
        $raw = $request->cookie((string) config('clientauth.tab.remember_cookie', 'portal_logins'));
        $ids = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0))) : [];
    }

    /** This login is now the most recent one signed in on this browser. */
    public function remember(Request $request, ClientUser $login): void
    {
        $ids = array_values(array_diff($this->rememberedIds($request), [(int) $login->id]));
        $ids[] = (int) $login->id;
        $this->write(array_slice($ids, -max(1, (int) config('clientauth.tab.remember_max', 8))));
    }

    /** Signed out: this browser no longer starts a new tab as that person. */
    public function forget(Request $request, ClientUser $login): void
    {
        $this->write(array_values(array_diff($this->rememberedIds($request), [(int) $login->id])));
    }

    private function write(array $ids): void
    {
        $minutes = max(1, (int) config('clientauth.tab.token_hours', 12)) * 60;
        Cookie::queue(Cookie::make((string) config('clientauth.tab.remember_cookie', 'portal_logins'), json_encode(array_values($ids)), $minutes, '/', null, null, true, false, 'lax'));
    }
}
