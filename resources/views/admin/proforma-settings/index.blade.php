{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')
@section('title', 'Proforma Invoice Settings')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header — §2.4 Pattern A (branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Proforma Invoices</h1>
                <p class="text-sm text-white/60">Numbering, due dates and banking for proforma invoices. Letterhead, logo and VAT number are pulled from your company branding.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.company-settings') }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.03 7.03 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    Company Branding
                </a>
            </div>
        </div>
    </div>

    {{-- Alerts — §3.9 --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if(session('error') || $errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">{{ session('error') ?: $errors->first() }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.proforma-settings.update') }}" class="space-y-5">
        @csrf @method('PUT')

        {{-- Numbering --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Numbering</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="number_prefix" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Prefix</label>
                    <input id="number_prefix" name="number_prefix" type="text" maxlength="16"
                           value="{{ old('number_prefix', $settings->number_prefix) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <x-input-error :messages="$errors->get('number_prefix')" class="mt-1" />
                </div>

                <div>
                    <label for="number_padding" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Zero-padding</label>
                    <input id="number_padding" name="number_padding" type="number" min="1" max="10"
                           value="{{ old('number_padding', $settings->number_padding) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <x-input-error :messages="$errors->get('number_padding')" class="mt-1" />
                </div>

                <div>
                    <label for="start_number" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Start number (advance only)</label>
                    <input id="start_number" name="start_number" type="number" min="{{ $settings->next_number }}"
                           placeholder="next: {{ number_format($settings->next_number) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <x-input-error :messages="$errors->get('start_number')" class="mt-1" />
                </div>
            </div>

            <p class="mt-3 text-xs" style="color: var(--text-muted);">
                Next number will be <strong style="color: var(--text-primary);">{{ $settings->formatNumber($settings->next_number) }}</strong>.
                The sequence never reuses a number — the start number can only be advanced forward.
            </p>
        </div>

        {{-- Due date --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Due date</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="due_date_rule" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Rule</label>
                    <select id="due_date_rule" name="due_date_rule"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="end_of_month" @selected(old('due_date_rule', $settings->due_date_rule) === 'end_of_month')>End of current month</option>
                        <option value="days_after" @selected(old('due_date_rule', $settings->due_date_rule) === 'days_after')>N days after issue</option>
                        <option value="on_receipt" @selected(old('due_date_rule', $settings->due_date_rule) === 'on_receipt')>On receipt</option>
                    </select>
                    <x-input-error :messages="$errors->get('due_date_rule')" class="mt-1" />
                </div>

                <div>
                    <label for="due_days" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Days (for "N days after")</label>
                    <input id="due_days" name="due_days" type="number" min="0" max="365"
                           value="{{ old('due_days', $settings->due_days) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <x-input-error :messages="$errors->get('due_days')" class="mt-1" />
                </div>
            </div>
        </div>

        {{-- Banking details --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">Banking details</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Shown as notes on every proforma invoice.</p>

            <label for="bank_details" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Bank details</label>
            <textarea id="bank_details" name="bank_details" rows="4"
                      placeholder="Bank, Account name, Account no, Branch code, Reference"
                      class="w-full rounded-md px-3 py-2 text-sm"
                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ old('bank_details', $settings->bank_details) }}</textarea>
            <x-input-error :messages="$errors->get('bank_details')" class="mt-1" />
        </div>

        {{-- Read-only, sourced from company branding --}}
        <div class="rounded-md p-5" style="background: var(--surface-2); border: 1px solid var(--border);">
            <h2 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">From company branding</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Read-only here — edit these on the Company Settings page.</p>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Company</dt>
                    <dd class="text-sm font-semibold" style="color: var(--text-primary);">{{ $agency->trading_name ?: $agency->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium mb-1" style="color: var(--text-secondary);">VAT</dt>
                    <dd class="text-sm">
                        @if($agency->vat_registered)
                            <span class="ds-badge ds-badge-success">Registered</span>
                            @if($agency->vat_no)
                                <span class="ml-2 font-semibold" style="color: var(--text-primary);">{{ $agency->vat_no }}</span>
                            @endif
                        @else
                            <span class="ds-badge ds-badge-default">Not registered</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <a href="{{ route('admin.company-settings') }}" class="inline-flex items-center gap-1 mt-4 text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">
                Edit company branding
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
            </a>
        </div>

        {{-- Save --}}
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary">Save proforma settings</button>
        </div>
    </form>
</div>
@endsection
