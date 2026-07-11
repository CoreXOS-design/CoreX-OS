{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    The clickwrap. Spec: .ai/specs/demo-access-control.md §6.2 step 9, §4.1

    $tnc['body'] is the text of an IMMUTABLE DemoTncVersion row. Accepting records
    a DemoTncAcceptance pointing at THAT version — so the acceptance remains
    evidence of what was actually agreed to, forever, even after v2 is published.

    The version number is shown deliberately: a prospect (and later, a lawyer)
    should be able to see exactly which text was on screen.
--}}
<x-guest-layout>
    <div style="margin-bottom:18px;">
        <h1 style="font-size:18px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
            Demo Terms &amp; Conditions
        </h1>
        <p style="font-size:12px; color:var(--text-secondary, #6b7280);">
            Version {{ $tnc['current_version'] }}
            @if (!empty($grant['company_name']))
                · {{ $grant['company_name'] }}
            @endif
        </p>
    </div>

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:14px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Scrollable, but the accept button is NOT gated on scrolling to the bottom.
         A forced-scroll gate is theatre: it proves the mouse moved, not that anyone
         read. The evidence that matters is the immutable version pointer on the
         acceptance row. --}}
    <div style="max-height:340px; overflow-y:auto; padding:14px;
                margin-bottom:18px; border-radius:6px;
                border:1px solid var(--border, rgba(0,0,0,0.14));
                background:var(--surface-2, #f0f2f8);
                color:var(--text-primary, #111827);
                font-size:13px; line-height:1.65; white-space:pre-wrap;">{{ $tnc['body'] }}</div>

    <form method="POST" action="{{ route('demo.tnc.accept') }}">
        @csrf

        <label style="display:flex; gap:9px; align-items:flex-start; margin-bottom:18px;
                      font-size:13px; line-height:1.5; cursor:pointer;
                      color:var(--text-primary, #111827);">
            <input type="checkbox" name="accept" value="1" required
                   style="margin-top:2px; flex:0 0 auto;">
            <span>I have read and accept these terms on behalf of
                <strong>{{ $grant['company_name'] ?? 'my company' }}</strong>.</span>
        </label>

        <button type="submit"
                style="width:100%; padding:10px 14px; border-radius:6px; border:none;
                       background:var(--brand-button, #0ea5e9); color:#ffffff;
                       font-size:14px; font-weight:600; cursor:pointer;">
            Accept &amp; continue
        </button>
    </form>

    <form method="POST" action="{{ route('demo.gate.logout') }}" style="margin-top:12px;">
        @csrf
        <button type="submit"
                style="width:100%; padding:8px; border-radius:6px;
                       border:1px solid var(--border, rgba(0,0,0,0.14));
                       background:transparent; color:var(--text-secondary, #6b7280);
                       font-size:12px; cursor:pointer;">
            Decline and sign out
        </button>
    </form>
</x-guest-layout>
