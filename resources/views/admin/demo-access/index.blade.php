{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo Access Control — grant list. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Demo Access')

@section('corex-content')
<div style="padding:24px; max-width:1400px; margin:0 auto;">

    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap;">
        <div>
            <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
                Demo Access
            </h1>
            <p style="font-size:13px; color:var(--text-secondary, #6b7280);">
                Time-boxed, company-attributed access to demo1.corexos.co.za.
                Next demo reset: <strong>{{ $nextReset->format('D j M, H:i') }}</strong> (every 3 days).
            </p>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('admin.demo-access.connection') }}"
               style="padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600;
                      text-decoration:none;
                      border:1px solid {{ $connector ? 'var(--border, rgba(0,0,0,0.14))' : 'var(--ds-crimson, #dc2626)' }};
                      color:var(--text-primary, #111827);
                      background:var(--surface, #ffffff);">
                Demo connection
                @unless ($connector) <span style="color:var(--ds-crimson, #dc2626);">— not set up</span> @endunless
            </a>
            <a href="{{ route('admin.demo-access.tnc') }}"
               style="padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600;
                      text-decoration:none;
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      color:var(--text-primary, #111827);
                      background:var(--surface, #ffffff);">
                Terms &amp; Conditions
                @if ($tncVersion) <span style="opacity:.6;">v{{ $tncVersion->version }}</span> @endif
            </a>
            <a href="{{ route('admin.demo-access.create') }}"
               style="padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600;
                      text-decoration:none; border:none;
                      background:var(--brand-button, #0ea5e9); color:#ffffff;">
                New grant
            </a>
        </div>
    </div>

    {{-- No T&C published = the clickwrap has nothing to show and EVERY prospect is
         hard-blocked at the gate. Surface it loudly here rather than discovering it
         when a prospect calls. --}}
    @unless ($tncVersion)
        <div role="alert"
             style="margin-bottom:16px; padding:12px 14px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.5;">
            <strong>No terms published.</strong> Until a version exists, nobody can get past the
            demo's terms screen — every prospect is blocked.
            <a href="{{ route('admin.demo-access.tnc') }}" style="color:var(--brand-icon, #0ea5e9);">Publish version 1</a>.
        </div>
    @endunless

    @if (session('status'))
        <div role="status"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--border, rgba(0,0,0,0.14));
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ session('status') }}
        </div>
    @endif

    <form method="GET" style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
        <input type="text" name="q" value="{{ $search }}" placeholder="Search company or email"
               style="flex:1 1 260px; padding:8px 11px; border-radius:6px;
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      background:var(--surface, #ffffff);
                      color:var(--text-primary, #111827); font-size:13px;">
        <label style="display:flex; align-items:center; gap:6px; font-size:13px;
                      color:var(--text-secondary, #6b7280);">
            <input type="checkbox" name="archived" value="1" {{ $showArchived ? 'checked' : '' }}
                   onchange="this.form.submit()">
            Show archived
        </label>
        <button type="submit"
                style="padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600;
                       border:1px solid var(--border, rgba(0,0,0,0.14));
                       background:var(--surface, #ffffff);
                       color:var(--text-primary, #111827); cursor:pointer;">
            Search
        </button>
    </form>

    <div style="border:1px solid var(--border, rgba(0,0,0,0.14)); border-radius:6px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:var(--surface-2, #f0f2f8); text-align:left;">
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">Company</th>
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">Email</th>
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">Status</th>
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">First login</th>
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">Expires</th>
                        <th style="padding:10px 12px; font-weight:600; color:var(--text-secondary, #6b7280);">Sessions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($grants as $grant)
                    @php
                        $status = $grant->status();
                        // Plain-English chips, per STANDARDS F.8 — never the raw enum.
                        $chipBorder = match ($status) {
                            'active'   => 'var(--ds-emerald, #10b981)',
                            'expired'  => 'var(--ds-amber, #f59e0b)',
                            'revoked'  => 'var(--ds-crimson, #dc2626)',
                            'archived' => 'var(--border, rgba(0,0,0,0.14))',
                            default    => 'var(--border, rgba(0,0,0,0.14))',
                        };
                    @endphp
                    <tr style="border-top:1px solid var(--border, rgba(0,0,0,0.14));">
                        <td style="padding:10px 12px;">
                            <a href="{{ route('admin.demo-access.show', $grant) }}"
                               style="color:var(--brand-icon, #0ea5e9); font-weight:600; text-decoration:none;">
                                {{ $grant->company_name }}
                            </a>
                        </td>
                        <td style="padding:10px 12px; color:var(--text-secondary, #6b7280);">{{ $grant->contact_email }}</td>
                        <td style="padding:10px 12px;">
                            <span style="display:inline-block; padding:2px 8px; border-radius:6px;
                                         font-size:11px; font-weight:600;
                                         background:var(--surface-2, #f0f2f8);
                                         border:1px solid {{ $chipBorder }};
                                         color:var(--text-primary, #111827);">
                                {{ $grant->statusLabel() }}
                            </span>
                        </td>
                        <td style="padding:10px 12px; color:var(--text-secondary, #6b7280);">
                            {{ $grant->first_login_at?->format('j M Y, H:i') ?? '—' }}
                        </td>
                        <td style="padding:10px 12px; color:var(--text-secondary, #6b7280);">
                            {{-- NULL until first login. "—" is the honest render; a date here
                                 would be a guess about when they will open the email. --}}
                            {{ $grant->expires_at?->format('j M Y, H:i') ?? '—' }}
                        </td>
                        <td style="padding:10px 12px; color:var(--text-secondary, #6b7280);">{{ $grant->sessions_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding:32px; text-align:center; color:var(--text-secondary, #6b7280);">
                            No grants yet.
                            <a href="{{ route('admin.demo-access.create') }}" style="color:var(--brand-icon, #0ea5e9);">Issue the first one</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:16px;">{{ $grants->links() }}</div>
</div>
@endsection
