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
        <div class="mt-4" x-data="{ show: false }">
            <label for="password" class="block text-sm font-medium" style="color:#9ca3af; font-size:0.8125rem;">Password</label>
            <div class="relative mt-1">
                <input id="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password"
                       class="block w-full rounded-lg px-3 py-2 text-sm pr-10"
                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#f9fafb;" />
                <button type="button" @click="show = !show"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 focus:outline-none"
                        style="color:#9ca3af;" title="Toggle password visibility">
                    {{-- Eye open (visible when password is hidden) --}}
                    <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    {{-- Eye closed (visible when password is shown) --}}
                    <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 012.31-3.894M6.938 6.938A9.966 9.966 0 0112 5c4.478 0 8.268 2.943 9.542 7a9.97 9.97 0 01-4.043 5.062M6.938 6.938L3 3m3.938 3.938l3.124 3.124M21 21l-3.938-3.938m0 0l-3.124-3.124m6.187-1.05A3 3 0 009.88 9.88" />
                    </svg>
                </button>
            </div>
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
