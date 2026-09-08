@if(\App\Support\OutboundMailGuard::isActive())
    <div class="rounded-lg border-2 border-red-500 bg-red-50 px-4 py-3 mb-4 flex items-start gap-3" style="color:#7f1d1d;">
        <span style="font-size:1.25rem;line-height:1;">&#9888;&#65039;</span>
        <div>
            <p class="font-semibold mb-0.5">This site will NOT send real email.</p>
            <p class="text-sm mb-0">
                Every outbound message from this environment ({{ config('app.env') }} — {{ parse_url(config('app.url'), PHP_URL_HOST) }})
                is intercepted and redirected before it leaves the server. Test Connection, Test Send, and any
                real message will land in Mailpit instead, showing who it would have gone to. Nothing reaches a
                real inbox from here.
            </p>
        </div>
    </div>
@endif
