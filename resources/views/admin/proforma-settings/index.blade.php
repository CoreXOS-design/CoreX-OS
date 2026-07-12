@extends('layouts.corex')
@section('title', 'Proforma Invoice Settings')

@section('corex-content')
<div style="max-width: 720px; margin: 0 auto; padding: 1rem;">
    <h1 style="font-size:1.4rem;font-weight:800;color:var(--brand-default,#0b2a4a);margin-bottom:.25rem;">Proforma Invoices</h1>
    <p style="color:var(--text-muted,#6b7280);font-size:.9rem;margin-bottom:1rem;">Numbering, due dates and banking for proforma invoices. Letterhead, logo and VAT number are pulled from your company branding.</p>

    @if(session('success'))<div class="corex-alert corex-alert-success" style="margin:1rem 0;">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('admin.proforma-settings.update') }}">
        @csrf @method('PUT')

        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.75rem;">Numbering</h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <label style="flex:1 1 180px;">Prefix
                    <input name="number_prefix" value="{{ old('number_prefix', $settings->number_prefix) }}" maxlength="16" class="corex-input" style="width:100%;">
                </label>
                <label style="flex:1 1 140px;">Zero-padding
                    <input name="number_padding" type="number" min="1" max="10" value="{{ old('number_padding', $settings->number_padding) }}" class="corex-input" style="width:100%;">
                </label>
                <label style="flex:1 1 180px;">Start number (advance only)
                    <input name="start_number" type="number" min="{{ $settings->next_number }}" placeholder="next: {{ $settings->next_number }}" class="corex-input" style="width:100%;">
                </label>
            </div>
            <p style="color:#9ca3af;font-size:.78rem;margin-top:.5rem;">Next number will be <strong>{{ $settings->formatNumber($settings->next_number) }}</strong>. The sequence never reuses a number — the start number can only be advanced forward.</p>
        </div>

        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.75rem;">Due date</h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end;">
                <label style="flex:1 1 240px;">Rule
                    <select name="due_date_rule" class="corex-input" style="width:100%;">
                        <option value="end_of_month" @selected(old('due_date_rule',$settings->due_date_rule)==='end_of_month')>End of current month</option>
                        <option value="days_after" @selected(old('due_date_rule',$settings->due_date_rule)==='days_after')>N days after issue</option>
                        <option value="on_receipt" @selected(old('due_date_rule',$settings->due_date_rule)==='on_receipt')>On receipt</option>
                    </select>
                </label>
                <label style="flex:1 1 140px;">Days (for "N days after")
                    <input name="due_days" type="number" min="0" max="365" value="{{ old('due_days', $settings->due_days) }}" class="corex-input" style="width:100%;">
                </label>
            </div>
        </div>

        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.75rem;">Banking details (shown as invoice notes)</h3>
            <textarea name="bank_details" rows="4" class="corex-input" style="width:100%;" placeholder="Bank, Account name, Account no, Branch code, Reference">{{ old('bank_details', $settings->bank_details) }}</textarea>
        </div>

        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;background:#f9fafb;">
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">From branding (read-only)</h3>
            <div style="font-size:.85rem;color:#374151;">
                <div>Company: <strong>{{ $agency->trading_name ?: $agency->name }}</strong></div>
                <div>VAT: <strong>{{ $agency->vat_registered ? ($agency->vat_no ?: 'registered') : 'Not registered' }}</strong></div>
                <a href="{{ route('admin.company-settings') }}" style="font-size:.8rem;">Edit company branding →</a>
            </div>
        </div>

        <button class="corex-btn-primary">Save proforma settings</button>
    </form>
</div>
@endsection
