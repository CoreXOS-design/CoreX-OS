{{-- AT-423 — a sub-user's username and set-up link, so the admin can pass both on by
     WhatsApp/SMS as well as the email that went to the shared inbox.
     Spec: .ai/specs/one-email-sub-users.md §6.2.

     Two sources:
       - $inviteFor (optional): a sub-user who has not set their password yet. The panel is
         then ALWAYS shown on their page with a fresh link — it must not depend on a one-time
         session flash, which the page's background pollers can use up before the admin sees it.
       - the session flash from create / resend (used on the Users list). --}}
@php
    $ilUser     = (isset($inviteFor) && $inviteFor && $inviteFor->isSubUser() && !$inviteFor->email_verified_at) ? $inviteFor : null;
    $ilLink     = session('invite_link') ?? ($ilUser ? app(\App\Services\Users\OneEmailService::class)->setupUrl($ilUser) : null);
    $ilName     = session('invite_name') ?? $ilUser?->name;
    $ilUsername = session('invite_username') ?? $ilUser?->email;
@endphp
@if($ilLink)
<div class="rounded-md px-4 py-3 text-sm space-y-2"
     x-data="{ copied: false }"
     style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
    <div class="font-semibold">Set-up link for {{ $ilName }}</div>
    <div class="text-xs" style="color:var(--text-secondary);">
        Username: <strong style="color:var(--text-primary);">{{ $ilUsername }}</strong>.
        Give this person their username and the link below. They will be asked for the username before choosing a password.
        The link works for 7 days.
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" readonly value="{{ $ilLink }}" x-ref="inviteLink"
               class="flex-1 min-w-[200px] rounded-md px-3 py-2 text-xs font-mono"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);"
               @focus="$event.target.select()">
        <button type="button" class="corex-btn-primary text-xs"
                @click="navigator.clipboard.writeText($refs.inviteLink.value).then(() => { copied = true; setTimeout(() => copied = false, 2000); })">
            <span x-show="!copied">Copy link</span>
            <span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>
</div>
@endif
