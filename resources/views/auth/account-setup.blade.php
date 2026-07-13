<x-guest-layout>
    <div style="text-align:center; margin-bottom:1.25rem;">
        <h2 style="color:#f9fafb; font-size:1.125rem; font-weight:700; margin:0 0 4px;">Set Up Your Account</h2>
        <p style="color:#9ca3af; font-size:0.8125rem; margin:0;">
            Welcome, <strong style="color:#f9fafb;">{{ $user->name }}</strong>. Choose a password to get started.
        </p>
    </div>

    @if($errors->any())
        <div style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); border-radius:0.5rem; padding:0.625rem 0.75rem; margin-bottom:1rem;">
            @foreach($errors->all() as $err)
                <p class="error-text" style="margin:0; font-size:0.8125rem;">{{ $err }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $formAction }}">
        @csrf

        {{-- Email (read-only) --}}
        <div>
            <label for="email" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Email</label>
            <input id="email" type="email" value="{{ $user->email }}" disabled
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); color:#6b7280; cursor:not-allowed;" />
        </div>

        {{-- Password --}}
        <div class="mt-4" x-data="{ show: false }">
            <label for="password" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Password</label>
            <div style="position:relative;">
                <input id="password" name="password" required autofocus autocomplete="new-password"
                       :type="show ? 'text' : 'password'"
                       placeholder="Min 8 characters"
                       class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb; padding-right:2.5rem;" />
                <button type="button" @click="show = !show" tabindex="-1"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                        style="position:absolute; top:50%; right:0.5rem; transform:translateY(-50%); margin-top:2px; display:flex; align-items:center; justify-content:center; background:none; border:none; padding:0.25rem; cursor:pointer; color:#9ca3af;">
                    <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Confirm Password --}}
        <div class="mt-4" x-data="{ show: false }">
            <label for="password_confirmation" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Confirm Password</label>
            <div style="position:relative;">
                <input id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                       :type="show ? 'text' : 'password'"
                       placeholder="Repeat password"
                       class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb; padding-right:2.5rem;" />
                <button type="button" @click="show = !show" tabindex="-1"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                        style="position:absolute; top:50%; right:0.5rem; transform:translateY(-50%); margin-top:2px; display:flex; align-items:center; justify-content:center; background:none; border:none; padding:0.25rem; cursor:pointer; color:#9ca3af;">
                    <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-6">
            <button type="submit" class="login-btn w-full" style="width:100%; text-align:center;">
                Set Password &amp; Continue
            </button>
        </div>
    </form>
</x-guest-layout>
