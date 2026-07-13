@extends('layouts.agency-setup')

@section('setup-content')
<div class="max-w-md mx-auto px-4 sm:px-6 py-10 sm:py-16">
    <div class="rounded-lg p-6 sm:p-8" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
        <h1 class="text-xl font-bold mb-1" style="color:var(--text-primary,#0f172a);">
            Welcome to {{ $agency->name ?? 'CoreX' }}
        </h1>
        <p class="text-sm mb-6" style="color:var(--text-muted,#64748b);">
            Sign in with your CoreX Admin details to set up your agency. Your progress
            saves as you go, so you can pause and come back any time.
        </p>

        @if ($error)
            <div class="mb-4 rounded-md px-3 py-2 text-sm" role="alert"
                 style="background:color-mix(in srgb, var(--ds-crimson,#e11d48) 12%, transparent); color:var(--ds-crimson,#e11d48);">
                {{ $error }}
            </div>
        @endif

        <form method="POST" action="{{ route('agency-setup.login', ['token' => $setup->urlKey()]) }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-xs font-semibold mb-1" style="color:var(--text-secondary,#475569);">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username"
                       value="{{ old('email') }}"
                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <div>
                <label for="password" class="block text-xs font-semibold mb-1" style="color:var(--text-secondary,#475569);">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <button type="submit" class="setup-cta w-full rounded-md px-4 py-2.5 text-sm font-semibold">
                Sign in & continue
            </button>
        </form>
    </div>

    <p class="text-center text-xs mt-6" style="color:var(--text-muted,#64748b);">
        Not the agency Admin? This link is for the administrator of
        <strong>{{ $agency->name ?? 'this agency' }}</strong>.
    </p>
</div>
@endsection
