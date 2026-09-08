{{--
    AT-URGENT-2026-09-08 (Johan, via conductor) — the previous wording of this
    banner said "Nothing reaches a real inbox from here," which is not true:
    Test Connection writes a real message into the mailbox's own Sent folder
    on the real mail server as a separate step that this interception cannot
    reach. Confirmed by packet capture. This copy must say exactly what does
    and does not happen — no claim this screen can't back up.
--}}
@if(\App\Support\OutboundMailGuard::isActive())
    <div class="rounded-lg border-2 border-red-500 bg-red-50 px-4 py-3 mb-4 flex items-start gap-3" style="color:#7f1d1d;">
        <span style="font-size:1.25rem;line-height:1;">&#9888;&#65039;</span>
        <div>
            <p class="font-semibold mb-0.5">This is a test site. Most email is safely caught — but not all of it.</p>
            <p class="text-sm mb-0">
                Messages the application sends — invitations, notifications, replies — are caught before they
                leave this server ({{ config('app.env') }} — {{ parse_url(config('app.url'), PHP_URL_HOST) }})
                and land in Mailpit instead, showing who they would have gone to. Nobody real receives those.
            </p>
            <p class="text-sm mb-0 mt-2">
                <strong>Test Connection is the exception.</strong> It also drops a copy of the test message
                straight into that mailbox's own Sent folder on its real mail server, the same way a genuine
                send would. That copy is <strong>not</strong> caught — it will show up in the real mailbox's
                Sent items, exactly as if it had actually been sent.
            </p>
        </div>
    </div>
@endif
