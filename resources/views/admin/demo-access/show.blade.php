{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo grant detail — telemetry, acceptances, revoke/archive. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Demo grant — ' . $grant->company_name)

@section('corex-content')
<div style="padding:24px; max-width:1100px; margin:0 auto;">

    <a href="{{ route('admin.demo-access.index') }}"
       style="font-size:12px; color:var(--text-secondary, #6b7280); text-decoration:none;">← All grants</a>

    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:8px 0 20px; flex-wrap:wrap;">
        <div>
            <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
                {{ $grant->company_name }}
            </h1>
            <p style="font-size:13px; color:var(--text-secondary, #6b7280);">
                {{ $grant->contact_email }}
                @if ($grant->contact_name) · {{ $grant->contact_name }} @endif
                · <strong>{{ $grant->statusLabel() }}</strong>
            </p>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('admin.demo-access.edit', $grant) }}"
               style="padding:8px 14px; border-radius:6px; font-size:13px; text-decoration:none;
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      color:var(--text-primary, #111827);">Edit</a>

            @if (!$grant->revoked_at && !$grant->archived_at)
                {{-- The confirm text states the REAL latency. The gate caches primary's
                     verdict for the TTL, so a revoke bites within that window — not
                     instantly. Promising an instant kill we cannot deliver would be a
                     lie the first time someone tested it. --}}
                <form method="POST" action="{{ route('admin.demo-access.revoke', $grant) }}"
                      onsubmit="return confirm('Revoke access for {{ addslashes($grant->company_name) }}?\n\nThey will be locked out within {{ $cacheTtl }} seconds — not instantly. If they are mid-page right now, they may finish that page.');">
                    @csrf
                    <button type="submit"
                            style="padding:8px 14px; border-radius:6px; font-size:13px; cursor:pointer;
                                   border:1px solid var(--ds-crimson, #dc2626);
                                   background:transparent; color:var(--ds-crimson, #dc2626);">
                        Revoke
                    </button>
                </form>
            @endif

            @if (!$grant->archived_at)
                <form method="POST" action="{{ route('admin.demo-access.destroy', $grant) }}"
                      onsubmit="return confirm('Archive this grant?\n\nIt is hidden from the list but kept permanently as a record of who accepted which terms. Nothing is deleted.');">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="padding:8px 14px; border-radius:6px; font-size:13px; cursor:pointer;
                                   border:1px solid var(--border, rgba(0,0,0,0.14));
                                   background:transparent; color:var(--text-secondary, #6b7280);">
                        Archive
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.demo-access.restore', $grant) }}">
                    @csrf
                    <button type="submit"
                            style="padding:8px 14px; border-radius:6px; font-size:13px; cursor:pointer;
                                   border:1px solid var(--border, rgba(0,0,0,0.14));
                                   background:transparent; color:var(--text-primary, #111827);">
                        Restore
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- THE ONLY TIME THE PLAINTEXT CODE EXISTS OUTSIDE THE EMAIL.
         The DB holds bcrypt(code); after this page it is unrecoverable. That is the
         correct property for a credential, and the copy says so plainly so nobody
         goes looking for a "show code" button that cannot exist. --}}
    @if ($plainCode)
        <div style="margin-bottom:20px; padding:16px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-emerald, #10b981);">
            <p style="font-size:13px; font-weight:600; color:var(--text-primary, #111827); margin-bottom:8px;">
                Invitation sent to {{ $grant->contact_email }}. Here is the access code:
            </p>
            <p style="font-family:'JetBrains Mono', ui-monospace, monospace; font-size:20px;
                      font-weight:700; letter-spacing:0.1em;
                      color:var(--text-primary, #111827); margin-bottom:8px;">
                {{ $plainCode }}
            </p>
            <p style="font-size:12px; color:var(--text-secondary, #6b7280); line-height:1.5;">
                <strong>This will not be shown again.</strong> We store only a hash of it, so it
                cannot be looked up later. If it's lost, issue a new grant.
            </p>
        </div>
    @endif

    @if (session('status'))
        <div role="status"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--border, rgba(0,0,0,0.14));
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Facts --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:24px;">
        @php
            $facts = [
                'Access length' => $grant->expiry_hours . ' hours',
                'First sign-in' => $grant->first_login_at?->format('j M Y, H:i') ?? 'Not used yet',
                'Expires'       => $grant->expires_at?->format('j M Y, H:i') ?? 'Starts at first sign-in',
                'Issued by'     => $grant->issuer?->name ?? '—',
                'Issued'        => $grant->created_at?->format('j M Y'),
                'Revoked'       => $grant->revoked_at?->format('j M Y, H:i') ?? '—',
            ];
        @endphp
        @foreach ($facts as $label => $value)
            <div style="padding:12px; border-radius:6px;
                        border:1px solid var(--border, rgba(0,0,0,0.14));
                        background:var(--surface, #ffffff);">
                <div style="font-size:11px; font-weight:600; color:var(--text-secondary, #6b7280); margin-bottom:3px;">{{ $label }}</div>
                <div style="font-size:13px; color:var(--text-primary, #111827);">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @if ($grant->notes)
        <div style="margin-bottom:24px; padding:12px; border-radius:6px;
                    border:1px solid var(--border, rgba(0,0,0,0.14));
                    background:var(--surface, #ffffff);">
            <div style="font-size:11px; font-weight:600; color:var(--text-secondary, #6b7280); margin-bottom:4px;">Notes</div>
            <div style="font-size:13px; color:var(--text-primary, #111827); white-space:pre-wrap;">{{ $grant->notes }}</div>
        </div>
    @endif

    {{-- Terms accepted. Renders the body AS ACCEPTED — DemoTncVersion is immutable,
         so this is the exact text that was on their screen, even after v2 ships. --}}
    <h2 style="font-size:15px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:8px;">
        Terms accepted
    </h2>
    @forelse ($grant->acceptances as $acceptance)
        <details style="margin-bottom:8px; padding:12px; border-radius:6px;
                        border:1px solid var(--border, rgba(0,0,0,0.14));
                        background:var(--surface, #ffffff);">
            <summary style="cursor:pointer; font-size:13px; color:var(--text-primary, #111827);">
                <strong>Version {{ $acceptance->version->version }}</strong>
                accepted {{ $acceptance->accepted_at->format('j M Y, H:i') }}
                @if ($acceptance->ip_address)
                    <span style="color:var(--text-secondary, #6b7280);">from {{ $acceptance->ip_address }}</span>
                @endif
                @unless ($acceptance->version->isCurrent())
                    <span style="color:var(--text-secondary, #6b7280);">· superseded</span>
                @endunless
            </summary>
            <div style="margin-top:10px; padding-top:10px; font-size:12px; line-height:1.6;
                        white-space:pre-wrap; color:var(--text-secondary, #6b7280);
                        border-top:1px solid var(--border, rgba(0,0,0,0.14));">{{ $acceptance->version->body }}</div>
        </details>
    @empty
        <p style="font-size:13px; color:var(--text-secondary, #6b7280); margin-bottom:16px;">
            Not accepted yet.
        </p>
    @endforelse

    {{-- Telemetry --}}
    <h2 style="font-size:15px; font-weight:700; color:var(--text-primary, #111827); margin:24px 0 8px;">
        Sessions &amp; pages viewed
    </h2>
    @forelse ($grant->sessions as $session)
        <div style="margin-bottom:10px; border-radius:6px;
                    border:1px solid var(--border, rgba(0,0,0,0.14));
                    background:var(--surface, #ffffff);">
            <div style="padding:10px 12px; font-size:12px;
                        background:var(--surface-2, #f0f2f8);
                        color:var(--text-secondary, #6b7280);">
                {{ $session->started_at->format('j M Y, H:i') }}
                · last seen {{ $session->last_seen_at->diffForHumans() }}
                · {{ $session->pageViews->count() }} pages
                @if ($session->ip_address) · {{ $session->ip_address }} @endif
            </div>
            @if ($session->pageViews->isNotEmpty())
                <ul style="margin:0; padding:8px 12px 10px 28px; font-size:12px;
                           color:var(--text-primary, #111827);">
                    @foreach ($session->pageViews as $view)
                        <li style="padding:2px 0;">
                            <span style="font-family:'JetBrains Mono', ui-monospace, monospace;">{{ $view->path }}</span>
                            <span style="color:var(--text-secondary, #6b7280);">— {{ $view->viewed_at->format('H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p style="font-size:13px; color:var(--text-secondary, #6b7280);">
            They haven't signed in yet.
        </p>
    @endforelse
</div>
@endsection
