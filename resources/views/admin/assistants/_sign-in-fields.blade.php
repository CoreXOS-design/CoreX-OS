{{-- AT-423 — One email (sub-users) sign-in choice for the Assistants add/edit forms.
     Spec: .ai/specs/one-email-sub-users.md §6.2 / §6.5 / §6.7.
     Expects: $oe (OneEmailService::formData), $subject (?User — the assistant when editing). --}}
@php
    $isSub         = $subject && $subject->isSubUser();
    $signInDefault = old('sign_in_type', $isSub ? 'username' : 'email');
    $usernameName  = old('username', $isSub ? strstr((string) $subject->email, '@', true) : '');
    $usernameStem  = $isSub ? strstr((string) $subject->email, '@') : ($oe['stem'] ?? '');
    $emailValue    = old('email', ($subject && !$isSub) ? $subject->email : '');
@endphp
<div class="sm:col-span-2" x-data="{ signIn: '{{ $signInDefault === 'username' ? 'username' : 'email' }}' }">
    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">How will this person sign in? <span class="text-red-500">*</span></label>
    <div class="flex flex-wrap gap-4 mb-3 text-sm" style="color:var(--text-primary);">
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="sign_in_type" value="email" x-model="signIn">
            Their own email address
        </label>
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="sign_in_type" value="username" x-model="signIn">
            A username, sharing an inbox
        </label>
    </div>

    <div x-show="signIn === 'email'" @if($signInDefault === 'username') x-cloak @endif>
        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Email <span class="text-red-500">*</span></label>
        <input type="email" name="email" value="{{ $emailValue }}"
               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
        <p class="text-xs mt-1" style="color:var(--text-muted);">
            @if($isSub)
                Enter their own email address. From then on they sign in with it instead of their username; their password stays the same.
            @elseif($subject)
                This is the address they sign in with. Changing it does not re-send the setup link — use Resend invite if they still need one.
            @else
                We'll email them a link to set their own password.
            @endif
        </p>
    </div>

    <div x-show="signIn === 'username'" @if($signInDefault !== 'username') x-cloak @endif>
        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Username <span class="text-red-500">*</span></label>
        <div class="flex items-stretch w-full">
            <input type="text" name="username" value="{{ $usernameName }}"
                   autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="e.g. thandi"
                   class="flex-1 min-w-0 rounded-l-md px-3 py-2.5 text-sm outline-none transition-colors"
                   style="background:var(--surface-2); border:1px solid var(--border); border-right:none; color:var(--text-primary);"
                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
            <span class="inline-flex items-center px-3 rounded-r-md text-sm"
                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">{{ $usernameStem ?: '@…' }}</span>
        </div>
        <p class="text-xs mt-1" style="color:var(--text-muted);">
            They sign in with this username and their own password.
            @if($oe['inbox'])
                Their CoreX emails, including the set-up link, go to the shared inbox <strong>{{ $oe['inbox'] }}</strong>.
            @endif
            Letters, numbers, dots, dashes and underscores only.
        </p>
    </div>

    @if($isSub)
    {{-- Option A (Andre, 2026-09-21): only an admin resets a sub-user's password, as a temporary one. --}}
    <div class="mt-4">
        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
            Reset password <span style="color:var(--text-muted); font-weight:400;">(leave blank to keep their current password)</span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <input type="password" name="password" autocomplete="new-password" placeholder="Temporary password (min 8 characters)"
                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Type the temporary password again"
                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <p class="text-xs mt-1" style="color:var(--text-muted);">
            Sub-users cannot reset their own password. Set a temporary one here and give it to them;
            the next time they sign in they must choose their own before they can continue.
            @if($subject->must_change_password)
                <strong style="color:var(--ds-amber, #f59e0b);">They have not chosen their new password yet.</strong>
            @endif
        </p>
    </div>
    @endif
</div>
