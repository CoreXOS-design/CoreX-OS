@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — one list (.ai/specs/campaigns.md §5, §7.3): rename, import, add a lead, leads table with send history in place. --}}
@php
    $archived = $list->trashed();
    $archivedView = request('archived') === '1';
    $newBag = $errors->getBag('newLead');
@endphp
<div class="w-full h-full flex flex-col" x-data="{ panel: '{{ $newBag->any() ? 'lead' : ($errors->has('file') ? 'import' : ($errors->has('name') ? 'rename' : '')) }}' }">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h1 class="text-base font-bold truncate" style="color: var(--text-primary);">{{ $list->name }}</h1>
                @if($archived) @include('admin.campaigns._status', ['status' => 'archived']) @endif
            </div>
            <div class="text-xs cmp-muted">
                @if($list->campaign) Campaign <a class="cmp-link" href="{{ route('admin.campaigns.show', $list->campaign->id) }}">{{ $list->campaign->name }}</a> ({{ $list->campaign->status }}) · @endif
                {{ $list->source_filename ? 'Last import ' . $list->source_filename . ' ' . $list->last_imported_at?->format('Y-m-d H:i') : 'Nothing imported yet' }}
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($archived)
                <form method="POST" action="{{ route('admin.campaigns.lists.restore', $list->id) }}">@csrf<button class="corex-btn-primary text-xs">Restore</button></form>
            @else
                <button type="button" @click="panel = panel === 'import' ? '' : 'import'" class="corex-btn-primary text-xs">Import</button>
                <button type="button" @click="panel = panel === 'lead' ? '' : 'lead'" class="corex-btn-outline text-xs">Add lead</button>
                <button type="button" @click="panel = panel === 'rename' ? '' : 'rename'" class="corex-btn-outline text-xs">Rename</button>
                <form method="POST" action="{{ route('admin.campaigns.lists.archive', $list->id) }}" onsubmit="return confirm('Archive this list?')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Archive</button></form>
            @endif
        </div>
    </div>

    @include('admin.campaigns._head')

    @if($importResult)
        <div class="cmp-panel p-3 mt-2 flex-shrink-0 text-sm" x-data="{ more: false }" data-import-result>
            <div style="color: var(--text-primary);">
                Imported <strong>{{ $importResult['imported'] }}</strong>
                · suppressed {{ $importResult['suppressed'] }} · skipped {{ $importResult['skipped'] }} · rejected {{ $importResult['rejected'] }}
                @if($importResult['truncated']) · {{ $importResult['truncated'] }} value(s) shortened to fit @endif
                @if(count($importResult['rows']))<button type="button" class="cmp-link ml-2" @click="more = !more" x-text="more ? 'Hide rows' : 'Show rows'">Show rows</button>@endif
            </div>
            @if(count($importResult['rows']))
                <div x-show="more" x-cloak class="mt-2 max-h-48 overflow-y-auto corex-brand-scroll text-xs">
                    <table class="w-full">
                        @foreach($importResult['rows'] as $r)
                            <tr class="cmp-row"><td class="py-1 pr-3 cmp-muted">Row {{ $r['row'] }}</td><td class="py-1 pr-3">{{ $r['email'] ?: '—' }}</td><td class="py-1 pr-3">{{ ucfirst($r['outcome']) }}</td><td class="py-1">{{ $r['reason'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>
    @endif

    @unless($archived)
        <form x-show="panel === 'import'" x-cloak method="POST" enctype="multipart/form-data" action="{{ route('admin.campaigns.lists.import.upload', $list->id) }}" class="cmp-panel p-3 mt-2 flex-shrink-0 flex flex-wrap gap-2 items-end" data-import-form>
            @csrf
            <div><label class="cmp-label" for="file">CSV or Excel (.xlsx) file, first row = column names</label><input id="file" type="file" name="file" accept=".csv,.xlsx,.txt" required class="text-sm" style="color: var(--text-primary);"></div>
            <button class="corex-btn-primary text-xs">Next: match columns</button>
        </form>

        <form x-show="panel === 'rename'" x-cloak method="POST" action="{{ route('admin.campaigns.lists.update', $list->id) }}" class="cmp-panel p-3 mt-2 flex-shrink-0 flex gap-2 items-end max-w-xl">
            @csrf @method('PUT')
            <div class="flex-1"><label class="cmp-label">List name</label><input name="name" value="{{ old('name', $list->name) }}" maxlength="150" required class="cmp-input"></div>
            <button class="corex-btn-primary text-xs">Save</button>
        </form>

        <form x-show="panel === 'lead'" x-cloak method="POST" action="{{ route('admin.campaigns.leads.store', $list->id) }}" class="cmp-panel p-3 mt-2 flex-shrink-0 grid grid-cols-2 md:grid-cols-7 gap-2 items-end" data-add-lead>
            @csrf
            @foreach(['first_name' => 'First name', 'last_name' => 'Last name', 'company' => 'Company', 'town' => 'Town', 'email' => 'Email', 'phone' => 'Phone'] as $f => $label)
                <div><label class="cmp-label">{{ $label }}</label><input name="{{ $f }}" value="{{ $newBag->any() ? old($f) : '' }}" class="cmp-input" @if($f === 'email') required @endif>
                    @if($newBag->has($f))<div class="cmp-err">{{ $newBag->first($f) }}</div>@endif</div>
            @endforeach
            <button class="corex-btn-primary text-xs justify-center">Add</button>
            <div class="col-span-2 md:col-span-7"><label class="cmp-label">Notes</label><textarea name="notes" rows="2" class="cmp-input">{{ $newBag->any() ? old('notes') : '' }}</textarea></div>
        </form>
    @endunless

    <div class="mt-2 flex-shrink-0">
        @include('admin.campaigns._leads-filters', ['action' => route('admin.campaigns.lists.show', $list->id), 'hidden' => $archivedView ? ['archived' => '1'] : []])
        <div class="text-xs mt-1">
            @if($archivedView)
                <a class="cmp-link" href="{{ route('admin.campaigns.lists.show', $list->id) }}">Show active leads</a>
            @else
                <a class="cmp-link" href="{{ route('admin.campaigns.lists.show', ['list' => $list->id, 'archived' => 1]) }}">Show archived leads</a>
            @endif
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-2">
        @include('admin.campaigns._leads-table', [
            'leads' => $leads, 'manage' => ! $archived, 'archivedView' => $archivedView, 'filtered' => $filtered,
            'emptyText' => $archivedView ? 'No archived leads.' : 'No leads on this list yet. Import a file or add a lead.',
        ])
    </div>
</div>
@endsection
