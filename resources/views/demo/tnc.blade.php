{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    The clickwrap. Spec: .ai/specs/demo-access-control.md §6.2 step 9, §4.1

    $tnc['body'] is the text of an IMMUTABLE DemoTncVersion row. Accepting records
    a DemoTncAcceptance pointing at THAT version — so the acceptance remains
    evidence of what was actually agreed to, forever, even after v2 is published.

    The version number is shown deliberately: a prospect (and later, a lawyer)
    should be able to see exactly which text was on screen.
--}}
{{-- A legal document is not a login form. The default guest card is 400px wide with
     a 340px scroll window and a "Sign in to your account" heading — which squeezed
     the terms into a letterbox and mistitled the page. Ask for a card that can
     actually hold a document, and suppress the heading: this page has its own <h1>.
     :heading="null" — not heading="" — so it is a real null, not a falsy string. --}}
<x-guest-layout max-width="820px" :heading="null">
    <div style="margin-bottom:18px;">
        <h1 style="font-size:22px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
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
    {{-- Height tracks the viewport rather than a fixed 340px: on a laptop the terms
         now fill the screen instead of a letterbox, and the clamp keeps the accept
         button above the fold on a short window and stops the box collapsing on a
         phone. --}}
    <div style="height:clamp(320px, 55vh, 620px); overflow-y:auto; padding:20px 22px;
                margin-bottom:20px; border-radius:6px;
                border:1px solid var(--border, rgba(0,0,0,0.14));
                background:var(--surface-2, #f0f2f8);
                color:var(--text-primary, #111827);
                font-size:15px; line-height:1.7; white-space:pre-wrap;">{{ $tnc['body'] }}</div>

    <form method="POST" action="{{ route('demo.tnc.accept') }}" id="tnc-accept">
        @csrf

        <label style="display:flex; gap:10px; align-items:flex-start; margin-bottom:18px;
                      font-size:14px; line-height:1.55; cursor:pointer;
                      color:var(--text-primary, #111827);">
            <input type="checkbox" name="accept" value="1" required
                   style="margin-top:3px; flex:0 0 auto;">
            <span>I have read and accept these terms on behalf of
                <strong>{{ $grant['company_name'] ?? 'my company' }}</strong>.</span>
        </label>
    </form>

    {{-- Accept and Decline are two DIFFERENT endpoints, so they must stay two forms.
         Laid out as one row (form= links the button back to its form) — a pair of
         stacked full-width buttons reads fine at 400px and clumsy at 820px. Wraps to
         stacked on a narrow screen. --}}
    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
        <button type="submit" form="tnc-accept"
                style="flex:1 1 220px; padding:11px 18px; border-radius:6px; border:none;
                       background:var(--brand-button, #0ea5e9); color:#ffffff;
                       font-size:14px; font-weight:600; cursor:pointer;">
            Accept &amp; continue
        </button>

        <form method="POST" action="{{ route('demo.gate.logout') }}" style="flex:0 1 auto; margin:0;">
            @csrf
            <button type="submit"
                    style="padding:11px 18px; border-radius:6px;
                           border:1px solid var(--border, rgba(0,0,0,0.14));
                           background:transparent; color:var(--text-secondary, #6b7280);
                           font-size:13px; cursor:pointer;">
                Decline and sign out
            </button>
        </form>
    </div>
</x-guest-layout>
