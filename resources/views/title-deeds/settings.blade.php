@extends('layouts.corex')

@section('corex-content')
@php
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $toggles = [
        'pin_required' => 'Collection PIN',
        'signature_required' => 'Collector signs on screen',
        'representatives_allowed' => 'Representatives may collect',
        'phone_consent_allowed' => 'Allow consent by phone call',
        'authority_letter_required' => 'Authority letter for a representative without the PIN',
        'collector_id_photo_required' => 'Photo of the collector\'s ID',
    ];
    $numbers = [
        'pin_lock_attempts' => 'Wrong PIN attempts before the PIN locks',
        'consent_validity_minutes' => 'Owner confirmation lasts (minutes)',
        'reminder_days' => 'Remind owners every (days)',
        'max_reminders' => 'Most reminders per deed (0 = none)',
        'contact_changed_block_days' => 'Extra check after an owner\'s email or phone changed (days, 0 = off)',
    ];
@endphp
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Title Deed settings</h1>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @if($noAgency)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">Switch into an agency to change its settings.</div>
    @else
    <form method="POST" action="{{ route('corex.settings.title-deeds.update') }}" class="rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @foreach($toggles as $key => $label)
        <div class="flex items-center gap-3">
            <input type="hidden" name="{{ $key }}" value="0">
            <input id="s-{{ $key }}" type="checkbox" name="{{ $key }}" value="1" @checked($values[$key])>
            <label for="s-{{ $key }}" class="text-sm font-medium" style="color: var(--text-primary);">{{ $label }}</label>
        </div>
        @endforeach

        <div>
            <label for="s-rep_without_pin_mode" class="block text-sm font-medium mb-1" style="color: var(--text-primary);">A representative who does not have the PIN</label>
            <select id="s-rep_without_pin_mode" name="rep_without_pin_mode" class="{{ $input }}" style="{{ $inputStyle }}">
                @foreach($repModes as $key => $label)<option value="{{ $key }}" @selected($values['rep_without_pin_mode'] === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($numbers as $key => $label)
            <div>
                <label for="s-{{ $key }}" class="block text-sm font-medium mb-1" style="color: var(--text-primary);">{{ $label }}</label>
                <input id="s-{{ $key }}" type="number" name="{{ $key }}" min="{{ $ranges[$key][0] }}" max="{{ $ranges[$key][1] }}" step="1" required value="{{ old($key, $values[$key]) }}" class="w-28 {{ $input }}" style="{{ $inputStyle }}">
            </div>
            @endforeach
        </div>

        <button type="submit" class="corex-btn-primary">Save</button>
    </form>
    @endif
</div>
@endsection
