{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    Per-company demo watermark + the page-view beacon.
    Spec: .ai/specs/demo-access-control.md §6.4, §6.5

    ══ INCLUDED IN **BOTH** AUTHENTICATED LAYOUTS ══

        layouts/corex-app.blade.php   (~159 views)
        layouts/corex.blade.php       (~231 views)

    Including it in only the first — which is where the docs point — leaves the
    MAJORITY of CoreX pages unmarked and untracked. If you add a third layout,
    add this to it too.

    Renders NOTHING unless this is a demo instance with a resolved grant. On
    primary it emits no element, no script, no layout shift.

    The watermark attributes a leaked screenshot to a company. It is deliberately
    faint and pointer-events:none — it must not obstruct the product during a
    sales demo, only survive a screenshot.
--}}
@php
    $_demoGrant   = request()->attributes->get('demo_grant');
    $_demoSession = request()->attributes->get('demo_session_token');
@endphp

@if (\App\Support\Instance::isDemo() && is_array($_demoGrant))
    @php
        $_wmText = trim(($_demoGrant['company_name'] ?? '') . ' · ' . ($_demoGrant['email'] ?? ''))
                   . ' · ' . now()->format('Y-m-d H:i');
        // Tiled diagonal SVG, inlined as a data URI so there is no extra request
        // and nothing for a CSP to block.
        $_wmSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="460" height="200">'
                . '<text x="0" y="100" transform="rotate(-24 0 100)" '
                . 'font-family="Inter, sans-serif" font-size="14" fill="%23808080">'
                . e($_wmText) . '</text></svg>';
    @endphp

    <div aria-hidden="true"
         style="position:fixed; inset:0; z-index:9999;
                pointer-events:none;
                opacity:0.06;
                background-image:url('data:image/svg+xml;utf8,{{ rawurlencode($_wmSvg) }}');
                background-repeat:repeat;
                print-color-adjust:exact; -webkit-print-color-adjust:exact;"></div>

    @if ($_demoSession)
        {{--
            Page-view beacon. FAILS OPEN by construction:

            - navigator.sendBeacon is fire-and-forget. It cannot block the page, it
              cannot delay unload, and it has no response to fail on.
            - The whole thing is wrapped in try/catch. If anything here threw, it
              would throw on EVERY demo page — telemetry must never be able to
              break the product it is measuring.
            - The endpoint answers 204 unconditionally.

            FormData (not JSON): sendBeacon sends it as multipart/form-data, which
            Laravel parses into the request body — so the CSRF _token is found
            where VerifyCsrfToken looks for it. A JSON blob would not be.
        --}}
        <script>
            (function () {
                try {
                    if (!navigator.sendBeacon) return;

                    var fd = new FormData();
                    fd.append('_token', @json(csrf_token()));
                    fd.append('path',   window.location.pathname);
                    fd.append('route',  @json(optional(request()->route())->getName()));
                    fd.append('title',  document.title);

                    navigator.sendBeacon(@json(route('demo.telemetry')), fd);
                } catch (e) { /* telemetry never breaks a page */ }
            })();
        </script>
    @endif
@endif
