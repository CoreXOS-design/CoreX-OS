<x-guest-layout>
    {{-- Session Status --}}
    @if (session('status'))
        <div class="session-status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Email --}}
        <div>
            <label for="email" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb;" />
            @error('email')
                <p class="error-text mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password --}}
        <div class="mt-4">
            <label for="password" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   class="block mt-1 w-full rounded-lg px-3 py-2 text-sm"
                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb;" />
            @error('password')
                <p class="error-text mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Remember me --}}
        <div class="flex items-center mt-4">
            <input id="remember_me" type="checkbox" name="remember"
                   class="rounded border-gray-600 w-4 h-4" style="accent-color:#00b4d8;" />
            <label for="remember_me" class="ms-2 text-sm remember-label" style="color:#9ca3af; font-size:0.8125rem;">Remember me</label>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-between mt-6">
            @if (Route::has('password.request'))
                <a class="forgot-link" href="{{ route('password.request') }}">Forgot password?</a>
            @else
                <span></span>
            @endif

            <button type="submit" class="login-btn">Sign in</button>
        </div>

        {{-- Register link --}}
        <div class="mt-4 text-center">
            <span style="color:#9ca3af; font-size:0.8125rem;">Don't have an account?</span>
            <a href="{{ route('register') }}" class="forgot-link" style="margin-left:4px;">Register here</a>
        </div>
    </form>
</x-guest-layout>
