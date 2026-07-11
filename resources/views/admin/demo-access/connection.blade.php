{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    The universal demo connector — minted HERE (on live), pasted into the demo.
    Owner-only. Spec: .ai/specs/demo-access-control.md §5.1
--}}
@extends('layouts.corex')

@section('title', 'Demo connection')

@section('corex-content')
<div style="padding:24px; max-width:820px; margin:0 auto;">

    <a href="{{ route('admin.demo-access.index') }}"
       style="font-size:12px; color:var(--text-secondary, #6b7280); text-decoration:none;">← Demo Access</a>

    <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin:8px 0 4px;">
        Demo connection
    </h1>
    <p style="font-size:13px; color:var(--text-secondary, #6b7280); margin-bottom:20px; line-height:1.6;">
        One token, for the one demo. The demo site uses it to ask this system whether a
        prospect's access code is real — so grants, terms and telemetry all live here,
        and survive the demo's 3-day wipe.
    </p>

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

    {{-- THE ONLY TIME THE TOKEN EXISTS IN READABLE FORM.
         We store sha256 of it. There is no "show token" button and there cannot be. --}}
    @if ($plainToken)
        <div style="margin-bottom:24px; padding:16px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-emerald, #10b981);">
            <p style="font-size:13px; font-weight:600; color:var(--text-primary, #111827); margin-bottom:8px;">
                Copy this token now and paste it into the demo.
            </p>
            <p id="demo-token"
               style="font-family:'JetBrains Mono', ui-monospace, monospace; font-size:13px;
                      word-break:break-all; padding:10px; border-radius:6px;
                      background:var(--surface, #ffffff);
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      color:var(--text-primary, #111827); margin-bottom:10px;">{{ $plainToken }}</p>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('demo-token').textContent.trim()); this.textContent='Copied';"
                    style="padding:7px 12px; border-radius:6px; border:none; cursor:pointer;
                           background:var(--brand-button, #0ea5e9); color:#ffffff;
                           font-size:12px; font-weight:600;">
                Copy token
            </button>
            <p style="margin-top:10px; font-size:12px; color:var(--text-secondary, #6b7280); line-height:1.5;">
                <strong>This will not be shown again.</strong> Only a hash of it is stored, so it
                cannot be looked up later. If you lose it, issue a new one — which replaces this
                one, and the demo will stop working until you paste the new token in.
            </p>
        </div>
    @endif

    {{-- Current state --}}
    <div style="margin-bottom:24px; padding:16px; border-radius:6px;
                border:1px solid var(--border, rgba(0,0,0,0.14));
                background:var(--surface, #ffffff);">
        <h2 style="font-size:14px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:12px;">
            Current connector
        </h2>

        @if ($connector)
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:14px;">
                @php
                    $facts = [
                        'Name'      => $connector->name,
                        'Token ID'  => $connector->key_prefix,
                        'Issued'    => $connector->created_at->format('j M Y, H:i'),
                        'Last used' => $connector->last_used_at
                            ? $connector->last_used_at->diffForHumans()
                            : 'Never — the demo has not called yet',
                    ];
                @endphp
                @foreach ($facts as $label => $value)
                    <div>
                        <div style="font-size:11px; font-weight:600; color:var(--text-secondary, #6b7280); margin-bottom:3px;">{{ $label }}</div>
                        <div style="font-size:13px; color:var(--text-primary, #111827); word-break:break-all;">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            {{-- "Last used: Never" is the single most useful diagnostic on this page.
                 It distinguishes "the demo is not calling us" (wrong URL, wrong token,
                 role not flipped) from "the demo is calling and being refused". --}}
            @unless ($connector->last_used_at)
                <p style="font-size:12px; color:var(--text-secondary, #6b7280); line-height:1.5; margin-bottom:14px;">
                    The demo has never used this token. Either it has not been pasted in yet, or the
                    demo cannot reach this address. Check the demo's own <em>Demo Connection</em> page
                    and press <em>Test connection</em> there.
                </p>
            @endunless

            <form method="POST" action="{{ route('admin.demo-access.connection.revoke') }}"
                  onsubmit="return confirm('Revoke this connector?\n\nThe demo will immediately lose access to CoreX. Because the demo gate fails closed, NOBODY will be able to sign in to the demo until you issue a new token and paste it in.\n\nDo this if the token has leaked. Do not do it to “reset” anything.');"
                  style="display:inline;">
                @csrf
                <button type="submit"
                        style="padding:8px 14px; border-radius:6px; font-size:13px; cursor:pointer;
                               border:1px solid var(--ds-crimson, #dc2626);
                               background:transparent; color:var(--ds-crimson, #dc2626);">
                    Revoke connector
                </button>
            </form>
        @else
            <p role="alert" style="font-size:13px; color:var(--text-primary, #111827); line-height:1.6;">
                <strong>No connector.</strong> The demo cannot reach this system, so nobody can sign in
                to the demo — the gate fails closed by design. Issue one below.
            </p>
        @endif
    </div>

    {{-- Mint / rotate --}}
    <div style="margin-bottom:24px; padding:16px; border-radius:6px;
                border:1px solid var(--border, rgba(0,0,0,0.14));
                background:var(--surface, #ffffff);">
        <h2 style="font-size:14px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:6px;">
            {{ $connector ? 'Replace the connector' : 'Issue a connector' }}
        </h2>
        <p style="font-size:12px; color:var(--text-secondary, #6b7280); margin-bottom:12px; line-height:1.5;">
            @if ($connector)
                Issuing a new token <strong>immediately revokes the current one</strong> — there is only
                ever one. The demo will stop working until you paste the new token into it. That is
                deliberate: rotating a leaked credential that kept working would achieve nothing.
            @else
                Issue the token, then paste it into the demo's <em>Demo Connection</em> page along with
                this system's address.
            @endif
        </p>

        <form method="POST" action="{{ route('admin.demo-access.connection.mint') }}"
              @if ($connector)
                  onsubmit="return confirm('Issue a new token?\n\nThis REVOKES the current one immediately. The demo will stop working until you paste the new token into it.');"
              @endif
              style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
            @csrf
            <div style="flex:1 1 240px;">
                <label for="name" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                    Label
                </label>
                <input id="name" name="name" type="text" value="{{ old('name', 'CoreX Demo Host') }}" maxlength="100"
                       style="width:100%; padding:9px 11px; border-radius:6px;
                              border:1px solid var(--border, rgba(0,0,0,0.14));
                              background:var(--surface, #ffffff);
                              color:var(--text-primary, #111827); font-size:14px;">
            </div>
            <button type="submit"
                    style="padding:10px 16px; border-radius:6px; border:none;
                           background:var(--brand-button, #0ea5e9); color:#ffffff;
                           font-size:14px; font-weight:600; cursor:pointer;">
                {{ $connector ? 'Replace token' : 'Issue token' }}
            </button>
        </form>
    </div>

    {{-- What to paste into the demo --}}
    <div style="margin-bottom:24px; padding:16px; border-radius:6px;
                background:var(--surface-2, #f0f2f8);
                border:1px solid var(--border, rgba(0,0,0,0.14));">
        <h2 style="font-size:14px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:8px;">
            On the demo, paste this address
        </h2>
        <p style="font-family:'JetBrains Mono', ui-monospace, monospace; font-size:13px;
                  color:var(--text-primary, #111827); word-break:break-all;">{{ $apiBase }}</p>
        <p style="margin-top:8px; font-size:12px; color:var(--text-secondary, #6b7280); line-height:1.6;">
            Sign in to the demo as a System Owner, go to <strong>Dev Settings → Demo Connection</strong>,
            paste that address and the token, then press <strong>Test connection</strong>.
        </p>
    </div>

    {{-- Rotation history — the table doubles as the audit trail, because rotation is
         insert-and-revoke rather than update. --}}
    @if ($history->count() > 1)
        <h2 style="font-size:14px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:8px;">
            Previous connectors
        </h2>
        <div style="border:1px solid var(--border, rgba(0,0,0,0.14)); border-radius:6px; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <tbody>
                @foreach ($history as $h)
                    <tr style="border-top:1px solid var(--border, rgba(0,0,0,0.14));">
                        <td style="padding:8px 12px; font-family:'JetBrains Mono', ui-monospace, monospace; color:var(--text-primary, #111827);">{{ $h->key_prefix }}</td>
                        <td style="padding:8px 12px; color:var(--text-secondary, #6b7280);">{{ $h->name }}</td>
                        <td style="padding:8px 12px; color:var(--text-secondary, #6b7280);">
                            {{ $h->created_at->format('j M Y') }}
                            @if ($h->creator) · {{ $h->creator->name }} @endif
                        </td>
                        <td style="padding:8px 12px; text-align:right;">
                            @if ($h->isActive())
                                <span style="color:var(--ds-emerald, #10b981); font-weight:600;">Active</span>
                            @else
                                <span style="color:var(--text-secondary, #6b7280);">Revoked {{ $h->revoked_at->format('j M Y') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
