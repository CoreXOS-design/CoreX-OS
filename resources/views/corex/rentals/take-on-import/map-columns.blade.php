@extends('layouts.corex')

{{--
    .ai/specs/rental-takeon-import.md §11 (Landing 2) — map an arbitrary
    CRM export's own columns onto the take-on template's fields. Nothing is
    parsed into a row until this form is submitted. No Alpine on this page
    (matches Landing 1's own choice) — the save-mapping name field is a
    plain checkbox-toggle via vanilla JS.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Map your columns — {{ $run->source_filename }}</h1>
        <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary text-xs">Back to batches</a>
    </div>

    @if ($errors->any())
        <div class="ds-alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-xs text-muted">
        This file's own column headings didn't match our template exactly, so match each field below to
        one of this file's columns. Required fields are marked with *. Fields left unmapped are simply
        not captured for this import.
    </p>

    @if ($savedMappings->isNotEmpty())
    <div class="corex-card p-4">
        <label class="prop-label">Load a saved mapping</label>
        <select onchange="if(this.value) window.location.href = this.value;" class="prop-select text-xs">
            <option value="">-- choose a saved mapping --</option>
            @foreach ($savedMappings as $saved)
                <option value="{{ route('corex.rentals.take-on-import.map-columns', ['run' => $run->id, 'load_mapping' => $saved->id]) }}" @selected($loadedMappingName === $saved->name)>{{ $saved->name }}</option>
            @endforeach
        </select>
        @if ($loadedMappingName)
            <p class="text-xs text-muted mt-1">Loaded "{{ $loadedMappingName }}" — fields it couldn't match in this file are left for you to pick below.</p>
        @endif
    </div>
    @endif

    <form method="POST" action="{{ route('corex.rentals.take-on-import.confirm-mapping', $run) }}">
        @csrf
        <div class="corex-card p-4">
            <div class="overflow-x-auto">
            <table class="ds-table w-full text-xs">
                <thead>
                    <tr>
                        <th>Template field</th>
                        <th>This file's column</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        <tr>
                            <td>{{ $field['required'] ? '*' : '' }}{{ $field['label'] }}</td>
                            <td>
                                <select name="column[{{ $field['key'] }}]" class="prop-select text-xs">
                                    <option value="">-- not mapped --</option>
                                    @foreach ($headers as $i => $header)
                                        <option value="{{ $i }}" @selected(($suggested[$field['key']] ?? null) === $i)>{{ $header !== '' ? $header : '(column ' . ($i + 1) . ')' }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="corex-card p-4 mt-4">
            <label class="flex items-center gap-2 text-xs">
                <input type="checkbox" id="rtoi-save-mapping-toggle" name="save_mapping" value="1" onchange="document.getElementById('rtoi-mapping-name-row').style.display = this.checked ? 'block' : 'none';">
                Save this mapping for reuse next time
            </label>
            <div id="rtoi-mapping-name-row" style="display:none;" class="mt-2">
                <label class="prop-label">Mapping name</label>
                <input type="text" name="mapping_name" placeholder="e.g. PropertyFile export" class="prop-input text-xs" value="{{ old('mapping_name', $loadedMappingName) }}">
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="corex-btn-primary text-xs">Continue to preview</button>
        </div>
    </form>
</div>
@endsection
