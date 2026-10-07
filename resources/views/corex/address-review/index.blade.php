{{-- Structured address matching — the admin-only "could not read this address" list. Spec: .ai/specs/structured-address-matching.md §10. No Alpine. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $sortLink = function (string $key, string $label) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        $arrow = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])) . '" class="no-underline" style="color:var(--text-muted);">' . e($label) . $arrow . '</a>';
    };
    $statusLabel = ['review' => 'Needs a look', 'unparseable' => 'Nothing readable', 'dismissed' => 'Dismissed', 'manual' => 'Fixed by an admin'];
    $statusTone = ['review' => 'var(--ds-amber, #f59e0b)', 'unparseable' => 'var(--ds-crimson, #c41e3a)', 'dismissed' => 'var(--text-muted)', 'manual' => 'var(--ds-green, #059669)'];
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Address Review</h1>
                <p class="text-sm text-white/60">Addresses CoreX could not read with confidence — fix them so they match the right property. Nothing here is ever deleted.</p>
            </div>
            @if($canSettings)
                <a href="{{ route('settings.prospecting.address-matching.edit') }}" class="inline-flex items-center gap-1 text-xs font-semibold no-underline rounded-md px-3 py-2" style="background:rgba(255,255,255,0.12); color:#fff;">Address matching settings →</a>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.address-review.index') }}" class="rounded-md p-4 grid gap-3 md:grid-cols-6" style="background:var(--surface); border:1px solid var(--border);">
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="q" value="{{ $f['q'] }}" placeholder="Street, number, suburb, complex, erf, record number"
                   class="w-full px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Show</label>
            <select name="status" class="w-full px-2 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                @foreach(['open' => 'Needs attention', 'review' => 'Needs a look', 'unparseable' => 'Nothing readable', 'manual' => 'Fixed by an admin', 'dismissed' => 'Dismissed', 'all' => 'Everything'] as $k => $label)
                    <option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Reason</label>
            <select name="reason" class="w-full px-2 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">Any reason</option>
                @foreach($reasons as $k => $label)
                    <option value="{{ $k }}" @selected($f['reason'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Record type</label>
            <select name="kind" class="w-full px-2 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">Properties and captures</option>
                <option value="property" @selected($f['kind'] === 'property')>Properties</option>
                <option value="tracked" @selected($f['kind'] === 'tracked')>Captured / tracked</option>
            </select>
        </div>
        <div class="md:col-span-3 flex items-end gap-2">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Updated from</label>
                <input type="date" name="from" value="{{ $f['from'] }}" class="px-2 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">to</label>
                <input type="date" name="to" value="{{ $f['to'] }}" class="px-2 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
        </div>
        <div class="md:col-span-3 flex items-end justify-end gap-2">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Apply</button>
            <a href="{{ route('corex.address-review.index') }}" class="text-sm px-4 py-2 rounded-md no-underline" style="border:1px solid var(--border); color:var(--text-primary);">Clear</a>
        </div>
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color:var(--text-muted);">
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('kind', 'Record') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('address', 'Address as held') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('suburb', 'Suburb') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('status', 'Status') !!}</th>
                    <th class="px-4 py-3 font-semibold">Why</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('updated', 'Last updated') !!}</th>
                    <th class="px-4 py-3 font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    @php
                        $line = trim(trim((string) $r->street_number) . ' ' . trim((string) $r->street_name));
                        $line = $line !== '' ? $line : (trim((string) $r->complex_name) !== '' ? $r->complex_name : ($r->erf_number ? 'Erf ' . $r->erf_number : '(no address held)'));
                        $open = $r->kind === 'property' ? route('corex.properties.show', $r->id) : route('corex.tracked-properties.show', $r->id);
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 align-top whitespace-nowrap" style="color:var(--text-secondary);">{{ $r->kind === 'property' ? 'Property' : 'Captured' }} #{{ $r->id }}</td>
                        <td class="px-4 py-3 align-top" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($line, 80) }}</td>
                        <td class="px-4 py-3 align-top" style="color:var(--text-secondary);">{{ $r->suburb ?: '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap"><span class="text-xs font-semibold" style="color:{{ $statusTone[$r->status] ?? 'var(--text-muted)' }};">{{ $statusLabel[$r->status] ?? $r->status }}</span></td>
                        <td class="px-4 py-3 align-top text-xs" style="color:var(--text-muted); max-width:22rem;">{{ $r->note ?: '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap text-xs" style="color:var(--text-muted);">{{ $r->updated_at ? \Illuminate\Support\Carbon::parse($r->updated_at)->format('j M Y') : '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap">
                            <a href="{{ $open }}" target="_blank" rel="noopener" class="text-xs no-underline mr-2" style="color:var(--brand-icon,#2563eb);">Open</a>
                            <a href="{{ route('corex.address-review.edit', ['kind' => $r->kind, 'id' => $r->id]) }}" class="text-xs font-semibold no-underline mr-2" style="color:var(--brand-icon,#2563eb);">Fix</a>
                            @if($r->status === 'dismissed')
                                <form method="POST" action="{{ route('corex.address-review.restore', ['kind' => $r->kind, 'id' => $r->id]) }}" class="inline">
                                    @csrf <button type="submit" class="text-xs" style="color:var(--text-secondary); background:none; border:0; cursor:pointer;">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('corex.address-review.dismiss', ['kind' => $r->kind, 'id' => $r->id]) }}" class="inline">
                                    @csrf <button type="submit" class="text-xs" style="color:var(--text-secondary); background:none; border:0; cursor:pointer;">Dismiss</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">
                            @if($anyFilter)
                                Nothing matches these filters. Try a different search, or press Clear.
                            @else
                                Nothing needs a look — every address CoreX holds could be read. Addresses it cannot read will appear here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $rows->links() }}</div>
</div>
@endsection
