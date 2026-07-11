{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Edit a demo grant — notes + CRM link ONLY. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Edit grant')

@section('corex-content')
<div style="padding:24px; max-width:680px; margin:0 auto;">

    <a href="{{ route('admin.demo-access.show', $grant) }}"
       style="font-size:12px; color:var(--text-secondary, #6b7280); text-decoration:none;">← Back to grant</a>

    <h1 style="font-size:20px; font-weight:700; color:var(--text-primary, #111827); margin:8px 0 4px;">
        Edit {{ $grant->company_name }}
    </h1>

    {{-- No Silent Locks (STANDARDS): the two things you CANNOT edit here are named,
         with the reason and the way forward — rather than simply being absent and
         leaving someone hunting for them. --}}
    <div style="margin:12px 0 20px; padding:12px; border-radius:6px;
                background:var(--surface-2, #f0f2f8);
                border:1px solid var(--border, rgba(0,0,0,0.14));
                font-size:12px; line-height:1.6; color:var(--text-secondary, #6b7280);">
        <strong style="color:var(--text-primary, #111827);">Access length and access code cannot be changed.</strong><br>
        The length ({{ $grant->expiry_hours }} hours) was fixed when the grant was issued —
        editing it would move a deadline the prospect was already told. The code is stored
        only as a hash, so there is nothing to reveal or re-send.<br>
        For either, <a href="{{ route('admin.demo-access.create') }}" style="color:var(--brand-icon, #0ea5e9);">issue a new grant</a>.
    </div>

    @if ($errors->any())
        <div role="alert"
             style="margin-bottom:16px; padding:10px 12px; border-radius:6px;
                    background:var(--surface-2, #f0f2f8);
                    border:1px solid var(--ds-crimson, #dc2626);
                    color:var(--text-primary, #111827); font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.demo-access.update', $grant) }}">
        @csrf @method('PUT')

        <div style="margin-bottom:14px;">
            <label for="contact_name" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Contact name
            </label>
            <input id="contact_name" name="contact_name" type="text"
                   value="{{ old('contact_name', $grant->contact_name) }}"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
        </div>

        <div style="margin-bottom:14px;">
            <label for="contact_id" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Linked contact (CRM)
            </label>
            <input id="contact_id" name="contact_id" type="number" min="1"
                   value="{{ old('contact_id', $grant->contact_id) }}"
                   placeholder="Contact ID"
                   style="width:100%; padding:9px 11px; border-radius:6px;
                          border:1px solid var(--border, rgba(0,0,0,0.14));
                          background:var(--surface, #ffffff);
                          color:var(--text-primary, #111827); font-size:14px;">
            <p style="margin-top:5px; font-size:11px; color:var(--text-secondary, #6b7280);">
                Optional. Links this prospect to a Contact record (the Contact pillar).
            </p>
        </div>

        <div style="margin-bottom:20px;">
            <label for="notes" style="display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:var(--text-secondary, #6b7280);">
                Notes
            </label>
            <textarea id="notes" name="notes" rows="4"
                      style="width:100%; padding:9px 11px; border-radius:6px;
                             border:1px solid var(--border, rgba(0,0,0,0.14));
                             background:var(--surface, #ffffff);
                             color:var(--text-primary, #111827); font-size:14px;
                             font-family:inherit; resize:vertical;">{{ old('notes', $grant->notes) }}</textarea>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit"
                    style="padding:10px 16px; border-radius:6px; border:none;
                           background:var(--brand-button, #0ea5e9); color:#ffffff;
                           font-size:14px; font-weight:600; cursor:pointer;">
                Save
            </button>
            <a href="{{ route('admin.demo-access.show', $grant) }}"
               style="padding:10px 16px; border-radius:6px; text-decoration:none;
                      border:1px solid var(--border, rgba(0,0,0,0.14));
                      color:var(--text-primary, #111827); font-size:14px;">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
