{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md - Solo Agents list (owner-only). Spec: .ai/specs/solo-agent-accounts.md section 6. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider whitespace-nowrap';
    $sortLink = function ($key, $label) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => 1])) . '">' . e($label) . $arrow . '</a>';
    };
    $badge = ['awaiting_contract' => 'ds-badge-warning', 'active' => 'ds-badge-success', 'suspended' => 'ds-badge-danger', 'archived' => 'ds-badge-default'];
    $filtered = $q !== '' || $status !== '' || $does !== '' || $from || $to || $ffcDays !== '';
    $limit = (int) config('solo-agent.ai_template_monthly_limit', 5);
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Solo Agents</h1>
                <p class="text-xs" style="color: var(--text-muted);">Single agents signed up by CoreX. Flat {{ \App\Support\Money\Zar::formatWhole($fee) }} a month, no portal syndication, no team features.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @permission('solo_agents.create')
                <a href="{{ route('admin.solo-agents.create') }}" class="corex-btn-primary text-xs">+ Sign up a single agent</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif

    <form method="GET" class="rounded-md p-3 flex flex-col flex-wrap lg:flex-row gap-2 lg:items-center" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="text" name="q" value="{{ $q }}" placeholder="Search agency, agent, email, mobile or FFC number…" class="list-header-filter flex-1 {{ $q !== '' ? 'is-active' : '' }}" aria-label="Search">
        <select name="status" class="list-header-filter {{ $status !== '' ? 'is-active' : '' }}" onchange="this.form.submit()" aria-label="Status">
            <option value="">Current (not archived)</option>
            <option value="awaiting_contract" @selected($status === 'awaiting_contract')>Awaiting contract</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="suspended" @selected($status === 'suspended')>Suspended</option>
            <option value="archived" @selected($status === 'archived')>Archived</option>
        </select>
        <select name="does" class="list-header-filter {{ $does !== '' ? 'is-active' : '' }}" onchange="this.form.submit()" aria-label="What they do">
            <option value="">Sales and rentals</option>
            <option value="both" @selected($does === 'both')>Both</option>
            <option value="sales" @selected($does === 'sales')>Sales only</option>
            <option value="rentals" @selected($does === 'rentals')>Rentals only</option>
        </select>
        <select name="ffc_days" class="list-header-filter {{ $ffcDays !== '' ? 'is-active' : '' }}" onchange="this.form.submit()" aria-label="FFC expiring">
            <option value="">Any FFC expiry</option>
            @foreach([30, 60, 90] as $d)<option value="{{ $d }}" @selected($ffcDays === (string) $d)>FFC expires within {{ $d }} days</option>@endforeach
        </select>
        <label class="text-xs" style="color: var(--text-muted);">Signed up from <input type="date" name="from" value="{{ $from }}" class="list-header-filter {{ $from ? 'is-active' : '' }}"></label>
        <label class="text-xs" style="color: var(--text-muted);">to <input type="date" name="to" value="{{ $to }}" class="list-header-filter {{ $to ? 'is-active' : '' }}"></label>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <button class="corex-btn-primary text-sm" style="height:2.25rem;">Search</button>
        @if($filtered)<a href="{{ route('admin.solo-agents.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('agency', 'Agency') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('agent', 'Agent') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('email', 'Email') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('mobile', 'Mobile') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('ffc', 'FFC no.') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('ffc_exp', 'FFC expires') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('type', 'Does') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('created', 'Signed up') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('contract', 'Contract') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('status', 'Status') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('login', 'Last login') !!}</th>
                    <th class="{{ $th }} text-right" style="color: var(--text-muted);">{!! $sortLink('listings', 'Listings') !!}</th>
                    <th class="{{ $th }} text-right" style="color: var(--text-muted);">{!! $sortLink('deals', 'Deals') !!}</th>
                    <th class="{{ $th }} text-right" style="color: var(--text-muted);">{!! $sortLink('ai', 'AI templates') !!}</th>
                    <th class="{{ $th }} text-right" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    @php $st = $r->displayStatus(); $expSoon = $r->ffc_expires_on && $r->ffc_expires_on->lte(now()->addDays(60)); @endphp
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);"><a href="{{ route('admin.solo-agents.show', $r->id) }}">{{ $r->agency?->name }}</a></td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $r->user?->name }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $r->user?->email }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $r->user?->cell ?: '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $r->ffc_number ?: '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: {{ $expSoon ? 'var(--ds-crimson)' : 'var(--text-secondary)' }};">{{ $r->ffc_expires_on?->format('j M Y') ?: '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ ucfirst($r->agency?->agency_type ?: 'both') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-muted);">{{ $r->created_at?->format('j M Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $r->status === 'active' ? 'Signed ' . $r->contract_signed_at?->format('j M Y') : ($r->contract_sent_at ? 'Sent ' . $r->contract_sent_at->format('j M Y') : 'Not sent') }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $badge[$st] }}">{{ $r->displayStatusLabel() }}</span></td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-muted);">{{ $r->last_login_at ? \Illuminate\Support\Carbon::parse($r->last_login_at)->format('j M Y') : 'Never' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" style="color: var(--text-secondary);">{{ number_format((int) $r->listings_count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" style="color: var(--text-secondary);">{{ number_format((int) $r->deals_count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" style="color: var(--text-secondary);">{{ (int) $r->ai_used }} of {{ $limit }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.solo-agents.show', $r->id) }}" class="corex-btn-outline corex-btn-xs">Open</a>
                            @if($r->trashed())
                                @permission('solo_agents.archive')
                                <form method="POST" action="{{ route('admin.solo-agents.restore', $r->id) }}" class="inline">@csrf<button class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                @endpermission
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">
                        @if($filtered)
                            No single agents match those filters.
                        @else
                            No single agents yet.
                            @permission('solo_agents.create')
                            <div class="mt-3"><a href="{{ route('admin.solo-agents.create') }}" class="corex-btn-primary text-xs">+ Sign up a single agent</a></div>
                            @endpermission
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-3" style="border-top: 1px solid var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format($rows->total()) }} {{ \Illuminate\Support\Str::plural('single agent', $rows->total()) }}</div>
            <form method="GET" class="flex items-center gap-2 text-xs" style="color: var(--text-muted);">
                @foreach(request()->except(['per_page', 'page']) as $k => $v)@if(is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                Per page
                <select name="per_page" class="list-header-filter" onchange="this.form.submit()">@foreach([25, 50, 100] as $n)<option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>@endforeach</select>
            </form>
            @if($rows->hasPages())<div>{{ $rows->links() }}</div>@endif
        </div>
    </div>
</div>
@endsection
