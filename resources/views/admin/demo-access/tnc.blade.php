{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo T&C versions — IMMUTABLE. Publish-only, never edit. Owner-only.
    Spec: .ai/specs/demo-access-control.md §4.1
--}}
@extends('layouts.corex')

@section('title', 'Demo Terms & Conditions')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Back link --}}
    <a href="{{ route('admin.demo-access.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Back to Demo Access
    </a>

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Demo Terms &amp; Conditions</h1>
        <p class="text-sm text-white/60">
            The clickwrap every prospect accepts before they can use the demo.
        </p>
    </div>

    {{-- The immutability rule, stated where someone is about to be surprised by it.
         Publishing v2 re-prompts EVERYONE — including prospects mid-session. That is
         the point of clickwrap, not a side effect. §3.9 warning alert. --}}
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3 max-w-4xl"
         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber, #f59e0b);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
        <div class="flex-1">
            <strong>Published terms can never be edited.</strong>
            To change them you publish a new version. Everyone — including prospects who are
            signed in right now — is asked to accept the new version before they can continue.
            Earlier acceptances stay attached to the exact text that was on screen when they
            agreed, which is what makes them worth anything.
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm font-medium max-w-4xl"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm max-w-4xl"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Publish a new version --}}
    <form method="POST" action="{{ route('admin.demo-access.tnc.publish') }}"
          onsubmit="return confirm('Publish a new version?\n\nEveryone currently in the demo will be asked to accept it before they can carry on. This cannot be undone — versions are permanent.');"
          class="max-w-4xl">
        @csrf

        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Publish a new version</h2>

            <label for="body" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                Terms text <span class="text-red-500">*</span>
            </label>
            <textarea id="body" name="body" rows="12" required
                      placeholder="Paste the full terms text…"
                      class="w-full rounded-md px-3 py-2 text-sm leading-relaxed"
                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical;">{{ old('body', optional($versions->first())->body) }}</textarea>
            <x-input-error :messages="$errors->get('body')" class="mt-1" />

            <div class="mt-4">
                <button type="submit" class="corex-btn-primary text-sm">
                    Publish version {{ number_format(($versions->max('version') ?? 0) + 1) }}
                </button>
            </div>
        </div>
    </form>

    {{-- Published versions --}}
    <div class="space-y-3 max-w-4xl">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Published versions</h2>

        @forelse ($versions as $version)
            <details class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <summary class="cursor-pointer text-sm flex items-center gap-2 flex-wrap" style="color: var(--text-primary);">
                    <strong>Version {{ $version->version }}</strong>
                    @if ($version->isCurrent())
                        <span class="ds-badge ds-badge-success">In use</span>
                    @else
                        <span class="ds-badge ds-badge-muted">Superseded</span>
                    @endif
                    <span class="text-xs" style="color: var(--text-muted);">
                        published {{ $version->published_at->format('j M Y') }}
                        @if ($version->publisher) by {{ $version->publisher->name }} @endif
                        · {{ number_format($version->acceptances_count) }} {{ Str::plural('acceptance', $version->acceptances_count) }}
                    </span>
                </summary>
                <div class="mt-3 pt-3 text-xs leading-relaxed whitespace-pre-wrap"
                     style="color: var(--text-secondary); border-top: 1px solid var(--border);">{{ $version->body }}</div>
            </details>
        @empty
            {{-- §3.10 empty state. The CTA is the textarea above it, so this points at it
                 rather than duplicating a button that would scroll nowhere useful. --}}
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson, #c41e3a);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Nothing published yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">
                    Until version 1 exists, nobody can get into the demo. Paste the terms above and publish.
                </p>
            </div>
        @endforelse
    </div>
</div>
@endsection
