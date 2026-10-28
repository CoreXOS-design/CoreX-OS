<?php

namespace App\Services\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\Agency;
use App\Models\DeskSession;
use App\Models\DeskSessionEvent;
use App\Models\Otp;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Support\Desk\DeskSupporterLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * CoreX Desk - the one place a session changes state (spec .ai/specs/corex-desk.md sections 5, 7.3, 7.4).
 *
 *   start()      the agent asks for help: a `waiting` session + a 6-digit code (hashed through OtpService)
 *   join()       support types the code: the session goes `live`, the agent's browser is told over the channel
 *   heartbeat() / leaving()   the agent's tab keeps the session alive; a closed tab lets it time out
 *   end()        either side, logout, timeout, expiry - always audited, always broadcast
 *   highlight() / clearHighlight() / pointer()   support's pointing, audited (pointer MOVES are not - only on/off)
 *
 * Every change writes a desk_session_events row. The screen content never passes through here.
 */
class DeskSessionService
{
    public const OTP_PURPOSE = 'desk_join';

    public function __construct(private OtpService $otp)
    {
    }

    // ── Permission / scope ──────────────────────────────────────────────────────────

    public function agencyAllowsDesk(?int $agencyId): bool
    {
        if (! $agencyId) {
            return true;
        }

        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        return $agency ? (bool) ($agency->desk_enabled ?? true) : true;
    }

    /** May this user run support for this session? Owner roles: any agency. Everyone else: their own agency only. */
    public function canSupport(User $user, DeskSession $session): bool
    {
        if (! $user->hasPermission('desk.support')) {
            return false;
        }

        return $user->isOwnerRole() || ((int) $user->effectiveAgencyId() === (int) $session->agency_id && $session->agency_id !== null);
    }

    /** May this user open the channel? The agent always; the joined support user while it is live. Never an ended session. */
    public function canJoinChannel(User $user, DeskSession $session): bool
    {
        if (! $session->isOpen()) {
            return false;
        }
        if ((int) $session->user_id === (int) $user->id) {
            return true;
        }

        return $session->isLive() && $session->isSupporter($user) && $this->canSupport($user, $session);
    }

    // ── Agent side ──────────────────────────────────────────────────────────────────

    /**
     * Start a session for $agent. Returns [session, plain code] - the code is shown once on the agent's screen and is never stored
     * in the clear. Any earlier open session of this agent is closed first.
     *
     * @return array{0: DeskSession, 1: string}
     */
    public function start(User $agent, ?string $ip = null, ?string $userAgent = null): array
    {
        $agencyId = $agent->effectiveAgencyId();
        if (! $this->agencyAllowsDesk($agencyId)) {
            throw new DeskUnavailableException('Desk is switched off for this agency.');
        }

        $this->expireStale();
        foreach (DeskSession::withoutGlobalScopes()->open()->where('user_id', $agent->id)->get() as $old) {
            $this->end($old, DeskSession::ENDED_BY_REPLACED, $agent);
        }

        $minutes = (int) config('desk.code_minutes', 10);
        $session = DeskSession::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'user_id' => $agent->id,
            'status' => DeskSession::WAITING,
            'started_at' => now(),
            'code_expires_at' => now()->addMinutes($minutes),
            'last_heartbeat_at' => now(),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
        ]);

        // Two waiting sessions must never share a code (a join submits only the code): draw again on a clash.
        $code = $this->otp->generateCode(6);
        for ($i = 0; $i < 10 && $this->waitingSessionForCode($code, $session->id); $i++) {
            $code = $this->otp->generateCode(6);
        }

        // OtpService hashes and stores; deliver:false (nothing is mailed). The `attributes` option overrides the engine's own random
        // code_hash with the hash of the code drawn above - the only way to know the plain code when nothing is delivered.
        $otp = $this->otp->issue(self::OTP_PURPOSE, 'desk:' . $session->id, [
            'subject' => $session,
            'deliver' => false,
            'expires_minutes' => $minutes,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'attributes' => ['code_hash' => Hash::make($code)],
        ]);
        $session->forceFill(['otp_id' => $otp->id])->save();

        $this->record($session, DeskSessionEvent::CREATED, [], $agent);

        return [$session->fresh(), $code];
    }

    public function currentFor(User $agent): ?DeskSession
    {
        $this->expireStale();

        return $this->openFor($agent);
    }

    /** The agent's open session without the stale sweep - one cheap query, used on every page load by the Desk partial. */
    public function openFor(User $agent): ?DeskSession
    {
        return DeskSession::withoutGlobalScopes()->open()->where('user_id', $agent->id)->latest('id')->first();
    }

    /** The agent's tab is alive. Returns the session as it now stands (the tab learns if it was ended meanwhile). */
    public function heartbeat(DeskSession $session, mixed $page = null): DeskSession
    {
        $this->expireStale();
        $session = $session->fresh();
        if (! $session->isOpen()) {
            return $session;
        }
        if ($session->isLive() && ! $this->supportersStillValid($session)) {
            return $this->end($session, DeskSession::ENDED_BY_DISABLED);
        }

        $path = $this->cleanPath($page);
        $changed = $path !== null && $path !== $session->page_url_last;
        $session->forceFill([
            'last_heartbeat_at' => now(),
            'leaving_at' => null,
            'page_url_last' => $path ?? $session->page_url_last,
        ])->save();

        if ($changed && $session->isLive()) {
            $this->record($session, DeskSessionEvent::PAGE, ['page' => $path]);
        }
        if ($session->isLive()) {
            $this->settleTabs($session);
        }

        return $session;
    }

    /** The agent's tab is going away (page change or close). The next page has the grace period to reconnect. */
    public function leaving(DeskSession $session): void
    {
        if ($session->fresh()?->isOpen()) {
            $session->forceFill(['leaving_at' => now()])->save();
        }
    }

    // ── Support side ────────────────────────────────────────────────────────────────

    /**
     * Join by code. Returns the now-live session, or null for a wrong / expired / other-agency code (all answered alike).
     * Throws DeskRateLimitedException after too many wrong codes.
     */
    public function join(User $support, string $code): ?DeskSession
    {
        $key = 'desk-join:' . $support->id;
        $max = (int) config('desk.join_max_failures', 8);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new DeskRateLimitedException(RateLimiter::availableIn($key));
        }

        $this->expireStale();
        $code = preg_replace('/\D/', '', $code) ?? '';
        $session = strlen($code) === 6 ? $this->waitingSessionForCode($code, null, $support) : null;

        if (! $session || ! $this->canSupport($support, $session)) {
            RateLimiter::hit($key, (int) config('desk.join_window_seconds', 600));

            return null;
        }

        // Consume the code through the engine (single use, audited there).
        if (! $this->otp->verify(self::OTP_PURPOSE, 'desk:' . $session->id, $code)) {
            RateLimiter::hit($key, (int) config('desk.join_window_seconds', 600));

            return null;
        }

        RateLimiter::clear($key);
        $display = DeskSupporterLabel::for($support);   // frozen now: the audit stays true if a title changes later
        // First valid joiner wins, atomically: the claim is ONE conditional UPDATE (still `waiting`), so two support people who typed the code in the same
        // instant cannot both pass - the loser's UPDATE touches no row and is answered like a wrong / expired code.
        $joinedAt = now();
        $claimed = DeskSession::withoutGlobalScopes()->whereKey($session->id)->where('status', DeskSession::WAITING)->whereNull('support_user_id')->update([
            'status' => DeskSession::LIVE,
            'support_user_id' => $support->id,
            'support_display' => $display,
            'joined_at' => $joinedAt,
            'updated_at' => $joinedAt,
        ]);
        if ($claimed !== 1) {
            return null;
        }
        $session = $session->fresh();
        $this->record($session, DeskSessionEvent::JOINED, ['support_name' => $display], $support);
        DeskBroadcast::dispatch($session->id, 'desk.joined', [
            'support_name' => $display,
            'supporters' => [$display],
            'joined_at' => $session->joined_at->toIso8601String(),
        ]);

        return $session;
    }

    /**
     * Open a LIVE session again - the support person who joined it (after a reload or a closed window), or a second support person of the same
     * scope joining the same session. The code is not needed: it was spent at the first join. Audited as another `joined` row.
     */
    public function attach(DeskSession $session, User $support): ?DeskSession
    {
        $this->expireStale();
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || ! $this->canSupport($support, $session)) {
            return null;   // answered like "not found": nothing about another agency's session leaks
        }

        $again = $session->isSupporter($support);
        if (! $again) {
            // (a locked read-modify-write: two people attaching at once must both end up in the list)
            DB::transaction(function () use ($session, $support) {
                $row = DeskSession::withoutGlobalScopes()->lockForUpdate()->find($session->id);
                $ids = array_values(array_unique(array_merge(array_map('intval', (array) $row->support_user_ids), [(int) $support->id])));
                $row->forceFill(['support_user_ids' => $ids])->save();
            });
            $session = $session->fresh();
        }
        $display = $this->displayFor($session, $support);
        $additional = ! $again && (int) $session->support_user_id !== (int) $support->id;
        $this->record($session, DeskSessionEvent::JOINED, ['support_name' => $display, 'again' => $again, 'additional' => $additional], $support);
        // The agent is TOLD (spec 4b/14b): a support person who attaches to a live session is announced on the agent's red bar, which then lists everyone in the
        // session. The channel is private, not presence, so this server event is the only notice the agent gets - it is sent for a first attach, not for the same
        // person re-opening their own window. An added supporter has no control: that needs the agent's separate Allow, as always.
        if (! $again) {
            DeskBroadcast::dispatch($session->id, 'desk.supporter_joined', ['support_name' => $display, 'supporters' => $session->supporterNames()]);
        }

        return $session;
    }

    public function highlight(DeskSession $session, User $support, string $selector, ?string $note = null, ?string $tab = null): void
    {
        $selector = trim(mb_substr($selector, 0, (int) config('desk.selector_max', 500)));
        $note = $note !== null && trim($note) !== '' ? trim(mb_substr($note, 0, (int) config('desk.note_max', 200))) : null;
        if ($selector === '') {
            throw new \InvalidArgumentException('A highlight needs an element.');
        }

        $tab = $this->cleanTabKey($tab);
        $this->record($session, DeskSessionEvent::HIGHLIGHT, ['selector' => $selector, 'note' => $note, 'tab' => $tab], $support);
        DeskBroadcast::dispatch($session->id, 'desk.highlight', ['selector' => $selector, 'note' => $note, 'tab' => $tab]);
    }

    public function clearHighlight(DeskSession $session, User $support, ?string $tab = null): void
    {
        $tab = $this->cleanTabKey($tab);
        $this->record($session, DeskSessionEvent::HIGHLIGHT_CLEARED, ['tab' => $tab], $support);
        DeskBroadcast::dispatch($session->id, 'desk.highlight_cleared', ['tab' => $tab]);
    }

    public function pointer(DeskSession $session, User $support, bool $on, ?string $tab = null): void
    {
        $tab = $this->cleanTabKey($tab);
        $this->record($session, $on ? DeskSessionEvent::POINTER_ON : DeskSessionEvent::POINTER_OFF, ['tab' => $tab], $support);
        DeskBroadcast::dispatch($session->id, 'desk.pointer_state', ['on' => $on, 'tab' => $tab]);
    }

    // ── Extension tabs (phase 3) ────────────────────────────────────────────────────

    /** The platform sites whose tabs the extension may share (config/desk.php). Each matches itself AND its subdomains. */
    public function allowedExtensionHosts(?int $agencyId = null): array
    {
        return array_values(array_unique(array_map('strtolower', (array) config('desk.extension_hosts', []))));
    }

    /**
     * This agency's own website host (agencies.website_url), matched EXACTLY - never its subdomains or its parent domain. The site as the agency typed it
     * and its www / non-www twin (people type either), nothing else: "mail.agency.co.za" or "co.za" are not "the agency's website".
     *
     * @return array<int, string>
     */
    public function agencyExactHosts(?int $agencyId): array
    {
        if (! $agencyId) {
            return [];
        }
        $url = trim((string) Agency::withoutGlobalScopes()->whereKey($agencyId)->value('website_url'));
        $host = $url !== '' ? parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            return [];
        }
        $bare = preg_replace('/^www\./', '', strtolower($host));

        return $bare === '' ? [] : array_values(array_unique([$bare, 'www.' . $bare]));
    }

    public function hostAllowed(string $host, ?int $agencyId): bool
    {
        $host = strtolower($host);
        foreach ($this->allowedExtensionHosts($agencyId) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return in_array($host, $this->agencyExactHosts($agencyId), true);
    }

    /**
     * A tab key: "main" (the CoreX tab that started the session), "c<letters/digits>" (any other CoreX tab of the agent - spec section 4f) or
     * "x<chrome tab id>" for an extension tab. Anything else is treated as "main".
     */
    public function cleanTabKey(?string $key): string
    {
        return is_string($key) && preg_match('/^(x\d{1,10}|c[a-z0-9]{3,10})$/', $key) ? $key : 'main';
    }

    private function isCoreXTabKey(string $key): bool
    {
        return $key === 'main' || (bool) preg_match('/^c[a-z0-9]{3,10}$/', $key);
    }

    /**
     * An extension tab joined, moved to another page, or left (the agent's browser, over the token-authenticated extension API).
     * Only while the session is live; only for allowed sites (the extension is not trusted to police that). Audited as tab_join /
     * tab_leave, and tab_update when the tab moved to a different site; a mere title change is broadcast but not audited.
     *
     * @return array<string, array{tab:string, host:string, title:string}> the tabs open now, by key
     */
    public function tabEvent(DeskSession $session, User $agent, string $action, string $key, ?string $url = null, ?string $title = null): array
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || (int) $session->user_id !== (int) $agent->id) {
            throw new DeskNotLiveException('That Desk session is not live.');
        }
        if (! preg_match('/^x\d{1,10}$/', $key)) {
            throw new \InvalidArgumentException('Not an extension tab.');
        }

        $open = $this->openTabs($session);

        if ($action === 'leave') {
            if (isset($open[$key])) {
                if ($session->control_state !== DeskSession::CONTROL_NONE && $session->control_tab === $key) {
                    $this->closeControl($session, 'system', 'tab', $agent);   // the tab being controlled is gone
                    $session = $session->fresh();
                }
                $this->record($session, DeskSessionEvent::TAB_LEAVE, ['tab' => $key, 'host' => $open[$key]['host'], 'title' => $open[$key]['title']], $agent);
                DeskBroadcast::dispatch($session->id, 'desk.tab_left', ['tab' => $key]);
                unset($open[$key]);
            }

            return $open;
        }

        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        $host = is_string($host) ? mb_substr(strtolower($host), 0, 120) : '';
        if ($host === '' || ! $this->hostAllowed($host, $session->agency_id ? (int) $session->agency_id : null)) {
            throw new \InvalidArgumentException('That site cannot be shared.');
        }
        $title = $this->cleanTitle($title);
        $info = ['tab' => $key, 'host' => $host, 'title' => $title];

        if (! isset($open[$key])) {
            $this->record($session, DeskSessionEvent::TAB_JOIN, $info, $agent);
            DeskBroadcast::dispatch($session->id, 'desk.tab_joined', $info);
        } else {
            if ($open[$key]['host'] !== $host) {
                $this->record($session, DeskSessionEvent::TAB_UPDATE, $info, $agent);
            }
            if ($open[$key]['host'] !== $host || $open[$key]['title'] !== $title) {
                DeskBroadcast::dispatch($session->id, 'desk.tab_updated', $info);
            }
        }
        $open[$key] = $info;

        return $open;
    }

    // ── The agent's CoreX tabs (spec section 4f): a session belongs to the USER, so every CoreX tab they have joins it ───────────

    /**
     * A CoreX tab of the agent joined, changed page / title, or is going away (the agent's own browser, session-authenticated). Audited as
     * tab_join / tab_leave exactly like an extension tab. A leave is not acted on at once: the same tab loading its next page says "leaving" and
     * then "joined" again within a moment - settleTabs() makes it a real leave only when nobody came back for the grace.
     *
     * $at: the agent's own clock (ms) when the tab sent this. Two calls from one tab can reach the server in either order (the old page's "leaving" beacon
     * against the next page's "joined" - both fire as the new page commits), so each only records its time and a tab is left only if its latest leave is
     * newer than its latest join.
     *
     * $followedFrom: the tab whose replayed click opened this one (the agent's browser knows - it just ran that click). While control is
     * granted on that tab, control moves with it, so a button that opens a new tab does not strand support behind it (audited as control_moved).
     *
     * @return array<string, array{tab:string, host:string, title:string, path:string}> the tabs open now
     */
    public function coreXTabEvent(DeskSession $session, User $agent, string $action, string $key, ?string $path = null, ?string $title = null, ?string $followedFrom = null, ?int $at = null): array
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || (int) $session->user_id !== (int) $agent->id) {
            throw new DeskNotLiveException('That Desk session is not live.');
        }
        if (! $this->isCoreXTabKey($key)) {
            throw new \InvalidArgumentException('Not a CoreX tab.');
        }
        $this->settleTabs($session);
        $session = $session->fresh();
        $open = $this->openTabs($session);

        // A tab loading its next page sends "leaving" (the old page, as it is replaced) and "joined" (the new page, as it starts) at almost the same moment,
        // and the two can be handled in either order by two PHP workers. So neither is acted on as it comes: each only records WHEN (the tab's own clock) it
        // happened, in a key of its own, and a tab counts as left only if its latest leave is newer than its latest join (settleTabs). Any order gives the same answer.
        $stamp = $at ?? (int) round(microtime(true) * 1000);
        if ($action === 'leave') {
            Cache::put($this->leaveAtKey($session->id, $key), max($stamp, (int) Cache::get($this->leaveAtKey($session->id, $key), 0)), 600);
            Cache::put($this->leaveRecvKey($session->id, $key), now()->timestamp, 600);

            return $open;
        }

        // joined (or still here)
        Cache::put($this->joinAtKey($session->id, $key), max($stamp, (int) Cache::get($this->joinAtKey($session->id, $key), 0)), 600);
        Cache::put($this->seenKey($session->id, $key), now()->timestamp, 600);

        $info = ['tab' => $key, 'host' => 'CoreX', 'title' => $this->cleanTitle($title), 'path' => (string) ($this->cleanPath($path) ?? '')];
        $isNew = ! isset($open[$key]);
        if ($isNew) {
            $this->record($session, DeskSessionEvent::TAB_JOIN, $info, $agent);
            DeskBroadcast::dispatch($session->id, 'desk.tab_joined', $info);
        } elseif (($open[$key]['title'] ?? '') !== $info['title'] || ($open[$key]['path'] ?? '') !== $info['path']) {
            // (recorded too, so the tabs derived from the trail carry the page the tab is on now)
            $this->record($session, DeskSessionEvent::TAB_UPDATE, $info, $agent);
            DeskBroadcast::dispatch($session->id, 'desk.tab_updated', $info);
        }
        $open[$key] = $info;

        if ($isNew && $followedFrom && $followedFrom !== $key && $this->isCoreXTabKey($followedFrom)
            && $session->control_state === DeskSession::CONTROL_GRANTED && $session->control_tab === $followedFrom) {
            $session->forceFill(['control_tab' => $key, 'control_last_action_at' => now()])->save();
            $this->record($session, DeskSessionEvent::CONTROL_MOVED, $info + ['from' => $followedFrom], $agent);
            DeskBroadcast::dispatch($session->id, 'desk.control_moved', ['tab' => $key, 'from' => $followedFrom, 'idle_seconds' => $this->controlIdleMinutes($session->agency_id ? (int) $session->agency_id : null) * 60]);
        }

        return $open;
    }

    /** Every CoreX tab pings with the session heartbeat: a tab that crashed (no "leaving" ever came) is dropped after this long. */
    public function touchTab(DeskSession $session, string $key): void
    {
        if ($this->isCoreXTabKey($key)) {
            Cache::put($this->seenKey($session->id, $key), now()->timestamp, 600);
        }
    }

    /**
     * Turn the tabs that said "leaving" and did not come back within the grace, and the tabs that stopped pinging, into real leaves
     * (audited, broadcast, control ended if it was on that tab). Cheap; called on every read of the session.
     */
    public function settleTabs(DeskSession $session): void
    {
        $grace = (int) config('desk.tab_leave_grace_seconds', 3);
        // A tab under take-over gets a much longer grace: it may be the controlled tab loading its next page (a click support replayed), and that must not
        // end the take-over just because the page was slow.
        $graceControlled = (int) config('desk.tab_leave_grace_controlled_seconds', 15);
        $silent = (int) config('desk.tab_silent_seconds', 100);
        $open = $this->openTabs($session);
        $gone = [];
        foreach ($open as $key => $t) {
            if (! $this->isCoreXTabKey($key)) {
                continue;
            }
            $leftAt = (int) Cache::get($this->leaveAtKey($session->id, $key), 0);
            $joinedAt = (int) Cache::get($this->joinAtKey($session->id, $key), 0);
            $recv = Cache::get($this->leaveRecvKey($session->id, $key));
            $seen = Cache::get($this->seenKey($session->id, $key));
            $g = ($session->control_state !== DeskSession::CONTROL_NONE && $session->control_tab === $key) ? $graceControlled : $grace;
            $wentAway = $leftAt > 0 && $leftAt > $joinedAt && $recv && now()->timestamp - (int) $recv >= $g;
            if ($wentAway || ($seen && now()->timestamp - (int) $seen > $silent)) {
                $gone[] = $key;
            }
        }
        foreach ($gone as $key) {
            if ($session->control_state !== DeskSession::CONTROL_NONE && $session->control_tab === $key) {
                $this->closeControl($session, 'system', 'tab');
                $session = $session->fresh();
            }
            $this->record($session, DeskSessionEvent::TAB_LEAVE, ['tab' => $key, 'host' => 'CoreX', 'title' => $open[$key]['title'] ?? '', 'path' => $open[$key]['path'] ?? '']);
            DeskBroadcast::dispatch($session->id, 'desk.tab_left', ['tab' => $key]);
            foreach ([$this->leaveAtKey($session->id, $key), $this->leaveRecvKey($session->id, $key), $this->joinAtKey($session->id, $key), $this->seenKey($session->id, $key)] as $k) {
                Cache::forget($k);
            }
        }
    }

    private function leaveAtKey(int $sessionId, string $key): string
    {
        return 'desk:tableaveat:' . $sessionId . ':' . $key;
    }

    private function leaveRecvKey(int $sessionId, string $key): string
    {
        return 'desk:tableaverecv:' . $sessionId . ':' . $key;
    }

    private function joinAtKey(int $sessionId, string $key): string
    {
        return 'desk:tabjoinat:' . $sessionId . ':' . $key;
    }

    private function seenKey(int $sessionId, string $key): string
    {
        return 'desk:tabseen:' . $sessionId . ':' . $key;
    }

    /** The agent clicked the highlighted element (or left the page): the highlight is gone on both sides; the console says "done". */
    public function highlightDone(DeskSession $session, User $agent, ?string $tab, string $reason): void
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || (int) $session->user_id !== (int) $agent->id) {
            return;
        }
        $tab = $this->cleanTabKey($tab);
        $reason = $reason === 'navigated' ? 'navigated' : 'clicked';
        $this->record($session, DeskSessionEvent::HIGHLIGHT_CLEARED, ['tab' => $tab, 'reason' => $reason, 'by' => 'agent'], $agent);
        DeskBroadcast::dispatch($session->id, 'desk.highlight_cleared', ['tab' => $tab, 'reason' => $reason]);
    }

    /**
     * One line in the session message strip, from support to the agent or back (spec section 4h). The text travels browser to browser as a whisper for
     * speed; this call is the audit row and the safety net - it records the line and broadcasts it, and each side drops the copy it already has (by id).
     *
     * @return array{id: string, from: string, name: string, text: string, tab: string, at: int}
     */
    public function message(DeskSession $session, User $sender, string $from, string $text, ?string $tab = null, ?string $id = null): array
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive()) {
            throw new DeskNotLiveException('That Desk session is not live.');
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        $text = mb_substr($text, 0, (int) config('desk.message_max', 300));
        if ($text === '') {
            throw new \InvalidArgumentException('A message needs some words.');
        }
        $from = $from === 'agent' ? 'agent' : 'support';
        $name = $from === 'support' ? $this->displayFor($session, $sender) : (string) $sender->name;
        $id = is_string($id) && preg_match('/^[a-z0-9]{6,24}$/', $id) ? $id : strtolower(\Illuminate\Support\Str::random(10));
        $tab = $this->cleanTabKey($tab);
        $row = ['id' => $id, 'from' => $from, 'name' => $name, 'text' => $text, 'tab' => $tab];
        $this->record($session, DeskSessionEvent::MESSAGE, $row, $sender);
        $row['at'] = (int) round(microtime(true) * 1000);
        DeskBroadcast::dispatch($session->id, 'desk.message', $row);

        return $row;
    }

    /** The last few lines of the message strip, oldest first - what a console or a tab that has just joined shows as the thread so far. */
    public function recentMessages(DeskSession $session, int $limit = 10): array
    {
        return DeskSessionEvent::query()->where('desk_session_id', $session->id)->where('type', DeskSessionEvent::MESSAGE)
            ->orderByDesc('id')->limit($limit)->get(['payload', 'created_at'])->reverse()
            ->map(fn ($e) => (array) $e->payload + ['at' => (int) $e->created_at->getTimestamp() * 1000])->values()->all();
    }

    /** Timing overlay only (config desk.timing_overlay): a server-stamped event so the console can show the server-to-console leg. */
    public function timingProbe(DeskSession $session): int
    {
        $ms = (int) round(microtime(true) * 1000);
        DeskBroadcast::dispatch($session->id, 'desk.timing', ['sent_ms' => $ms]);

        return $ms;
    }

    /** The extension tabs open in this session right now, derived from its audit trail (last join/update/leave per tab). */
    public function openTabs(DeskSession $session): array
    {
        // One read per request unless the trail changed meanwhile: the answer is keyed by the id of the newest tab row, found by one cheap indexed query -
        // the heavy part (loading and folding every tab row) runs once for many callers in the same request (tabInfo, settleTabs, the list).
        $latest = (int) DeskSessionEvent::query()->where('desk_session_id', $session->id)
            ->whereIn('type', [DeskSessionEvent::TAB_JOIN, DeskSessionEvent::TAB_UPDATE, DeskSessionEvent::TAB_LEAVE])->max('id');
        if (isset($this->openTabsMemo[$session->id]) && $this->openTabsMemo[$session->id][0] === $latest) {
            return $this->openTabsMemo[$session->id][1];
        }
        $open = [];
        $events = DeskSessionEvent::query()->where('desk_session_id', $session->id)
            ->whereIn('type', [DeskSessionEvent::TAB_JOIN, DeskSessionEvent::TAB_UPDATE, DeskSessionEvent::TAB_LEAVE])
            ->orderBy('id')->get(['type', 'payload']);
        foreach ($events as $e) {
            $p = (array) $e->payload;
            $key = (string) ($p['tab'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($e->type === DeskSessionEvent::TAB_LEAVE) {
                unset($open[$key]);
            } else {
                $open[$key] = ['tab' => $key, 'host' => (string) ($p['host'] ?? ''), 'title' => (string) ($p['title'] ?? ''), 'path' => (string) ($p['path'] ?? '')];
            }
        }
        $this->openTabsMemo[$session->id] = [$latest, $open];

        return $open;
    }

    /** @var array<int, array{0:int, 1:array}> openTabs() memo for this request: session id => [newest tab row id, tabs] */
    private array $openTabsMemo = [];

    /** A page title is whatever the site says - strip markup, collapse whitespace, cap the length. */
    private function cleanTitle(?string $title): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $title)));

        return mb_substr($t, 0, 120);
    }

    // ── Take-over (phase 2): support asks, the agent allows or declines, the agent's own browser replays the actions ──────

    /** The agency's idle limit for an unused take-over, in minutes (1-60; default 5). */
    public function controlIdleMinutes(?int $agencyId): int
    {
        $min = (int) config('desk.control_idle_minutes_min', 1);
        $max = (int) config('desk.control_idle_minutes_max', 60);
        $v = $agencyId ? Agency::withoutGlobalScopes()->whereKey($agencyId)->value('desk_control_idle_minutes') : null;

        return max($min, min($max, (int) ($v ?: config('desk.control_idle_minutes_default', 5))));
    }

    /** host + title of a tab for the audit trail ("main" is the CoreX page itself). */
    private function tabInfo(DeskSession $session, string $tab): array
    {
        $open = $this->openTabs($session);
        if ($tab === 'main') {
            return ['tab' => 'main', 'host' => 'CoreX', 'title' => $open['main']['title'] ?? 'CoreX page', 'path' => $open['main']['path'] ?? ''];
        }

        $info = $open[$tab] ?? ['tab' => $tab, 'host' => '', 'title' => ''];
        if (($info['path'] ?? '') === '') {
            unset($info['path']);   // (only a CoreX tab has a page path)
        }

        return $info;
    }

    /**
     * Support asks for control of one tab. Only the support user who joined, only while live, only one control at a time, and an
     * extension tab must still be shared. Nothing moves until the agent allows.
     */
    public function requestControl(DeskSession $session, User $support, string $tab): DeskSession
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || ! $session->isSupporter($support)) {
            throw new DeskControlException('You are not in a live session.', 409);
        }
        if (! $support->hasPermission('desk.control')) {
            throw new DeskControlException('You do not have permission to take control.', 403);
        }
        if (($session->control_state ?: DeskSession::CONTROL_NONE) !== DeskSession::CONTROL_NONE) {
            throw new DeskControlException('Control has already been asked for or is in use.', 409);
        }
        $tab = $this->cleanTabKey($tab);
        if ($tab !== 'main' && ! isset($this->openTabs($session)[$tab])) {
            throw new DeskControlException('That tab is no longer shared.', 409);
        }

        // Each request has its own random id: the agent's Allow / Decline must quote it, so an answer can only ever be for the question that was shown.
        $requestId = strtolower(Str::random(24));
        $session->forceFill([
            'control_state' => DeskSession::CONTROL_REQUESTED, 'control_tab' => $tab, 'control_support_user_id' => $support->id, 'control_request_id' => $requestId,
            'control_requested_at' => now(), 'control_granted_at' => null, 'control_last_action_at' => null,
        ])->save();
        $this->record($session, DeskSessionEvent::CONTROL_REQUESTED, $this->tabInfo($session, $tab), $support);
        DeskBroadcast::dispatch($session->id, 'desk.control_requested', ['tab' => $tab, 'support_name' => $this->displayFor($session, $support), 'request_id' => $requestId]);

        return $session->fresh();
    }

    /**
     * The agent's answer to a request (Allow / Decline). Only the agent; only while a request is open; only for the request that was shown
     * ($requestId - a mismatch is a 409, null is for the server's own "withdraw" path); and never for a request older than desk.control_request_seconds
     * (the request lapses first and the answer is refused).
     */
    public function respondControl(DeskSession $session, User $agent, bool $allow, ?string $requestId = null, bool $checkRequestId = true): DeskSession
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || (int) $session->user_id !== (int) $agent->id) {
            throw new DeskControlException('That Desk session is not live.', 409);
        }
        if ($session->control_state !== DeskSession::CONTROL_REQUESTED) {
            throw new DeskControlException('Nobody is asking for control.', 409);
        }
        if ($checkRequestId && (! is_string($requestId) || $requestId === '' || ! $session->control_request_id || ! hash_equals((string) $session->control_request_id, $requestId))) {
            throw new DeskControlException('That request is not the one currently open.', 409);
        }
        if ($session->control_requested_at && $session->control_requested_at->lt(now()->subSeconds((int) config('desk.control_request_seconds', 60)))) {
            $this->closeControl($session, 'system', 'no_answer');   // the clock ran out before the answer got here
            throw new DeskControlException('That request has lapsed. Ask support to request control again.', 409);
        }
        $tab = (string) $session->control_tab;
        $info = $this->tabInfo($session, $tab);

        if ($allow) {
            $session->forceFill(['control_state' => DeskSession::CONTROL_GRANTED, 'control_granted_at' => now(), 'control_last_action_at' => now(), 'control_ended_at' => null])->save();
            $this->record($session, DeskSessionEvent::CONTROL_GRANTED, $info, $agent);
            DeskBroadcast::dispatch($session->id, 'desk.control_granted', ['tab' => $tab, 'idle_seconds' => $this->controlIdleMinutes($session->agency_id ? (int) $session->agency_id : null) * 60]);
        } else {
            $session->forceFill(['control_state' => DeskSession::CONTROL_NONE, 'control_tab' => null, 'control_requested_at' => null])->save();
            $this->record($session, DeskSessionEvent::CONTROL_DECLINED, $info + ['reason' => 'declined'], $agent);
            DeskBroadcast::dispatch($session->id, 'desk.control_declined', ['tab' => $tab, 'reason' => 'declined']);
        }

        return $session->fresh();
    }

    /**
     * The agent takes control back at any time ("agent"), or the agent's own browser reports its idle clock ran out ("idle").
     * A request still waiting is simply declined.
     */
    public function revokeControl(DeskSession $session, User $agent, string $reason = 'agent'): DeskSession
    {
        $session = $session->fresh();
        if (! $session || (int) $session->user_id !== (int) $agent->id) {
            throw new DeskControlException('That Desk session is not yours.', 403);
        }
        $state = $session->control_state ?: DeskSession::CONTROL_NONE;
        if ($state === DeskSession::CONTROL_REQUESTED) {
            return $this->respondControl($session, $agent, false, null, false);
        }
        if ($state === DeskSession::CONTROL_GRANTED) {
            $this->closeControl($session, $reason === 'idle' ? 'system' : 'agent', $reason === 'idle' ? 'idle' : 'agent', $agent);
        }

        return $session->fresh();
    }

    /**
     * The signing key of the take-over that is granted right now: HMAC-SHA256(app key, "desk-grant|session|support user|request id|granted at"). Nothing is
     * stored - it is derived each time it is asked for, so the moment control ends (which clears those fields) the key cannot be derived any more.
     * Only two callers get it: the AGENT (the Allow answer, or the agent's own "key" call when a page change / a tab move needs it again) and the support
     * person who holds this control. The console signs every action with it; the agent's browser verifies before anything is replayed.
     */
    public function grantKey(DeskSession $session): ?string
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || $session->control_state !== DeskSession::CONTROL_GRANTED
            || ! $session->control_request_id || ! $session->control_support_user_id || ! $session->control_granted_at) {
            return null;
        }

        return hash_hmac('sha256', implode('|', ['desk-grant', $session->id, $session->control_support_user_id, $session->control_request_id, $session->control_granted_at->getTimestamp()]), (string) config('app.key'));
    }

    /** The grant as the AGENT's browser gets it: the key plus the request it belongs to. Null unless granted (and, if $tab is given, granted for that tab). */
    public function agentGrant(DeskSession $session, User $agent, ?string $tab = null): ?array
    {
        $session = $session->fresh();
        if (! $session || (int) $session->user_id !== (int) $agent->id) {
            return null;
        }
        if ($tab !== null && $session->control_tab !== $this->cleanTabKey($tab)) {
            return null;
        }
        $key = $this->grantKey($session);

        return $key ? ['key' => $key, 'request_id' => $session->control_request_id, 'tab' => $session->control_tab] : null;
    }

    /** The grant as the SUPPORT person gets it: only the user who asked for this control (control_support_user_id), only while it is granted. */
    public function supportGrant(DeskSession $session, User $support): ?array
    {
        $session = $session->fresh();
        if (! $session || ! $session->isLive() || ! $session->isSupporter($support) || ! $support->hasPermission('desk.control') || ! $this->canSupport($support, $session)
            || (int) $session->control_support_user_id !== (int) $support->id) {
            return null;
        }
        $key = $this->grantKey($session);

        return $key ? ['key' => $key, 'request_id' => $session->control_request_id, 'tab' => $session->control_tab] : null;
    }

    /** Support hands control back (or cancels a request nobody has answered yet). */
    public function endControlBySupport(DeskSession $session, User $support): DeskSession
    {
        $session = $session->fresh();
        if (! $session || ! $session->isSupporter($support)) {
            throw new DeskControlException('You are not in that session.', 409);
        }
        if (($session->control_state ?: DeskSession::CONTROL_NONE) !== DeskSession::CONTROL_NONE) {
            $this->closeControl($session, 'support', 'support', $support);
        }

        return $session->fresh();
    }

    /**
     * What the agent's own browser replayed for support (clicks, typing, keys, scrolling, choices). Reported by the AGENT'S browser - the
     * record of what actually ran, including what it refused - and only while control is granted for that tab. Typed text is never in
     * it: just the action, the element (selector + a short label) and whether it ran. Consecutive keystrokes arrive pre-counted (n).
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    public function recordControlActions(DeskSession $session, User $agent, string $tab, array $actions): int
    {
        $session = $session->fresh();
        $tab = $this->cleanTabKey($tab);
        $late = false;
        if (! $session || (int) $session->user_id !== (int) $agent->id) {
            throw new DeskControlException('Nobody has control of that tab.', 409);
        }
        if ($session->isLive() && $session->control_state === DeskSession::CONTROL_GRANTED && $session->control_tab === $tab) {
            $controlTab = $tab;
            $supportId = $session->control_support_user_id ?: $session->support_user_id;
        } elseif ($session->control_ended_at && $session->control_ended_tab === $tab
            && $session->control_ended_at->gte(now()->subSeconds((int) config('desk.control_report_grace_seconds', 10)))) {
            // The agent's browser flushes its last batch a moment AFTER control ended (the report is batched ~1.5 s): accept it inside a short grace window for the
            // same session and tab, and mark the rows late. A late report never keeps anything alive (no idle-clock touch).
            $late = true;
            $controlTab = $tab;
            $supportId = $session->control_ended_support_user_id ?: $session->support_user_id;
        } else {
            throw new DeskControlException('Nobody has control of that tab.', 409);
        }

        $info = $this->tabInfo($session, $controlTab);
        $support = $supportId ? User::withoutGlobalScopes()->find($supportId) : null;
        $kinds = ['click', 'dblclick', 'type', 'key', 'scroll', 'select', 'focus'];
        $max = (int) config('desk.control_actions_per_report', 50);
        $written = 0;
        $anyRan = false;

        foreach (array_slice($actions, 0, $max) as $a) {
            $kind = is_array($a) && is_scalar($a['type'] ?? null) ? (string) $a['type'] : '';
            if (! in_array($kind, $kinds, true)) {
                continue;
            }
            $ok = (bool) ($a['ok'] ?? false);
            $anyRan = $anyRan || $ok;
            // (an element selector / label / reason is text - a client that sends an array or an object in their place must not crash the call)
            $selector = is_scalar($a['selector'] ?? null) ? (string) $a['selector'] : '';
            $labelText = is_scalar($a['label'] ?? null) ? (string) $a['label'] : '';
            $reasonText = is_scalar($a['reason'] ?? null) ? (string) $a['reason'] : 'refused';
            $this->record($session, DeskSessionEvent::CONTROL_ACTION, [
                'tab' => $info['tab'], 'host' => $info['host'], 'title' => $info['title'],
                'action' => $kind,
                'selector' => mb_substr($selector, 0, (int) config('desk.selector_max', 500)),
                'label' => $this->cleanTitle(mb_substr($labelText, 0, 80)),
                'ok' => $ok,
                'reason' => $ok ? null : mb_substr((string) preg_replace('/[^a-z_ ]/i', '', $reasonText), 0, 40),
                'n' => max(1, min(1000, (int) (is_numeric($a['n'] ?? null) ? $a['n'] : 1))),
            ] + ($late ? ['late' => true] : []), $support);
            $written++;
        }
        if ($anyRan && ! $late) {
            $session->forceFill(['control_last_action_at' => now()])->save();   // the idle clock
        }

        return $written;
    }

    /**
     * How this support person is shown for this session: the string frozen at join time (the first supporter on the session, or what they were
     * on their own `joined` row), else computed now for someone who has not joined yet.
     */
    public function displayFor(DeskSession $session, User $support): string
    {
        if ((int) $session->support_user_id === (int) $support->id && $session->support_display) {
            return $session->support_display;
        }
        $row = DeskSessionEvent::query()->where('desk_session_id', $session->id)->where('type', DeskSessionEvent::JOINED)->where('actor_user_id', $support->id)
            ->orderByDesc('id')->first(['payload']);
        $stored = $row ? ($row->payload['support_name'] ?? null) : null;

        return $stored ?: DeskSupporterLabel::for($support);
    }

    /** One place that ends a request or a control: records control_ended / control_declined, clears the state, tells both sides. */
    private function closeControl(DeskSession $session, string $by, string $reason, ?User $actor = null): void
    {
        $state = $session->control_state ?: DeskSession::CONTROL_NONE;
        if ($state === DeskSession::CONTROL_NONE) {
            return;
        }
        $tab = (string) $session->control_tab;
        $info = $this->tabInfo($session, $tab);
        $was = ['tab' => $tab, 'support' => $session->control_support_user_id];
        $session->forceFill([
            'control_state' => DeskSession::CONTROL_NONE, 'control_tab' => null, 'control_requested_at' => null, 'control_granted_at' => null, 'control_last_action_at' => null,
            'control_support_user_id' => null, 'control_request_id' => null,
            // remembered for the report grace window (recordControlActions): only a control that was really granted can have a last batch still on its way
            'control_ended_at' => $state === DeskSession::CONTROL_GRANTED ? now() : $session->control_ended_at,
            'control_ended_tab' => $state === DeskSession::CONTROL_GRANTED ? $was['tab'] : $session->control_ended_tab,
            'control_ended_support_user_id' => $state === DeskSession::CONTROL_GRANTED ? $was['support'] : $session->control_ended_support_user_id,
        ])->save();

        if ($state === DeskSession::CONTROL_REQUESTED) {
            $this->record($session, DeskSessionEvent::CONTROL_DECLINED, $info + ['reason' => $reason], $actor);
            DeskBroadcast::dispatch($session->id, 'desk.control_declined', ['tab' => $tab, 'reason' => $reason]);

            return;
        }
        if ($by === 'agent') {
            $this->record($session, DeskSessionEvent::CONTROL_REVOKED, $info, $actor);
            DeskBroadcast::dispatch($session->id, 'desk.control_revoked', ['tab' => $tab]);

            return;
        }
        $this->record($session, DeskSessionEvent::CONTROL_ENDED, $info + ['by' => $by, 'reason' => $reason], $actor);
        DeskBroadcast::dispatch($session->id, 'desk.control_ended', ['tab' => $tab, 'by' => $by, 'reason' => $reason]);
    }

    // ── Ending ──────────────────────────────────────────────────────────────────────

    /** End a session (idempotent). $by is one of DeskSession::ENDED_BY_*. */
    public function end(DeskSession $session, string $by, ?User $actor = null): DeskSession
    {
        $session = $session->fresh() ?? $session;
        if (! $session->isOpen()) {
            return $session;
        }

        // Ending the session always ends control (spec section 14) - recorded before the session's own end.
        if (($session->control_state ?: DeskSession::CONTROL_NONE) !== DeskSession::CONTROL_NONE) {
            $this->closeControl($session, 'system', 'session', $actor);
            $session = $session->fresh();
        }

        $session->forceFill([
            'status' => DeskSession::ENDED,
            'ended_at' => now(),
            'ended_by' => $by,
            'ended_by_user_id' => $actor?->id,
        ])->save();

        // A code that was never used must not stay valid.
        if ($session->otp_id) {
            Otp::query()->whereKey($session->otp_id)->whereNull('used_at')->update(['used_at' => now()]);
        }

        $this->record($session, DeskSessionEvent::ENDED, ['by' => $by], $actor);
        DeskBroadcast::dispatch($session->id, 'desk.ended', ['by' => $by]);

        return $session->fresh();
    }

    /** Close every open session of a user (logout). */
    public function endAllFor(User $user, string $by): void
    {
        foreach (DeskSession::withoutGlobalScopes()->open()->where('user_id', $user->id)->get() as $session) {
            $this->end($session, $by, $user);
        }
    }

    /**
     * Close sessions nobody joined in time, sessions whose agent tab has gone quiet, sessions past the hard cap, and sessions with a support person who is
     * no longer allowed to be there. Safe to call on every read: a read-path call is throttled to once per desk.expire_throttle_seconds (a cache lock, so
     * a heartbeat or a poll does not run the sweep's table scans each time); the `desk:expire` schedule and anything that passes $force always sweeps.
     */
    public function expireStale(bool $force = false): int
    {
        $every = (int) config('desk.expire_throttle_seconds', 5);
        if (! $force && $every > 0 && ! Cache::add('desk:expire-sweep', 1, $every)) {
            return 0;
        }
        $closed = 0;
        $staleBefore = now()->subSeconds((int) config('desk.stale_after_seconds', 75));
        $leavingBefore = now()->subSeconds((int) config('desk.leaving_grace_seconds', 30));

        $expired = DeskSession::withoutGlobalScopes()->where('status', DeskSession::WAITING)->where('code_expires_at', '<', now())->get();
        foreach ($expired as $session) {
            $this->end($session, DeskSession::ENDED_BY_EXPIRED);
            $closed++;
        }

        $quiet = DeskSession::withoutGlobalScopes()->open()
            ->where(function ($q) use ($staleBefore, $leavingBefore) {
                $q->where('last_heartbeat_at', '<', $staleBefore)
                    ->orWhere('leaving_at', '<', $leavingBefore);
            })->get();
        foreach ($quiet as $session) {
            $this->end($session, DeskSession::ENDED_BY_TIMEOUT);
            $closed++;
        }

        // Hard cap: however alive it looks, a session does not run for ever (desk.max_session_hours; 0 switches the cap off).
        $maxHours = (int) config('desk.max_session_hours', 8);
        if ($maxHours > 0) {
            $tooLong = DeskSession::withoutGlobalScopes()->open()->where('started_at', '<', now()->subHours($maxHours))->get();
            foreach ($tooLong as $session) {
                $this->end($session, DeskSession::ENDED_BY_TIMEOUT);
                $closed++;
            }
        }

        // A support person whose desk.support permission / account is gone is not left watching (see supportersStillValid).
        foreach (DeskSession::withoutGlobalScopes()->where('status', DeskSession::LIVE)->whereNotNull('support_user_id')->get() as $session) {
            if (! $this->supportersStillValid($session)) {
                $this->end($session, DeskSession::ENDED_BY_DISABLED);
                $closed++;
            }
        }

        // Take-over: a request nobody answered lapses; a granted control nobody uses ends (the agency's idle limit).
        $pending = DeskSession::withoutGlobalScopes()->open()->where('control_state', '!=', DeskSession::CONTROL_NONE)->get();
        foreach ($pending as $session) {
            if ($session->control_state === DeskSession::CONTROL_REQUESTED
                && $session->control_requested_at && $session->control_requested_at->lt(now()->subSeconds((int) config('desk.control_request_seconds', 60)))) {
                $this->closeControl($session, 'system', 'no_answer');
                $closed++;
            } elseif ($session->control_state === DeskSession::CONTROL_GRANTED) {
                $last = $session->control_last_action_at ?? $session->control_granted_at;
                if ($last && $last->lt(now()->subMinutes($this->controlIdleMinutes($session->agency_id ? (int) $session->agency_id : null)))) {
                    $this->closeControl($session, 'system', 'idle');
                    $closed++;
                }
            }
        }

        return $closed;
    }

    // ── Internals ───────────────────────────────────────────────────────────────────

    /**
     * Is every support person on this live session still allowed to be there (active account, desk.support, still in scope)? A person whose permission
     * or account was taken away mid-session cannot be removed from a private channel they are already subscribed to, so the session ends instead (heartbeat
     * and the expiry sweep call this) - the agent is never left with someone watching who should not be.
     */
    public function supportersStillValid(DeskSession $session): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge([(int) $session->support_user_id], (array) $session->support_user_ids)))));
        if (! $ids) {
            return true;
        }
        $users = User::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $u = $users->get($id);
            if (! $u || ! $u->is_active || ! $this->canSupport($u, $session)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The waiting, unexpired session whose code this is (hash test - nothing is consumed or counted against anyone). A joining support person can only ever
     * use a session they could support, so the candidates are narrowed to their scope (their own agency; every agency for an owner role) and capped, instead of
     * bcrypt-testing every waiting session of every agency on each attempt.
     */
    private function waitingSessionForCode(string $code, ?int $exceptId = null, ?User $joiner = null): ?DeskSession
    {
        $candidates = DeskSession::withoutGlobalScopes()
            ->where('status', DeskSession::WAITING)
            ->where('code_expires_at', '>', now())
            ->whereNotNull('otp_id')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($joiner && ! $joiner->isOwnerRole(), fn ($q) => $q->where('agency_id', $joiner->effectiveAgencyId() ?: 0))
            ->orderByDesc('id')
            ->limit((int) config('desk.join_candidate_cap', 200))
            ->get();

        foreach ($candidates as $session) {
            $otp = Otp::query()->whereKey($session->otp_id)->whereNull('used_at')->first();
            if ($otp && $otp->expires_at->isFuture() && Hash::check($code, $otp->code_hash)) {
                return $session;
            }
        }

        return null;
    }

    private function record(DeskSession $session, string $type, array $payload, ?User $actor = null): DeskSessionEvent
    {
        return DeskSessionEvent::create([
            'desk_session_id' => $session->id,
            'agency_id' => $session->agency_id,
            'type' => $type,
            'payload' => $payload ?: null,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /** Path only - a query string can carry anything (tokens, search text), so it is never kept. */
    public function cleanPath(mixed $page): ?string
    {
        if (! is_string($page) || trim($page) === '') {
            return null;
        }
        $path = parse_url($page, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? mb_substr($path, 0, 255) : null;
    }
}
