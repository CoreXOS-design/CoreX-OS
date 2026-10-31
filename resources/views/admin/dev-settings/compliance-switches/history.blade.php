@extends('layouts.corex')

{{-- AT-509 — Dev Settings → Agency compliance switches, change history (.ai/specs/property-compliance-gate-settings.md §7.3 Screen B). --}}

@section('corex-content')
@php
    $tz = config('app.timezone');
    $sortLink = function (string $key, string $label) use ($filters) {
        $dir = ($filters['sort'] === $key && $filters['dir'] === 'desc') ? 'asc' : 'desc';
        $arrow = $filters['sort'] === $key ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<div class="w-full space-y-5">
    @include('admin.dev-settings.compliance-switches._header', ['active' => 'history'])

    <form method="GET" class="flex flex-wrap items-end gap-2 p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Agency, name, email or IP" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Agency</label>
            <select name="agency_id" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
                <option value="">All agencies</option>
                @foreach($agencyOptions as $a)
                    <option value="{{ $a->id }}" @selected($filters['agency_id'] === (string) $a->id)>{{ $a->trading_name ?: $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Check</label>
            <select name="gate" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
                <option value="">Any check</option>
                @foreach($gateOptions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['gate'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">Action</label>
            <select name="action" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
                <option value="">Off and on</option>
                <option value="switched_off" @selected($filters['action'] === 'switched_off')>Switched off</option>
                <option value="switched_on" @selected($filters['action'] === 'switched_on')>Switched on</option>
            </select>
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">From</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <div>
            <label class="block text-[11px] mb-1" style="color:var(--text-muted);">to</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}">
        </div>
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        <a href="{{ route('admin.dev-settings.compliance-switches.history') }}" class="corex-btn-outline text-xs">Clear</a>
        <a href="{{ route('admin.dev-settings.compliance-switches.history.export', request()->except(['page', 'sort', 'dir'])) }}" class="corex-btn-outline text-xs ml-auto">Export CSV</a>
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wider" style="color:var(--text-muted); border-bottom:1px solid var(--border);">
                    <th class="px-4 py-2">{!! $sortLink('date', 'Date & time') !!}</th>
                    <th class="px-4 py-2">{!! $sortLink('agency', 'Agency') !!}</th>
                    <th class="px-4 py-2">{!! $sortLink('gate', 'Check') !!}</th>
                    <th class="px-4 py-2">Action</th>
                    <th class="px-4 py-2">Who</th>
                    <th class="px-4 py-2">IP address</th>
                    <th class="px-4 py-2">Disclaimer</th>
                </tr>
            </thead>
            <tbody>
                @forelse($changes as $c)
                    @php $isOff = $c->action === 'switched_off'; @endphp
                    <tr style="border-bottom:1px solid var(--border);" class="align-top">
                        <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color:var(--text-secondary);">{{ \Illuminate\Support\Carbon::parse($c->created_at)->timezone($tz)->format('j M Y H:i:s') }}</td>
                        <td class="px-4 py-2.5" style="color:var(--text-primary);">{{ $c->agency_name }}</td>
                        <td class="px-4 py-2.5" style="color:var(--text-primary);">{{ $c->gate_label }}</td>
                        <td class="px-4 py-2.5">
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded"
                                  style="{{ $isOff ? 'background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson);' : 'background:color-mix(in srgb, var(--ds-green, #059669) 15%, transparent); color:var(--ds-green, #059669);' }}">
                                {{ $isOff ? 'Switched off' : 'Switched on' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">{{ $c->user_name }}<br>{{ $c->user_email }}</td>
                        <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color:var(--text-secondary);" title="{{ $c->user_agent }}">{{ $c->ip_address }}</td>
                        <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);" x-data="{ show: false }">
                            @if($isOff && $c->disclaimer_accepted_at)
                                Accepted (v{{ $c->disclaimer_version }})
                                <button type="button" class="underline ml-1" @click="show = !show" x-text="show ? 'Hide text' : 'View exact text'"></button>
                                <div x-show="show" x-cloak class="mt-2 p-2 rounded max-w-md" style="background:var(--surface-2); white-space:pre-line;">{{ $c->disclaimer_text }}</div>
                            @elseif($isOff)
                                <span style="color:var(--ds-crimson);">Not recorded</span>
                            @else
                                <span style="color:var(--text-muted);">Not needed (switched on)</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No compliance switches have ever been changed.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $changes->links() }}
</div>
@endsection
