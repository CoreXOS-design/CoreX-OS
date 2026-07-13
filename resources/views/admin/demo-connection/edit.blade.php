{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo Connection — the DEMO side. Paste the CoreX address + connector token.
    Owner-only, demo-instance-only. Spec: .ai/specs/demo-access-control.md §5.2
--}}
@extends('layouts.corex')

@section('title', 'Demo connection')

@section('corex-content')
<div style="padding:24px; max-width:760px; margin:0 auto;">

    <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
        Demo connection
    </h1>
    <p style="font-size:13px; color:var(--text-secondary, #6b7280); margin-bottom:20px; line-height:1.6;">
        This demo asks CoreX whether a prospect's access code is real. Point it at CoreX and
        paste the connector token you issued there — the demo's own database is wiped every
        three days, so nothing about who has access is kept here.
    </p>

    {{-- Wired or not, stated first. This is the answer to "why can nobody get in?" --}}
    @if ($isWired)
        <div role="status"
             style="margin-bottom:16px; padding:12px 14px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-emerald, #10b981);
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.6;">
            <strong>Configured.</strong> Pointing at <code>{{ $controlUrl }}</code>
            with token <code>{{ $tokenPrefix ?? 'set' }}</code>.
            Press <em>Test connection</em> to confirm CoreX actually answers.
        </div>
    @else
        <div role="alert"
             style="margin-bottom:16px; padding:12px 14px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.6;">
            <strong>Not configured — nobody can sign in to this demo.</strong>
            The gate deliberately fails closed: without a working connection to CoreX it cannot
            check anyone's access code, so it lets nobody through. Fill this in to open the demo.
        </div>
    @endif

    {{-- The Test-connection verdict. The whole reason this button exists is to turn a
         silent fail-closed gate into a sentence someone can act on. --}}
    @if ($testResult)
        <div role="status"
             style="margin-bottom:16px; padding:12px 14px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid {{ $testResult['ok'] ? 'var(--ds-emerald, #10b981)' : 'var(--ds-crimson, #dc2626)' }};
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.6;">
            <strong>{{ $testResult['ok'] ? 'Connected.' : 'Not connected.' }}</strong>
            {{ $testResult['message'] }}
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

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.demo-connection.update') }}"
          style="padding:16px; border-radius:6px;
                 border:1px solid var(--border, rgba(0,0,0,0.14));
                 background:var(--surface, #ffffff); margin-bottom:16px;">
        @csrf @method('PUT')

        <div style="margin-bottom:16px;">
            <label for="control_url" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                CoreX address <span style="color:var(--ds-crimson, #dc2626);">*</span>
            </label>
            <input id="control_url" name="control_url" type="url" required
                   value="{{ old('control_url', $controlUrl) }}"
                   placeholder="https://corex.hfcoastal.co.za"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;
                          font-family:'JetBrains Mono', ui-monospace, monospace;">
            <p style="margin-top:5px; font-size:11px; color:var(--text-secondary, #6b7280);">
                The live CoreX system — shown for you on its Demo Access → Demo connection page.
            </p>
        </div>

        <div style="margin-bottom:18px;">
            <label for="control_token" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Connector token
            </label>
            {{-- The secret is NEVER rendered back — only its prefix, above. So a blank
                 field means "I'm not changing it", not "clear it". Saying so out loud
                 prevents someone wiping the token by editing only the URL. --}}
            <input id="control_token" name="control_token" type="password" autocomplete="off"
                   placeholder="{{ $tokenPrefix ? 'Leave blank to keep the current token (' . $tokenPrefix . '…)' : 'cx_demo_xxxxxxxx.xxxxxxxx…' }}"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;
                          font-family:'JetBrains Mono', ui-monospace, monospace;">
            <p style="margin-top:5px; font-size:11px; color:var(--text-secondary, #6b7280); line-height:1.5;">
                @if ($tokenPrefix)
                    A token is already saved. Leave this blank to keep it — paste a new one only if you
                    have replaced it on CoreX.
                @else
                    Issue this on CoreX: <strong>Dev Settings → Demo Access → Demo connection → Issue token</strong>.
                    It is shown once.
                @endif
            </p>
        </div>

        <button type="submit"
                style="padding:10px 16px; border-radius:6px; border:none;
                       background:var(--brand-button, #0ea5e9); color:#ffffff;
                       font-size:14px; font-weight:600; cursor:pointer;">
            Save connection
        </button>
    </form>

    <form method="POST" action="{{ route('admin.demo-connection.test') }}">
        @csrf
        <button type="submit"
                style="padding:10px 16px; border-radius:6px; cursor:pointer;
                       border:1px solid var(--border, rgba(0,0,0,0.14));
                       background:var(--surface, #ffffff);
                       color:var(--text-primary, #111827); font-size:14px; font-weight:600;">
            Test connection
        </button>
    </form>

    <p style="margin-top:20px; font-size:12px; color:var(--text-secondary, #6b7280); line-height:1.6;">
        You can always reach this page, even when the connection is broken — System Owners sign in
        with a password and bypass the demo gate. So a bad token can never lock you out of fixing it.
    </p>
</div>
@endsection
