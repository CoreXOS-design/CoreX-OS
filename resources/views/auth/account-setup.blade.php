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

    <form method="POST" action="{{ route('account.setup.store', ['user' => $user->id]) }}">
        @csrf

        {{-- Email (read-only) --}}
        <div>
            <label for="email" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Email</label>
            <input id="email" type="email" value="{{ $user->email }}" disabled
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); color:#6b7280; cursor:not-allowed;" />
        </div>

        {{-- Password --}}
        <div class="mt-4">
            <label for="password" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Password</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                   placeholder="Min 8 characters"
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb;" />
        </div>

        {{-- Confirm Password --}}
        <div class="mt-4">
            <label for="password_confirmation" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                   placeholder="Repeat password"
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb;" />
        </div>

        {{-- Submit --}}
        <div class="mt-6">
            <button type="submit" class="login-btn w-full" style="width:100%; text-align:center;">
                Set Password &amp; Continue
            </button>
        </div>
    </form>
</x-guest-layout>
