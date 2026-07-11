{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo T&C versions — IMMUTABLE. Publish-only, never edit. Owner-only.
    Spec: .ai/specs/demo-access-control.md §4.1
--}}
@extends('layouts.corex')

@section('title', 'Demo Terms & Conditions')

@section('corex-content')
<div style="padding:24px; max-width:900px; margin:0 auto;">

    <a href="{{ route('admin.demo-access.index') }}"
       style="font-size:12px; color:var(--text-secondary, #6b7280); text-decoration:none;">← Demo Access</a>

    <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin:8px 0 4px;">
        Demo Terms &amp; Conditions
    </h1>

    {{-- The immutability rule, stated where someone is about to be surprised by it.
         Publishing v2 re-prompts EVERYONE — including prospects mid-session. That is
         the point of clickwrap, not a side effect. --}}
    <div style="margin:12px 0 20px; padding:12px; border-radius:6px;
                background:var(--surface-2, #f0f2f8);
                border:1px solid var(--border, rgba(0,0,0,0.14));
                font-size:12px; line-height:1.6; color:var(--text-secondary, #6b7280);">
        <strong style="color:var(--text-primary, #111827);">Published terms can never be edited.</strong><br>
        To change them you publish a new version. Everyone — including prospects who are
        signed in right now — is asked to accept the new version before they can continue.
        Earlier acceptances stay attached to the exact text that was on screen when they
        agreed, which is what makes them worth anything.
    </div>

    @if (session('status'))
        <div role="status"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-emerald, #10b981);
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.5;">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif

    <h2 style="font-size:15px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:8px;">
        Publish a new version
    </h2>
    <form method="POST" action="{{ route('admin.demo-access.tnc.publish') }}"
          onsubmit="return confirm('Publish a new version?\n\nEveryone currently in the demo will be asked to accept it before they can carry on. This cannot be undone — versions are permanent.');"
          style="margin-bottom:32px;">
        @csrf
        <textarea name="body" rows="12" required
                  placeholder="Paste the full terms text…"
                  style="width:100%; padding:11px; border-radius:6px;
                         border:1px solid var(--border, rgba(0,0,0,0.14));
                         background:var(--surface, #ffffff);
                         color:var(--text-primary, #111827); font-size:13px;
                         line-height:1.6; font-family:inherit; resize:vertical;">{{ old('body', optional($versions->first())->body) }}</textarea>
        <button type="submit"
                style="margin-top:10px; padding:10px 16px; border-radius:6px; border:none;
                       background:var(--brand-button, #0ea5e9); color:#ffffff;
                       font-size:14px; font-weight:600; cursor:pointer;">
            Publish version {{ ($versions->max('version') ?? 0) + 1 }}
        </button>
    </form>

    <h2 style="font-size:15px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:8px;">
        Published versions
    </h2>
    @forelse ($versions as $version)
        <details style="margin-bottom:8px; padding:12px; border-radius:6px;
                        border:1px solid {{ $version->isCurrent() ? 'var(--ds-emerald, #10b981)' : 'var(--border, rgba(0,0,0,0.14))' }};
                        background:var(--surface, #ffffff);">
            <summary style="cursor:pointer; font-size:13px; color:var(--text-primary, #111827);">
                <strong>Version {{ $version->version }}</strong>
                @if ($version->isCurrent())
                    <span style="color:var(--ds-emerald, #10b981); font-weight:600;">· in use</span>
                @else
                    <span style="color:var(--text-secondary, #6b7280);">· superseded</span>
                @endif
                <span style="color:var(--text-secondary, #6b7280);">
                    · published {{ $version->published_at->format('j M Y') }}
                    @if ($version->publisher) by {{ $version->publisher->name }} @endif
                    · {{ $version->acceptances_count }} {{ Str::plural('acceptance', $version->acceptances_count) }}
                </span>
            </summary>
            <div style="margin-top:10px; padding-top:10px; font-size:12px; line-height:1.65;
                        white-space:pre-wrap; color:var(--text-secondary, #6b7280);
                        border-top:1px solid var(--border, rgba(0,0,0,0.14));">{{ $version->body }}</div>
        </details>
    @empty
        <p style="font-size:13px; color:var(--text-secondary, #6b7280);">
            Nothing published yet. Until version 1 exists, nobody can get into the demo.
        </p>
    @endforelse
</div>
@endsection
