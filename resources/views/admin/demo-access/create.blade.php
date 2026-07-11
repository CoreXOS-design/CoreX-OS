{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Issue a demo access grant. Owner-only.
    Spec: .ai/specs/demo-access-control.md §6.1
--}}
@extends('layouts.corex')

@section('title', 'New demo grant')

@section('corex-content')
<div style="padding:24px; max-width:680px; margin:0 auto;">

    <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin-bottom:4px;">
        New demo grant
    </h1>
    <p style="font-size:13px; color:var(--text-secondary, #6b7280); margin-bottom:20px; line-height:1.5;">
        We email an access code to this address. The clock starts when they first sign in —
        not now — so an unopened invitation loses them nothing.
    </p>

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.demo-access.store') }}">
        @csrf

        <div style="margin-bottom:14px;">
            <label for="company_name" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Company name <span style="color:var(--ds-crimson, #dc2626);">*</span>
            </label>
            <input id="company_name" name="company_name" type="text" required
                   value="{{ old('company_name') }}"
                   placeholder="e.g. Seaside Realty (Pty) Ltd"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
            <p style="margin-top:5px; font-size:11px; color:var(--text-secondary, #6b7280);">
                Shown in the watermark on every page they view.
            </p>
        </div>

        <div style="margin-bottom:14px;">
            <label for="contact_email" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Email address <span style="color:var(--ds-crimson, #dc2626);">*</span>
            </label>
            <input id="contact_email" name="contact_email" type="email" required
                   value="{{ old('contact_email') }}"
                   placeholder="thabo@seasiderealty.co.za"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
        </div>

        <div style="margin-bottom:14px;">
            <label for="contact_name" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Contact name
            </label>
            <input id="contact_name" name="contact_name" type="text"
                   value="{{ old('contact_name') }}"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
        </div>

        <div style="margin-bottom:14px;">
            <label for="expiry_hours" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Access length (hours)
            </label>
            <input id="expiry_hours" name="expiry_hours" type="number" min="1" max="8760"
                   value="{{ old('expiry_hours', $defaultExpiryHours) }}"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
            <p style="margin-top:5px; font-size:11px; color:var(--text-secondary, #6b7280);">
                Counted from their first sign-in. This value is fixed onto the grant now —
                changing the default later will not shorten a demo you've already promised.
            </p>
        </div>

        <div style="margin-bottom:20px;">
            <label for="notes" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Notes
            </label>
            <textarea id="notes" name="notes" rows="3"
                      placeholder="Context for the sales team — who introduced them, what they care about."
                      style="width:100%; padding:9px 11px; border-radius:6px;
                             border:1px solid var(--border, rgba(0,0,0,0.14));
                             background:var(--surface, #ffffff);
                             color:var(--text-primary, #111827); font-size:14px;
                             font-family:inherit; resize:vertical;">{{ old('notes') }}</textarea>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit"
                    style="padding:10px 16px; border-radius:6px; border:none;
                           background:var(--brand-button, #0ea5e9); color:#ffffff;
                           font-size:14px; font-weight:600; cursor:pointer;">
                Issue grant &amp; send invitation
            </button>
            <a href="{{ route('admin.demo-access.index') }}"
               style="padding:10px 16px; border-radius:6px; text-decoration:none;
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      color:var(--text-primary, #111827); font-size:14px;">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
