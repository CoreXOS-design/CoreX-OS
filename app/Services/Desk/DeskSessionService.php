<?php

namespace App\Services\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\Agency;
use App\Models\DeskSession;
use App\Models\DeskSessionEvent;
use App\Models\Otp;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

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

        return $session->isLive() && (int) $session->support_user_id === (int) $user->id && $this->canSupport($user, $session);
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
    public function heartbeat(DeskSession $session, ?string $page = null): DeskSession
    {
        $this->expireStale();
        $session = $session->fresh();
        if (! $session->isOpen()) {
            return $session;
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
        $session = strlen($code) === 6 ? $this->waitingSessionForCode($code) : null;

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
        $session->forceFill([
            'status' => DeskSession::LIVE,
            'support_user_id' => $support->id,
            'joined_at' => now(),
        ])->save();
        $this->record($session, DeskSessionEvent::JOINED, ['support_name' => $support->name], $support);
        DeskBroadcast::dispatch($session->id, 'desk.joined', [
            'support_name' => $support->name,
            'joined_at' => $session->joined_at->toIso8601String(),
        ]);

        return $session->fresh();
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

    /** Hosts whose tabs the extension may share: the platform list plus this agency's own website. */
    public function allowedExtensionHosts(?int $agencyId): array
    {
        $hosts = array_map('strtolower', (array) config('desk.extension_hosts', []));
        if ($agencyId) {
            $url = Agency::withoutGlobalScopes()->whereKey($agencyId)->value('website_url');
            $host = $url ? parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = preg_replace('/^www\./', '', strtolower($host));
            }
        }

        return array_values(array_unique($hosts));
    }

    public function hostAllowed(string $host, ?int $agencyId): bool
    {
        $host = strtolower($host);
        foreach ($this->allowedExtensionHosts($agencyId) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /** A tab key: "main" (the CoreX page) or "x<chrome tab id>" for an extension tab. Anything else is treated as the CoreX page. */
    public function cleanTabKey(?string $key): string
    {
        return is_string($key) && preg_match('/^x\d{1,10}$/', $key) ? $key : 'main';
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

    /** The extension tabs open in this session right now, derived from its audit trail (last join/update/leave per tab). */
    public function openTabs(DeskSession $session): array
    {
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
                $open[$key] = ['tab' => $key, 'host' => (string) ($p['host'] ?? ''), 'title' => (string) ($p['title'] ?? '')];
            }
        }

        return $open;
    }

    /** A page title is whatever the site says - strip markup, collapse whitespace, cap the length. */
    private function cleanTitle(?string $title): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $title)));

        return mb_substr($t, 0, 120);
    }

    // ── Ending ──────────────────────────────────────────────────────────────────────

    /** End a session (idempotent). $by is one of DeskSession::ENDED_BY_*. */
    public function end(DeskSession $session, string $by, ?User $actor = null): DeskSession
    {
        $session = $session->fresh() ?? $session;
        if (! $session->isOpen()) {
            return $session;
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

    /** Close sessions nobody joined in time, and sessions whose agent tab has gone quiet. Cheap; safe to call on every read. */
    public function expireStale(): int
    {
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

        return $closed;
    }

    // ── Internals ───────────────────────────────────────────────────────────────────

    /** The waiting, unexpired session whose code this is (hash test - nothing is consumed or counted against anyone). */
    private function waitingSessionForCode(string $code, ?int $exceptId = null): ?DeskSession
    {
        $candidates = DeskSession::withoutGlobalScopes()
            ->where('status', DeskSession::WAITING)
            ->where('code_expires_at', '>', now())
            ->whereNotNull('otp_id')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
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
    private function cleanPath(?string $page): ?string
    {
        if ($page === null || trim($page) === '') {
            return null;
        }
        $path = parse_url($page, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? mb_substr($path, 0, 255) : null;
    }
}
