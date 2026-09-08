{{--
    AT-URGENT-2026-09-08 (Johan, via conductor) — the previous wording of this
    banner said "Nothing reaches a real inbox from here," which was not true:
    Test Connection wrote a real message into the mailbox's own Sent folder
    on the real mail server as a separate step this interception could not
    reach. Confirmed by packet capture. cc3's ImapSentFolderAppender guard
    fix (landed the same session, immediately before this) closes that
    specific gap — append() now checks OutboundMailGuard itself, before any
    connection is attempted. Updated this copy to describe the environment
    AFTER that fix, not the leak that prompted it — the whole point of this
    banner is to never state something the code can't currently back up.
--}}
@if(\App\Support\OutboundMailGuard::isActive())
    <div class="rounded-lg border-2 border-red-500 bg-red-50 px-4 py-3 mb-4 flex items-start gap-3" style="color:#7f1d1d;">
        <span style="font-size:1.25rem;line-height:1;">&#9888;&#65039;</span>
        <div>
            <p class="font-semibold mb-0.5">This is a test site. No real message leaves this environment.</p>
            <p class="text-sm mb-0">
                Messages the application sends — invitations, notifications, replies — are caught before they
                leave this server ({{ config('app.env') }} — {{ parse_url(config('app.url'), PHP_URL_HOST) }})
                and land in Mailpit instead, showing who they would have gone to. Nobody real receives those.
            </p>
            <p class="text-sm mb-0 mt-2">
                <strong>Test Connection's Sent-folder check is also blocked here.</strong> It normally verifies a
                mailbox's real Sent folder over a separate IMAP connection — on this environment that check is
                skipped instead of attempted, so it never writes anything to a real mailbox either.
            </p>
        </div>
    </div>
@endif
