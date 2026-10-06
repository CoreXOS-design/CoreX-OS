{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Template Packages</h1>
        <p class="text-xs" style="color: var(--text-muted);">Move an e-sign template between systems (for example from Staging to live) into the agency you choose. Export a template from Template Management; import it here.</p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">{{ $errors->first() }}</div>
            @if(session('error_details'))
                <ul class="list-disc pl-5 mt-1 text-xs">
                    @foreach(session('error_details') as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Upload --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Import a template package</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Choose a package file (.cxpkg), or the .zip made by "Export selected". You will see exactly what will be created, and pick the agency, before anything happens. Nothing existing is ever replaced.</p>
        <form method="POST" action="{{ route('docuperfect.template-transfer.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
            @csrf
            <input type="file" name="package" accept=".cxpkg,.zip" required class="text-xs">
            <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Upload and preview</button>
        </form>
    </div>

    {{-- Settings --}}
    <details class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <summary class="px-5 py-3 text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">Settings</summary>
        <form method="POST" action="{{ route('docuperfect.template-transfer.settings') }}" class="px-5 pb-5 grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            @foreach($fields as $field => $def)
                @php [$key, $default, $kind, $min, $max, $label, $help] = $def; $value = old($field, $settings[$field]); @endphp
                <div>
                    <label class="block text-xs font-semibold mb-1" for="ts_{{ $field }}" style="color: var(--text-secondary);">{{ $label }}</label>
                    @if($kind === 'visibility')
                        <select id="ts_{{ $field }}" name="{{ $field }}" class="list-header-filter w-full">
                            <option value="all_branches" @selected($value === 'all_branches')>Every branch of the agency</option>
                            <option value="agency_admins_only" @selected($value === 'agency_admins_only')>Agency administrators only</option>
                        </select>
                    @else
                        <input id="ts_{{ $field }}" type="text" name="{{ $field }}" value="{{ $value }}" class="list-header-filter w-full">
                    @endif
                    <p class="text-[0.6875rem] mt-1" style="color: var(--text-muted);">{{ $help }}</p>
                </div>
            @endforeach
            <div class="md:col-span-2"><button type="submit" class="corex-btn-outline text-xs px-4 py-2">Save settings</button></div>
        </form>
    </details>

    {{-- Transfer log --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('docuperfect.template-transfer.index') }}" class="flex flex-wrap items-center gap-3">
            @if(request('sort'))
                <input type="hidden" name="sort" value="{{ request('sort') }}">
                <input type="hidden" name="direction" value="{{ request('direction', 'asc') }}">
            @endif
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search template, person or checksum…" class="list-header-filter flex-1 min-w-[200px] max-w-sm">
            <select name="direction_filter" onchange="this.form.submit()" class="list-header-filter">
                <option value="">Exports and imports</option>
                <option value="export" @selected(request('direction_filter') === 'export')>Exports</option>
                <option value="import" @selected(request('direction_filter') === 'import')>Imports</option>
            </select>
            <select name="outcome" onchange="this.form.submit()" class="list-header-filter">
                <option value="">Any outcome</option>
                <option value="success" @selected(request('outcome') === 'success')>Done</option>
                <option value="rejected" @selected(request('outcome') === 'rejected')>Refused</option>
                <option value="failed" @selected(request('outcome') === 'failed')>Failed</option>
            </select>
            <label class="text-xs" style="color: var(--text-muted);">From <input type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()" class="list-header-filter"></label>
            <label class="text-xs" style="color: var(--text-muted);">To <input type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()" class="list-header-filter"></label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if($filtered)<a href="{{ route('docuperfect.template-transfer.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>@endif
            <span class="ml-auto text-xs" style="color: var(--text-muted);">{{ number_format($logs->total()) }} {{ \Illuminate\Support\Str::plural('record', $logs->total()) }}</span>
        </form>
    </div>

    @if($logs->isEmpty())
        <div class="rounded-md py-10 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            @if($filtered)
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No transfers match these filters</h3>
                <p class="text-sm" style="color: var(--text-muted);">Try a different search or clear the filters.</p>
            @else
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No transfers yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Every export and import is recorded here — who did it, when, and into which agency.</p>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <x-sort-header field="created_at" label="When" />
                        <x-sort-header field="direction" label="Export / import" />
                        <x-sort-header field="template_name" label="Template" />
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Into agency</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">By</th>
                        <x-sort-header field="outcome" label="Outcome" />
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Package checksum</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($logs as $log)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2 text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $log->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2 text-xs">{{ $log->direction === 'export' ? 'Export' : 'Import' }}</td>
                        <td class="px-4 py-2 font-medium" style="color: var(--text-primary);">{{ $log->template_name ?? '—' }}
                            @if($log->name_clash_choice)<span class="ds-badge ds-badge-default ml-1">{{ $log->name_clash_choice === 'new_version' ? 'new version' : 'new copy' }}</span>@endif
                        </td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">{{ $log->target_agency_name ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">{{ $log->actor_name ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">
                            @if($log->outcome === 'success')<span class="ds-badge ds-badge-success">Done</span>
                            @elseif($log->outcome === 'rejected')<span class="ds-badge ds-badge-default" title="{{ $log->failure_reason }}">Refused</span>
                            @else<span class="ds-badge ds-badge-default" title="{{ $log->failure_reason }}">Failed</span>@endif
                        </td>
                        <td class="px-4 py-2 text-[0.6875rem] font-mono" style="color: var(--text-muted);" title="{{ $log->package_checksum }}">{{ $log->package_checksum ? substr($log->package_checksum, 0, 12) . '…' : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $logs->links() }}</div>
    @endif
</div>
@endsection
