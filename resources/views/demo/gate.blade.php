{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    The demo sign-in gate. Spec: .ai/specs/demo-access-control.md §6.2

    A prospect arrives here with nothing but the email + access code we mailed
    them. This is the front door of demo1.corexos.co.za.

    Colours use the var(--token, #fallback) pattern only — no naked hex.
--}}
<x-guest-layout>
    <div style="margin-bottom:24px; text-align:center;">
        <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:6px;">
            CoreX OS — Demo
        </h1>
        <p style="font-size:13px; color:var(--text-secondary, #6b7280); line-height:1.5;">
            Sign in with the email address and access code from your invitation.
        </p>
    </div>

    {{-- Why they were bounced: expired, revoked, session ended, or our own outage.
         Never silently drop someone at a login box with no explanation. --}}
    @if (session('demo_gate_message'))
        <div role="status"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--border, rgba(0,0,0,0.14));
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.5;">
            {{ session('demo_gate_message') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px; line-height:1.5;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('demo.gate.verify') }}">
        @csrf

        <div style="margin-bottom:14px;">
            <label for="email"
                   style="display:block; margin-bottom:6px; font-size:12px; font-weight:600;
                          color:var(--text-secondary, #6b7280);">
                Email address
            </label>
            <input id="email" name="email" type="email" required autofocus
                   value="{{ old('email') }}"
                   autocomplete="email"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
        </div>

        <div style="margin-bottom:20px;">
            <label for="code"
                   style="display:block; margin-bottom:6px; font-size:12px; font-weight:600;
                          color:var(--text-secondary, #6b7280);">
                Access code
            </label>
            {{-- Monospace + letter-spacing: they are copying a 16-char code off an
                 email by eye. autocapitalize on, because the code is uppercase —
                 the server normalises anyway, but the field should not fight them. --}}
            <input id="code" name="code" type="text" required
                   placeholder="XXXX-XXXX-XXXX-XXXX"
                   autocomplete="one-time-code" autocapitalize="characters" spellcheck="false"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827);
                          font-family:'JetBrains Mono', ui-monospace, monospace;
                          font-size:14px; letter-spacing:0.08em;">
        </div>

        <button type="submit"
                style="width:100%; padding:10px 14px; border-radius:6px; border:none;
                       background:var(--brand-button, #0ea5e9); color:#ffffff;
                       font-size:14px; font-weight:600; cursor:pointer;
                       transition:opacity 300ms;">
            Enter the demo
        </button>
    </form>

    <p style="margin-top:20px; text-align:center; font-size:12px;
              color:var(--text-secondary, #6b7280); line-height:1.5;">
        Don't have an access code, or has yours expired?<br>
        Contact us and we'll send you a new invitation.
    </p>
</x-guest-layout>
