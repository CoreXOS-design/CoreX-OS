{{-- System owner > Revenue. Owner only. Signed agencies, prospect pipeline, 12-month cash flow. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $money = fn ($v) => $v === null ? null : 'R ' . number_format((float) $v, 2, '.', ' ');
    $url = fn (array $extra = []) => route('admin.revenue.index', array_filter(array_merge(['tab' => $tab, 'q' => $q ?: null, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir === 'desc' ? 'desc' : null, 'archived' => $archived ? 1 : null], $extra), fn ($v) => $v !== null));
    $sortUrl = fn (string $col) => $url(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']);
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $editing = $edit && isset($rows) ? $rows->first(fn ($r) => (($r['record'] ?? $r['prospect'])->id ?? 0) === $edit) : null;
    $inp = 'style="padding:5px 8px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text-primary);font-size:12px;"';
@endphp
<style>
    .rv-tabs a { padding: 6px 14px; font-size: 13px; font-weight: 600; text-decoration: none; color: var(--text-muted); border-bottom: 2px solid transparent; }
    .rv-tabs a.on { color: var(--text-primary); border-color: var(--brand-icon, #0ea5e9); }
    table.rv { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.rv th, table.rv td { padding: 6px 10px; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
    table.rv th { background: var(--surface-2); position: sticky; top: 0; font-weight: 600; color: var(--text-muted); }
    table.rv th a { color: inherit; text-decoration: none; }
    table.rv td.n, table.rv th.n { text-align: right; }
    table.rv tfoot td { font-weight: 700; background: var(--surface-2); }
    .rv-miss { color: var(--ds-amber, #b45309); font-style: italic; }
    .rv-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); margin-bottom: 12px; }
    .rv-form label { font-size: 11px; color: var(--text-muted); display: flex; flex-direction: column; gap: 2px; }
    .rv-bar { height: 12px; border-radius: 3px; display: inline-block; vertical-align: middle; }
</style>
<div class="w-full flex flex-col gap-3">
    <div class="rounded-md px-6 py-4 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Revenue</h1>
        <p class="text-xs" style="color: var(--text-muted);">What signed agencies pay CoreX, who is still coming, and the next 12 months. System owner only. Prices come from the price list in Agency Billing; no VAT is added.</p>
    </div>
    @if(session('success'))<div class="rounded-md px-4 py-2 text-xs" style="background: color-mix(in srgb, #16a34a 12%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-xs" style="background: color-mix(in srgb, #dc2626 12%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>@endif

    <div class="rv-tabs flex gap-1" style="border-bottom: 1px solid var(--border);">
        <a href="{{ route('admin.revenue.index', ['tab' => 'signed']) }}" class="{{ $tab === 'signed' ? 'on' : '' }}">Signed agencies</a>
        <a href="{{ route('admin.revenue.index', ['tab' => 'pipeline']) }}" class="{{ $tab === 'pipeline' ? 'on' : '' }}">Pipeline</a>
        <a href="{{ route('admin.revenue.index', ['tab' => 'cashflow']) }}" class="{{ $tab === 'cashflow' ? 'on' : '' }}">Cash flow</a>
        <a href="{{ route('admin.billing.index') }}" title="The prices live in Agency Billing">Price list &rarr; Agency Billing</a>
    </div>

@if(in_array($tab, ['signed', 'pipeline'], true))
    <form method="GET" action="{{ route('admin.revenue.index') }}" class="flex items-center gap-2">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="text" name="q" value="{{ $q }}" placeholder="Search by name{{ $tab === 'pipeline' ? ', contact or notes' : '' }}…" {!! $inp !!} style="min-width:260px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text-primary);font-size:12px;">
        <label class="text-xs" style="color:var(--text-muted);"><input type="checkbox" name="archived" value="1" {{ $archived ? 'checked' : '' }} onchange="this.form.submit()"> Show archived</label>
        <button class="corex-btn-outline text-xs" type="submit">Search</button>
    </form>
@endif

@if($tab === 'signed')
    <form method="POST" action="{{ $editing ? route('admin.revenue.signed.update', $editing['record']) : route('admin.revenue.signed.store') }}" class="rv-form">
        @csrf @if($editing) @method('PUT') @endif
        @php $r = $editing['record'] ?? null; @endphp
        <strong class="text-xs" style="width:100%;">{{ $editing ? 'Edit ' . $r->name : 'Add a signed agency' }}</strong>
        <label>Name<input name="name" required value="{{ old('name', $r->name ?? '') }}" {!! $inp !!}></label>
        <label>Agency on CoreX<select name="agency_id" {!! $inp !!}><option value="">— not on CoreX yet —</option>@foreach($agencies as $id => $nm)<option value="{{ $id }}" @selected(old('agency_id', $r->agency_id ?? null) == $id)>{{ $nm }}</option>@endforeach</select></label>
        <label>Users<input name="users" type="number" min="0" value="{{ old('users', $r->users ?? '') }}" style="width:70px;" {!! $inp !!}></label>
        <label>Branches<input name="branches" type="number" min="1" value="{{ old('branches', $r->branches ?? 1) }}" style="width:70px;" {!! $inp !!}></label>
        <label>Special deal: monthly (R)<input name="override_monthly" type="number" step="0.01" min="0" value="{{ old('override_monthly', $r->override_monthly ?? '') }}" placeholder="price list" style="width:120px;" {!! $inp !!}></label>
        <label>Deal note<input name="override_note" value="{{ old('override_note', $r->override_note ?? '') }}" placeholder="e.g. Founding Partner" style="width:150px;" {!! $inp !!}></label>
        <label>Go-live date<input name="start_date" type="date" value="{{ old('start_date', optional($r?->start_date)->format('Y-m-d')) }}" {!! $inp !!}></label>
        <label>Term (months)<input name="term_months" type="number" min="0" value="{{ old('term_months', $r->term_months ?? '') }}" style="width:80px;" {!! $inp !!}></label>
        <label>Notes<input name="notes" value="{{ old('notes', $r->notes ?? '') }}" style="min-width:160px;" {!! $inp !!}></label>
        <button class="corex-btn-primary text-xs" type="submit">{{ $editing ? 'Save' : 'Add' }}</button>
        @if($editing)<a class="text-xs" href="{{ $url(['edit' => null]) }}">Cancel</a>@endif
    </form>
    <div class="rounded-md overflow-auto" style="border:1px solid var(--border);background:var(--surface);">
        <table class="rv">
            <thead><tr>
                <th><a href="{{ $sortUrl('name') }}">Agency{{ $arrow('name') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('users') }}">Users{{ $arrow('users') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('price') }}">Price per user{{ $arrow('price') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('monthly') }}">Monthly charge{{ $arrow('monthly') }}</a></th>
                <th><a href="{{ $sortUrl('start') }}">Start date{{ $arrow('start') }}</a></th>
                <th><a href="{{ $sortUrl('term') }}">Term{{ $arrow('term') }}</a></th><th></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr data-row="signed">
                    <td>{{ $r['name'] }}@if($r['branches'] > 1)<span style="color:var(--text-muted);"> · {{ $r['branches'] }} branches</span>@endif @if($r['agency'] && $r['agency'] !== $r['name'])<span style="color:var(--text-muted);"> · {{ $r['agency'] }}</span>@endif</td>
                    <td class="n">{{ $r['users'] ?? '—' }}@if($r['users_from_agreement'])<span style="color:var(--text-muted);" title="From the signed agreement"> (agreement)</span>@endif</td>
                    <td class="n">{!! $r['price'] !== null ? e($money($r['price'])) : '<span class="rv-miss">' . ($r['quote'] ? 'tailored quote' : 'set users') . '</span>' !!}</td>
                    <td class="n">{!! $r['monthly'] !== null ? e($money($r['monthly'])) . ($r['override'] ? ' <span title="Special deal replaces the price list" style="color:var(--text-muted);">(' . e($r['override']) . ')</span>' : '') : '<span class="rv-miss">' . ($r['quote'] ? 'tailored quote' : 'set price') . '</span>' !!}</td>
                    <td>{{ $r['start'] ? $r['start']->format('j M Y') : '—' }}</td>
                    <td>{{ $r['term'] ? $r['term'] . ' months' : '—' }}</td>
                    <td class="n">
                        @if($archived)
                            <form method="POST" action="{{ route('admin.revenue.signed.restore', $r['record']->id) }}" style="display:inline;">@csrf<button class="text-xs" type="submit">Restore</button></form>
                        @else
                            <a class="text-xs" href="{{ $url(['edit' => $r['record']->id]) }}">Edit</a>
                            <form method="POST" action="{{ route('admin.revenue.signed.archive', $r['record']) }}" style="display:inline;" onsubmit="return confirm('Archive this agency from the list?')">@csrf @method('DELETE')<button class="text-xs" type="submit">Archive</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:18px;color:var(--text-muted);">No signed agencies{{ $q ? ' match your search' : ' yet — add the first one above' }}.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td>Total: {{ $totals['agencies'] }} {{ \Illuminate\Support\Str::plural('agency', $totals['agencies']) }}</td><td class="n">{{ $totals['users'] }}</td><td></td><td class="n" data-total="monthly">{{ $money($totals['monthly']) }}</td><td colspan="3">monthly recurring</td></tr></tfoot>
        </table>
    </div>

@elseif($tab === 'pipeline')
    <form method="POST" action="{{ $editing ? route('admin.revenue.pipeline.update', $editing['prospect']) : route('admin.revenue.pipeline.store') }}" class="rv-form">
        @csrf @if($editing) @method('PUT') @endif
        @php $p = $editing['prospect'] ?? null; @endphp
        <strong class="text-xs" style="width:100%;">{{ $editing ? 'Edit ' . $p->name : 'Add a prospect' }}</strong>
        <label>Name<input name="name" required value="{{ old('name', $p->name ?? '') }}" {!! $inp !!}></label>
        <label>Contact<input name="contact" value="{{ old('contact', $p->contact ?? '') }}" {!! $inp !!}></label>
        <label>Stage<select name="stage" {!! $inp !!}>@foreach(\App\Models\RevenueProspect::STAGES as $k => $v)<option value="{{ $k }}" @selected(old('stage', $p->stage ?? 'talking') === $k)>{{ $v }}</option>@endforeach</select></label>
        <label>Users from<input name="users_min" type="number" min="0" value="{{ old('users_min', $p->users_min ?? '') }}" style="width:70px;" {!! $inp !!}></label>
        <label>to<input name="users_max" type="number" min="0" value="{{ old('users_max', $p->users_max ?? '') }}" style="width:70px;" {!! $inp !!}></label>
        <label>Special price: monthly (R)<input name="monthly_override" type="number" step="0.01" min="0" value="{{ old('monthly_override', $p->monthly_override ?? '') }}" placeholder="price list" style="width:120px;" {!! $inp !!}></label>
        <label>Chance %<input name="chance_percent" type="number" min="0" max="100" required value="{{ old('chance_percent', $p->chance_percent ?? 50) }}" style="width:70px;" {!! $inp !!}></label>
        <label>Expected go-live<input name="expected_start_month" type="month" value="{{ old('expected_start_month', optional($p?->expected_start_month)->format('Y-m')) }}" {!! $inp !!}></label>
        <label>Notes<input name="notes" value="{{ old('notes', $p->notes ?? '') }}" style="min-width:160px;" {!! $inp !!}></label>
        <button class="corex-btn-primary text-xs" type="submit">{{ $editing ? 'Save' : 'Add' }}</button>
        @if($editing)<a class="text-xs" href="{{ $url(['edit' => null]) }}">Cancel</a>@endif
    </form>
    <div class="rounded-md overflow-auto" style="border:1px solid var(--border);background:var(--surface);">
        <table class="rv">
            <thead><tr>
                <th><a href="{{ $sortUrl('name') }}">Prospect{{ $arrow('name') }}</a></th><th>Contact</th>
                <th><a href="{{ $sortUrl('stage') }}">Stage{{ $arrow('stage') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('users') }}">Users{{ $arrow('users') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('price') }}">Monthly (range){{ $arrow('price') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('chance') }}">Chance{{ $arrow('chance') }}</a></th>
                <th><a href="{{ $sortUrl('start') }}">Expected go-live{{ $arrow('start') }}</a></th>
                <th class="n"><a href="{{ $sortUrl('weighted') }}">Weighted monthly{{ $arrow('weighted') }}</a></th><th>Notes</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $r)
                @php $p = $r['prospect']; @endphp
                <tr data-row="prospect">
                    <td>{{ $p->name }}</td><td>{{ $p->contact ?: '—' }}</td>
                    <td>{{ \App\Models\RevenueProspect::STAGES[$p->stage] ?? $p->stage }}</td>
                    <td class="n">{{ $p->users_min && $p->users_max && $p->users_min !== $p->users_max ? $p->users_min . '–' . $p->users_max : ($p->users_max ?: ($p->users_min ?: '—')) }}</td>
                    <td class="n">{!! $r['low'] === null && $r['high'] === null ? '<span class="rv-miss">' . (($p->users_max ?: $p->users_min) >= 40 ? 'tailored quote' : 'set users') . '</span>' : e($r['low'] === $r['high'] || $r['high'] === null ? $money($r['low']) : $money($r['low']) . ' – ' . $money($r['high'])) !!}</td>
                    <td class="n">{{ $p->chance_percent }}%</td>
                    <td>{{ $p->expected_start_month ? $p->expected_start_month->format('M Y') : '—' }}</td>
                    <td class="n">{{ $money($r['weighted']) }}</td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;" title="{{ $p->notes }}">{{ $p->notes }}</td>
                    <td class="n">
                        @if($archived)
                            <form method="POST" action="{{ route('admin.revenue.pipeline.restore', $p->id) }}" style="display:inline;">@csrf<button class="text-xs" type="submit">Restore</button></form>
                        @else
                            <a class="text-xs" href="{{ $url(['edit' => $p->id]) }}">Edit</a>
                            <form method="POST" action="{{ route('admin.revenue.pipeline.convert', $p) }}" style="display:inline;">@csrf
                                <select name="agency_id" class="text-xs" style="max-width:130px;"><option value="">Convert: not on CoreX yet</option>@foreach($agencies as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach</select>
                                <button class="text-xs" type="submit">Convert to signed</button></form>
                            <form method="POST" action="{{ route('admin.revenue.pipeline.archive', $p) }}" style="display:inline;" onsubmit="return confirm('Archive this prospect?')">@csrf @method('DELETE')<button class="text-xs" type="submit">Archive</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" style="padding:18px;color:var(--text-muted);">No prospects{{ $q ? ' match your search' : ' yet — add one above' }}.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td>Total: {{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('prospect', $totals['count']) }}</td><td></td><td></td>
                <td class="n">{{ $totals['users_min'] === $totals['users_max'] ? $totals['users_max'] : $totals['users_min'] . '–' . $totals['users_max'] }}</td><td class="n" data-total="range">{{ $money($totals['low']) }}{{ $totals['high'] !== $totals['low'] ? ' – ' . $money($totals['high']) : '' }}</td><td></td><td></td>
                <td class="n" data-total="weighted">{{ $money($totals['weighted']) }}</td><td colspan="2">weighted monthly</td></tr></tfoot>
        </table>
    </div>

@else
    <div class="rounded-md overflow-auto" style="border:1px solid var(--border);background:var(--surface);">
        <table class="rv">
            <thead><tr><th>Month</th><th class="n">Signed</th><th class="n">Pipeline (weighted)</th><th class="n">Total</th><th style="width:40%;">&nbsp;</th></tr></thead>
            <tbody>
            @foreach($flow as $m)
                <tr data-row="month">
                    <td>{{ $m['month']->format('M Y') }}</td>
                    <td class="n">{{ $money($m['signed']) }}</td><td class="n">{{ $money($m['pipeline']) }}</td><td class="n"><strong>{{ $money($m['total']) }}</strong></td>
                    <td><span class="rv-bar" style="width:{{ round($m['signed'] / $max * 100) }}%;background:var(--brand-icon,#0ea5e9);"></span><span class="rv-bar" style="width:{{ round($m['pipeline'] / $max * 100) }}%;background:var(--ds-amber,#f59e0b);opacity:.75;"></span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs" style="color:var(--text-muted);">Signed = what signed agencies pay from the month after their go-live date. Pipeline = open prospects, weighted by their chance %, counted from their expected start month. Billing starts the month after go-live (the take-on month is free). A prospect with no expected month, or on quotation with no special price, is left out.</p>
@endif
</div>
@endsection
