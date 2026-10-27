@extends('layouts.corex')

{{-- AT-509 — Dev Settings → Agency compliance switches, agency overview (.ai/specs/property-compliance-gate-settings.md §7.3 Screen A). --}}

@section('corex-content')
@php
    $tz = config('app.timezone');
    $sortLink = function (string $key, string $label) use ($filters) {
        $dir = ($filters['sort'] === $key && $filters['dir'] === 'asc') ? 'desc' : 'asc';
        $arrow = $filters['sort'] === $key ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<div class="w-full space-y-5">
    @include('admin.dev-settings.compliance-switches._header', ['active' => 'agencies'])

    <form method="GET" class="flex flex-wrap items-end gap-2 p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Agency name</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search agencies" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Checks off</label>
            <select name="off" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
                <option value="">All agencies</option>
                <option value="any" @selected($filters['off'] === 'any')>Has a check switched off</option>
                <option value="none" @selected($filters['off'] === 'none')>Everything on</option>
            </select>
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Check switched off</label>
            <select name="gate" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
                <option value="">Any check</option>
                @foreach($gateOptions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['gate'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Last change from</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">to</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        <a href="{{ route('admin.dev-settings.compliance-switches') }}" class="corex-btn-outline text-xs">Clear</a>
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wider" style="color:var(--text-muted); border-bottom:1px solid var(--border);">
                    <th class="px-4 py-2">{!! $sortLink('name', 'Agency') !!}</th>
                    <th class="px-4 py-2">Compliance check</th>
                    <th class="px-4 py-2">{!! $sortLink('off_count', 'Checks switched off') !!}</th>
                    <th class="px-4 py-2">Disclaimer</th>
                    <th class="px-4 py-2">{!! $sortLink('last_change', 'Last change') !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color:var(--text-primary);">
                            <a href="{{ route('admin.dev-settings.compliance-switches.history', ['agency_id' => $r->id]) }}" class="hover:underline">{{ $r->name }}</a>
                        </td>
                        <td class="px-4 py-2.5">
                            @if($r->master_on)
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded" style="background:color-mix(in srgb, var(--ds-green, #059669) 15%, transparent); color:var(--ds-green, #059669);">On</span>
                            @else
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded" style="background:color-mix(in srgb, var(--ds-crimson) 15%, transparent); color:var(--ds-crimson);">All off</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @forelse($r->off as $g)
                                <span class="inline-block text-[11px] px-2 py-0.5 rounded mr-1 mb-1" style="background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson);"
                                      title="{{ $g['switched_off_by'] ? 'Switched off by ' . $g['switched_off_by'] : '' }}">{{ $g['label'] }}</span>
                            @empty
                                <span class="text-xs" style="color:var(--text-muted);">None</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">
                            @if($r->last_off)
                                Accepted by {{ $r->last_off->user_name }}<br>
                                {{ $r->last_off->disclaimer_accepted_at?->timezone($tz)->format('j M Y H:i') }} · {{ $r->last_off->ip_address }}
                            @else
                                <span style="color:var(--text-muted);">Never switched anything off</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">
                            @if($r->last)
                                {{ $r->last->gate_label }} switched {{ $r->last->isSwitchOff() ? 'off' : 'on' }}<br>
                                {{ $r->last->created_at->timezone($tz)->format('j M Y H:i') }} by {{ $r->last->user_name }}
                            @else
                                <span style="color:var(--text-muted);">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No agencies found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
