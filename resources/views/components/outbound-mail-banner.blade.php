{{--
    AT-URGENT-2026-09-09 (Johan, via conductor) — supersedes the old,
    per-screen mail-guard-banner (4 screens only). Included at the global
    layout level (layouts/corex.blade.php, layouts/corex-app.blade.php),
    right alongside partials._env-banner, so EVERY authenticated user sees
    it on EVERY page — deliberately NOT gated on isOwnerRole() at all: "An
    agent sending an e-sign invitation on live while interception is on has
    to know their message is being held, and they are not a super admin...
    Getting this backwards — toggle visible to all, banner visible to few —
    would be the worst possible outcome." The toggle itself is the ONLY
    thing gated to super admins; this banner is for everyone.

    Structural shape deliberately matches _env-banner (full-width bar, flex
    child, no card margins) rather than the old floating rounded-card
    banner — it lives in the same fixed chrome, not inside page content.

    Four states, driven by two facts, not four separate guesses:
      natural    = would THIS environment intercept with no override?
      effective  = is it intercepting RIGHT NOW (OutboundMailGuard::isActive())?
      overridden = natural !== effective (a super admin has moved it away
                   from what this environment would do on its own).

      A. effective=true,  overridden=false — plain "test site" strip.
      B. effective=true,  overridden=true  — LOUD: mail is being HELD
         somewhere that normally sends for real (Staging once armed, or
         production) — an incident is in progress.
      C. effective=false, overridden=false — sends for real, normally.
         Staging gets an explicit "yes this is real" strip (must not look
         safe when it isn't). Production gets nothing — its normal state.
      D. effective=false, overridden=true  — LOUD: real mail is LEAVING an
         environment that normally intercepts (QA1 or Staging-before-arming).
--}}
@php
    $natural = ! \App\Support\OutboundMailGuard::isSendingConfirmed();
    $effective = \App\Support\OutboundMailGuard::isActive();
    $overridden = \App\Support\OutboundMailGuard::isOverridden();
    $host = parse_url(config('app.url'), PHP_URL_HOST);

    $lastChange = $overridden ? \App\Support\OutboundMailGuard::lastToggleChange() : null;
    $sinceLabel = null;
    if ($lastChange) {
        $mins = max(0, $lastChange->created_at->diffInMinutes(now()));
        $d = intdiv($mins, 1440);
        $h = intdiv($mins % 1440, 60);
        $m = $mins % 60;
        $sinceLabel = ($d > 0 ? "{$d}d " : '') . ($h > 0 || $d > 0 ? "{$h}h " : '') . "{$m}m";
    }
    $heldCount = $effective && $overridden ? \App\Support\OutboundMailGuard::heldCount() : null;

    $barStyle = 'flex:0 0 auto; width:100%; line-height:1.4; font-size:12px; font-weight:700;'
        . 'letter-spacing:.02em; text-align:center; padding:6px 12px;'
        . 'border-bottom:1px solid var(--border, rgba(0,0,0,0.14));';
@endphp

@if($effective && ! $overridden)
    {{-- A — plain, non-alarming. Wording already verified correct tonight. --}}
    <div role="status" aria-label="Outbound mail: intercepted" style="{{ $barStyle }} background:#fef2f2; color:#7f1d1d;">
        &#9888;&#65039; This is a test site — no real message leaves this environment ({{ config('app.env') }} &middot; {{ $host }}).
        Messages are caught and land in Mailpit; Test Connection's Sent-folder check is caught too.
    </div>
@elseif($effective && $overridden)
    {{-- B — LOUD. Mail is being held somewhere that normally sends for real. --}}
    <div role="status" aria-label="Outbound mail: HELD" style="{{ $barStyle }} background:#7f1d1d; color:#fff; font-size:13px;">
        &#128721; OUTBOUND MAIL IS BEING HELD, NOT SENT — a super admin turned interception ON for this environment
        ({{ config('app.env') }} &middot; {{ $host }}), which normally sends real mail.
        @if($sinceLabel)
            On for {{ $sinceLabel }}
            @if($heldCount !== null)
                &middot; {{ $heldCount }} message{{ $heldCount === 1 ? '' : 's' }} held
            @endif
            .
            @if($lastChange?->user)
                Turned on by {{ $lastChange->user->name }}.
            @endif
            @if($lastChange?->reason)
                Reason: &ldquo;{{ $lastChange->reason }}&rdquo;
            @endif
        @endif
    </div>
@elseif(! $effective && ! $overridden && config('app.env') === 'staging')
    {{-- C (Staging only) — this looks like a test site but is not, for mail. --}}
    <div role="status" aria-label="Outbound mail: sending for real" style="{{ $barStyle }} background:#fffbeb; color:#78350f;">
        &#9888;&#65039; This is Staging — mail sent from here IS real and reaches real inboxes, including real
        client, agent, and staff addresses ({{ $host }}). There is no interception. Treat every send as real.
    </div>
@elseif(! $effective && $overridden)
    {{-- D — LOUD. Real mail is leaving an environment that normally intercepts. --}}
    <div role="status" aria-label="Outbound mail: SENDING FOR REAL" style="{{ $barStyle }} background:#7f1d1d; color:#fff; font-size:13px;">
        &#128680; OUTBOUND MAIL INTERCEPTION IS OFF, MAIL IS SENDING FOR REAL — a super admin forced this
        environment ({{ config('app.env') }} &middot; {{ $host }}), which normally catches every message, to send
        real mail instead — including Test Connection.
        @if($sinceLabel)
            On for {{ $sinceLabel }}.
            @if($lastChange?->user)
                Turned on by {{ $lastChange->user->name }}.
            @endif
            @if($lastChange?->reason)
                Reason: &ldquo;{{ $lastChange->reason }}&rdquo;
            @endif
        @endif
    </div>
@endif
