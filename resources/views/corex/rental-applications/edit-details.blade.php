{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-516 - the agent corrects what the applicant typed. Saves to the current values and the change log; the applicant's
     signed submission (the sealed generation) is never touched, and the applicant is emailed what changed. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full" style="max-width: 56rem; margin: 0 auto; padding: 1rem;">

    <div class="flex items-center justify-between mb-3">
        <div>
            <h1 class="text-sm font-bold" style="color: var(--text-primary);">
                Correct details — {{ $rentalApplication->full_name ?: $rentalApplication->contact?->full_name }}
            </h1>
            <p class="text-xs" style="color: var(--text-muted);">
                Change anything the applicant typed. What they originally signed stays on file untouched; they are emailed what you change.
            </p>
        </div>
        <a href="{{ route('corex.rental-applications.review', $rentalApplication) }}" class="text-xs underline" style="color: var(--ds-blue, #2563eb);">Back to review</a>
    </div>

    @if($errors->any())
        <div class="p-3 rounded-md text-xs mb-3" style="background: var(--surface); border: 1px solid var(--ds-red, #dc2626); color: var(--ds-red, #dc2626);" data-qa="edit-details-errors">
            @foreach($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-applications.review.details.update', $rentalApplication) }}" data-qa="edit-details-form">
        @csrf

        @foreach($sections as $section => $fields)
            <div class="rounded-lg p-4 mb-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">{{ $section }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-3">
                    @foreach($fields as $field)
                        @php
                            $name = 'f[' . $field['path'] . ']';
                            // PHP form arrays keep dots in the key, but old() resolves dots as nesting - read it from the raw old input.
                            $oldInput = session()->getOldInput('f', []);
                            $value = array_key_exists($field['path'], $oldInput) ? $oldInput[$field['path']] : $field['value'];
                            $wide = $field['type'] === 'textarea';
                        @endphp
                        <label class="block text-xs {{ $wide ? 'md:col-span-2' : '' }}" style="color: var(--text-secondary);">
                            <span class="block mb-1">{{ $field['label'] }}</span>
                            @if($field['type'] === 'textarea')
                                <textarea name="{{ $name }}" rows="3" class="corex-input text-xs w-full" data-field="{{ $field['path'] }}">{{ $value }}</textarea>
                            @elseif($field['type'] === 'select')
                                <select name="{{ $name }}" class="corex-input text-xs w-full" data-field="{{ $field['path'] }}">
                                    <option value="">—</option>
                                    @foreach($field['options'] as $optValue => $optLabel)
                                        <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif($field['type'] === 'bool')
                                <select name="{{ $name }}" class="corex-input text-xs w-full" data-field="{{ $field['path'] }}">
                                    <option value="">—</option>
                                    <option value="1" @selected((string) $value === '1')>Yes</option>
                                    <option value="0" @selected((string) $value === '0')>No</option>
                                </select>
                            @else
                                <input type="{{ ['date' => 'date', 'email' => 'email'][$field['type']] ?? (in_array($field['type'], ['money', 'number'], true) ? 'number' : 'text') }}"
                                       @if($field['step']) step="{{ $field['step'] }}" @endif
                                       name="{{ $name }}" value="{{ $value }}" class="corex-input text-xs w-full" data-field="{{ $field['path'] }}">
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="rounded-lg p-4 mb-3" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs" style="color: var(--text-secondary);">
                <span class="block mb-1">Why you are correcting this (optional — kept in the change log)</span>
                <textarea name="note" rows="2" maxlength="1000" class="corex-input text-xs w-full" placeholder="e.g. ID number mistyped; confirmed with the applicant by phone">{{ old('note') }}</textarea>
            </label>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('corex.rental-applications.review', $rentalApplication) }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs" data-qa="edit-details-save">Save and email the applicant</button>
        </div>
    </form>
</div>
@endsection
