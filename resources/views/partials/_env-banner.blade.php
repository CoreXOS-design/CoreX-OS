{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    Environment banner. Source of truth = config('app.env_label')
    (env APP_ENV_LABEL) — NOT APP_ENV (demo, staging AND live all run
    APP_ENV=production). Empty/unset => renders absolutely NOTHING (no
    element, no flex item, no layout shift). This is exactly how LIVE
    behaves — clean for clients.

    Colour rules (F.7): only existing, non-agency-branded design tokens
    via the var(--token, #fallback) pattern.
      - STAGING : --ds-amber  bg  +  --ds-navy text  (dark-on-amber,
                  theme-independent — both tokens are single-value)
      - DEMO    : --ds-navy   bg  +  white text       (the DS "Info"
                  token; theme-independent; high contrast both modes)
      - LOCAL   : --surface-2 bg  +  --text-primary    (neutral, the
                  theme-PAIRED tokens flip together so it stays readable
                  in light AND dark — avoids the white-on-white trap)
--}}
@php
    $envLabel = strtoupper(trim((string) config('app.env_label', '')));
@endphp
@if ($envLabel !== '')
    @php
        $host = request()->getHost();
        $themes = [
            'DEMO' => [
                'bg'   => 'var(--ds-navy, #0b2a4a)',
                'fg'   => '#ffffff',
                'text' => 'DEMO ENVIRONMENT · ' . $host . ' · data may reset',
            ],
            'STAGING' => [
                'bg'   => 'var(--ds-amber, #f59e0b)',
                'fg'   => 'var(--ds-navy, #0b2a4a)',
                'text' => 'STAGING · ' . $host . ' · testing only — not live',
            ],
            'LOCAL' => [
                'bg'   => 'var(--surface-2, #f0f2f8)',
                'fg'   => 'var(--text-primary, #111827)',
                'text' => 'LOCAL DEV · ' . $host,
            ],
        ];
        $c = $themes[$envLabel] ?? [
            'bg'   => 'var(--ds-navy, #0b2a4a)',
            'fg'   => '#ffffff',
            'text' => $envLabel . ' · ' . $host,
        ];
        // AT-230 — the 3-day reset countdown. Computed here, not inline in the
        // markup: an @if spanning several lines of HTML attributes (and sitting
        // directly against another @endif) does not survive Blade's parser.
        //
        // The instant comes from DemoResetSchedule::next() — the SAME pure
        // function the scheduler calls. The banner cannot drift from reality
        // because there is no second source of truth for it to drift from.
        $isDemoInstance = \App\Support\Instance::isDemo();
        $resetAtIso     = $isDemoInstance ? \App\Support\DemoResetSchedule::next()->toIso8601String() : null;
        $resetSeconds   = $isDemoInstance ? \App\Support\DemoResetSchedule::secondsUntilNext() : 0;
        // Server-rendered fallback; the script below refines it to the minute.
        $resetLabel     = 'RESETS IN ' . intdiv($resetSeconds, 86400) . 'd '
                        . intdiv($resetSeconds % 86400, 3600) . 'h';
    @endphp
    <div role="status" aria-label="Environment: {{ $envLabel }}"
         style="flex:0 0 auto; width:100%; height:24px; line-height:24px;
                background:{{ $c['bg'] }}; color:{{ $c['fg'] }};
                border-bottom:1px solid var(--border, rgba(0,0,0,0.14));
                font-size:11px; font-weight:700; letter-spacing:.09em;
                text-transform:uppercase; text-align:center;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
                padding:0 12px; user-select:none;">
        {{ $c['text'] }}@if ($envLabel === 'DEMO') · <a href="https://mail.demo1.corexos.co.za" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline;">Open Mailpit</a>@endif
        @if ($isDemoInstance)
            · <span id="demo-reset-countdown" data-reset-at="{{ $resetAtIso }}"
                    title="The demo database is wiped and rebuilt every 3 days at 03:00 SAST. Anything you change will be lost.">{{ $resetLabel }}</span>
        @endif
    </div>

    {{-- AT-230 — live countdown to the 3-day demo reset.
         Spec: .ai/specs/demo-access-control.md §6.7

         The instant is computed SERVER-side by DemoResetSchedule::next() — the
         same pure function the scheduler calls — and merely rendered here. The
         script only ticks the display; it never decides when the reset is. That
         is why the banner cannot lie: there is no second source of truth for it
         to drift from.

         There is no quiet-hours skip and no deferral, so this number is a promise
         we actually keep. --}}
    @if ($isDemoInstance)
        <script>
            (function () {
                try {
                    var el = document.getElementById('demo-reset-countdown');
                    if (!el) return;

                    var resetAt = new Date(el.dataset.resetAt).getTime();
                    if (isNaN(resetAt)) return;

                    function tick() {
                        var left = Math.max(0, Math.floor((resetAt - Date.now()) / 1000));

                        if (left === 0) {
                            el.textContent = 'RESETTING NOW';
                            return;
                        }

                        var d = Math.floor(left / 86400),
                            h = Math.floor((left % 86400) / 3600),
                            m = Math.floor((left % 3600) / 60);

                        el.textContent = 'RESETS IN ' + (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm';
                    }

                    tick();
                    setInterval(tick, 30000);
                } catch (e) { /* the banner must never break a page */ }
            })();
        </script>
    @endif
@endif
