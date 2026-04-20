@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="RMCP Variables" :back-route="route('compliance.rmcp.index')" back-label="RMCP" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="mb-4 text-sm" style="color:#64748b;">
            These variables are substituted into every RMCP section. Agency fields are pulled from Settings. Compliance Officer fields are pulled from the current appointed officer. Only <strong>Manual</strong> values can be edited here.
        </div>

        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Variable Key</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Source</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Current Value</th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($variableList as $var)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);" x-data="{ editing: false, value: '{{ e($var['value']) }}' }">
                        <td class="px-4 py-3">
                            <code class="text-xs font-mono" style="color:#0d9488;">{{ '{{' . $var['key'] . '}}' }}</code>
                        </td>
                        <td class="px-4 py-3">
                            @if($var['source'] === 'agency_column')
                            <span class="text-xs px-2 py-0.5" style="background:rgba(59,130,246,0.1); color:#3b82f6; border-radius:3px;">Agency</span>
                            @elseif($var['source'] === 'compliance_officer_column')
                            <span class="text-xs px-2 py-0.5" style="background:rgba(168,85,247,0.1); color:#a855f7; border-radius:3px;">Compliance Officer</span>
                            @elseif($var['source'] === 'computed')
                            <span class="text-xs px-2 py-0.5" style="background:rgba(148,163,184,0.1); color:#94a3b8; border-radius:3px;">Computed</span>
                            @else
                            <span class="text-xs px-2 py-0.5" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Manual</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-primary, #1f2937);">
                            <template x-if="!editing">
                                <span x-text="value || '(empty)'" :style="value ? '' : 'color:#94a3b8; font-style:italic;'"></span>
                            </template>
                            <template x-if="editing">
                                <input type="text" x-model="value" class="w-full px-2 py-1 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                            </template>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($var['editable'] && $var['db_id'])
                            <template x-if="!editing">
                                <button @click="editing = true" class="text-xs font-semibold" style="color:#3b82f6;">Edit</button>
                            </template>
                            <template x-if="editing">
                                <button @click="
                                    fetch('{{ route('compliance.rmcp.variables.update', $var['db_id']) }}', {
                                        method: 'PATCH',
                                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                        body: JSON.stringify({ value: value })
                                    }).then(() => { editing = false; });
                                " class="text-xs font-semibold" style="color:#00d4aa;">Save</button>
                            </template>
                            @else
                            <span class="text-xs" style="color:#94a3b8;">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
