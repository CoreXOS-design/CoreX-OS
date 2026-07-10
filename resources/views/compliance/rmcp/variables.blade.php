{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="RMCP Variables" :back-route="route('compliance.rmcp.index')" back-label="RMCP" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="mb-4 text-sm" style="color:var(--text-secondary);">
            These variables are substituted into every RMCP section. Agency fields are pulled from Settings. Compliance Officer fields are pulled from the current appointed officer. Only <strong>Manual</strong> values can be edited here.
        </div>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Variable Key</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Source</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Current Value</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($variableList as $var)
                        <tr style="border-top: 1px solid var(--border);" x-data="{ editing: false, value: '{{ e($var['value']) }}' }">
                            <td class="px-4 py-3">
                                {{-- Render literal mustache braces around the key. The delimiters are split ('{'.'{' / '}'.'}') so no literal }} sits inside this echo — a literal }} makes Blade's non-greedy {{ }} regex close the echo early and emit invalid PHP (AT-182 compile class). --}}
                                <code class="text-xs font-mono" style="color: var(--brand-icon);">{{ '{' . '{' . $var['key'] . '}' . '}' }}</code>
                            </td>
                            <td class="px-4 py-3">
                                @if($var['source'] === 'agency_column')
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--ds-navy, #0b2a4a) 12%, transparent); color: var(--ds-navy, #0b2a4a);">Agency</span>
                                @elseif($var['source'] === 'compliance_officer_column')
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color: var(--ds-green, #059669);">Officer</span>
                                @elseif($var['source'] === 'computed')
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 16%, transparent); color: var(--text-muted, #9ca3af);">Computed</span>
                                @else
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">Manual</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                <template x-if="!editing">
                                    <span x-text="value || '(empty)'" :style="value ? '' : 'color: var(--text-muted); font-style: italic;'"></span>
                                </template>
                                <template x-if="editing">
                                    <input type="text" x-model="value" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </template>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($var['editable'] && $var['db_id'])
                                <template x-if="!editing">
                                    <button @click="editing = true" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</button>
                                </template>
                                <template x-if="editing">
                                    <button @click="
                                        fetch('{{ route('compliance.rmcp.variables.update', $var['db_id']) }}', {
                                            method: 'PATCH',
                                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                            body: JSON.stringify({ value: value })
                                        }).then(() => { editing = false; });
                                    " class="text-xs font-semibold" style="color: var(--brand-icon);">Save</button>
                                </template>
                                @else
                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
