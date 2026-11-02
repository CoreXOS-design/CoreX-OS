@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — match the file's columns to lead fields (.ai/specs/campaigns.md §5.1, Rentals Take-On map-columns pattern). --}}
<div class="w-full h-full flex flex-col">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex items-center justify-between gap-3">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Import into {{ $list->name }} — {{ $pending['filename'] }}</h1>
        <a href="{{ route('admin.campaigns.lists.show', $list->id) }}" class="corex-btn-outline text-xs">Cancel</a>
    </div>

    @include('admin.campaigns._head')

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3">
        <form method="POST" action="{{ route('admin.campaigns.lists.import.run', $list->id) }}" class="cmp-panel p-4 max-w-2xl"
              x-data="{ email: '{{ $suggested['email'] ?? '' }}' }" data-map-columns>
            @csrf
            <input type="hidden" name="token" value="{{ $pending['token'] }}">
            <table class="w-full">
                @foreach($fields as $key => [$label])
                    <tr class="cmp-row">
                        <td class="cmp-td font-medium" style="width: 35%;">{{ $label }}@if($key === 'email') <span style="color: var(--ds-crimson);">*</span>@endif</td>
                        <td class="cmp-td">
                            <select name="column[{{ $key }}]" class="cmp-input" @if($key === 'email') x-model="email" @endif>
                                <option value="">— not in this file —</option>
                                @foreach($pending['headers'] as $i => $h)
                                    <option value="{{ $i }}" @selected(($suggested[$key] ?? null) === $i)>{{ $h !== '' ? $h : 'Column ' . ($i + 1) }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
            </table>
            <div class="flex justify-end mt-3">
                <button class="corex-btn-primary text-xs" :disabled="email === ''">Import</button>
            </div>
        </form>
    </div>
</div>
@endsection
