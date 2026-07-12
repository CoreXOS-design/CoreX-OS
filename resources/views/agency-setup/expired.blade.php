@extends('layouts.agency-setup')

@section('setup-content')
<div class="max-w-md mx-auto px-4 sm:px-6 py-16 text-center">
    <div class="rounded-lg p-8" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
        <div class="mx-auto mb-4 h-12 w-12 rounded-full flex items-center justify-center"
             style="background:color-mix(in srgb, var(--ds-crimson,#e11d48) 12%, transparent);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                 class="h-6 w-6" style="color:var(--ds-crimson,#e11d48);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
        </div>
        <h1 class="text-lg font-bold mb-2" style="color:var(--text-primary,#0f172a);">
            This setup link is no longer active
        </h1>
        <p class="text-sm" style="color:var(--text-muted,#64748b);">
            @if ($setup->revoked_at)
                The link has been revoked.
            @else
                The link has expired.
            @endif
            Your CoreX administrator can re-send a fresh setup link from the
            Agency Setup Progress page.
        </p>
    </div>
</div>
@endsection
