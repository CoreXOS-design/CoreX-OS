<x-guest-layout>
    <div class="space-y-4">
        <div class="text-center">
            <h2 class="text-lg font-semibold" style="color: #ffffff;">System Owner Login</h2>
            <p class="text-xs mt-1" style="color: var(--text-secondary);">
                Sign in with System Owner credentials to access platform tools.
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded-md px-3 py-2 text-xs"
                 style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent);
                        color: var(--text-primary);">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('demo.owner.login.store') }}" class="space-y-3">
            @csrf

            <div>
                <label for="email" class="block text-xs font-medium mb-1">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="block w-full rounded-md px-3 py-2 text-sm" />
            </div>

            <div x-data="{ show: false }">
                <label for="password" class="block text-xs font-medium mb-1">Password</label>
                <div class="relative">
                    <input id="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password"
                           class="block w-full rounded-md px-3 py-2 text-sm pr-10" />
                    <button type="button" @click="show = !show"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 focus:outline-none"
                            style="color: var(--text-muted, #9ca3af);" title="Toggle password visibility">
                        <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <svg x-show="show" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A10.7 10.7 0 0 1 12 5c7 0 11 7 11 7a17.2 17.2 0 0 1-3.2 3.9M6.6 6.6A17.2 17.2 0 0 0 1 12s4 7 11 7c1.7 0 3.2-.4 4.6-1" />
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full rounded-md px-4 py-2 text-sm font-medium transition mt-2"
                    style="background: var(--brand-button, #0ea5e9); color: #fff;">
                Sign In
            </button>
        </form>

        <div class="text-center pt-2">
            <a href="{{ route('login') }}" class="text-xs" style="color: var(--text-muted);">
                ← Back to demo
            </a>
        </div>
    </div>
</x-guest-layout>
