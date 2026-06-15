{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Register</h1>
                <p class="text-sm text-white/60">Track sales, commission and settlement across the agency.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('deals.create')
                <a href="{{ route('admin.deals.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Deal
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(isset($paidNotSettledDeals) && $paidNotSettledDeals->count() > 0 && auth()->user()?->hasPermission('settle_deals'))
        <div x-data="{ openPaidExceptions: false }">
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-amber, #f59e0b);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                </svg>
                <div class="flex-1">
                    <strong>Heads up.</strong>
                    {{ number_format($paidNotSettledDeals->count()) }} deal{{ $paidNotSettledDeals->count() === 1 ? '' : 's' }} marked Paid but Settlement not marked Paid.
                </div>
                <button type="button"
                        @click="openPaidExceptions = true"
                        class="text-xs font-semibold flex-shrink-0"
                        style="color: var(--ds-amber, #f59e0b);">
                    View exceptions
                </button>
            </div>

            <div x-show="openPaidExceptions" x-cloak
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="openPaidExceptions = false"></div>

                <div class="relative w-full max-w-3xl rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);">
                    <div class="flex items-center justify-between mb-4">
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">Paid but not settled</div>
                        <button type="button" @click="openPaidExceptions = false" class="deals-modal-close w-8 h-8 flex items-center justify-center rounded-md text-lg transition-colors duration-150" style="color: var(--text-muted);">&times;</button>
                    </div>

                    <div class="text-sm mb-5" style="color: var(--text-secondary);">
                        These deals are marked <b>Paid</b> on the Deal Register, but settlement has not been marked paid yet.
                        Open each settlement and complete the agent payout workflow.
                    </div>

                    <div class="max-h-[60vh] overflow-auto rounded-md" style="border: 1px solid var(--border);">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr style="background: var(--surface-2);">
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal No</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Period</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($paidNotSettledDeals as $d)
                                    <tr style="border-top: 1px solid var(--border);">
                                        <td class="px-4 py-2.5 font-semibold" style="color: var(--brand-icon, #0ea5e9);">{{ $d->deal_no ?? ('#'.$d->id) }}</td>
                                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $d->property_address ?? '—' }}</td>
                                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $d->period ?? '—' }}</td>
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('admin.deals.settle', $d) }}" class="corex-btn-primary text-xs px-3 py-1.5">
                                                Open settlement
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 flex justify-end">
                        <button type="button" @click="openPaidExceptions = false" class="corex-btn-outline text-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        @push('head')
        <style>
            .deals-modal-close:hover { background: var(--surface-2); }
        </style>
        @endpush
    @endif

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('admin.deals') }}" class="flex flex-wrap items-center gap-3">
            {{-- Preserve sort params --}}
            @if(request('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="direction" value="{{ request('direction', 'asc') }}">
            @endif

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search seller, buyer, property, deal no…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); outline: none;">
            </div>

            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All statuses</option>
                <option value="Pending" {{ request('status') === 'Pending' ? 'selected' : '' }}>Pending</option>
                <option value="Granted" {{ request('status') === 'Granted' ? 'selected' : '' }}>Granted</option>
                <option value="Registered" {{ request('status') === 'Registered' ? 'selected' : '' }}>Registered</option>
                <option value="Declined" {{ request('status') === 'Declined' ? 'selected' : '' }}>Declined</option>
            </select>

            <select name="commission" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All commission</option>
                <option value="Not Paid" {{ request('commission') === 'Not Paid' ? 'selected' : '' }}>Not Paid</option>
                <option value="Paid" {{ request('commission') === 'Paid' ? 'selected' : '' }}>Paid</option>
                <option value="Loss" {{ request('commission') === 'Loss' ? 'selected' : '' }}>Loss</option>
            </select>

            @permission('settle_deals')
            <select name="branch" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All branches</option>
                @foreach($branches as $br)
                <option value="{{ $br->id }}" {{ request('branch') == $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                @endforeach
            </select>
            @endpermission

            <select name="agent" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All agents</option>
                @foreach($agents as $ag)
                <option value="{{ $ag->id }}" {{ request('agent') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                @endforeach
            </select>

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if(collect(request()->except(['sort', 'direction', 'page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <a href="{{ route('admin.deals') }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif

            @if($deals->total() > 0)
            <span class="text-sm ml-auto whitespace-nowrap" style="color: var(--text-muted);">
                Showing {{ number_format($deals->firstItem()) }} to {{ number_format($deals->lastItem()) }} of {{ number_format($deals->total()) }} results
            </span>
            @endif
        </form>
    </div>

    <div class="space-y-6">

        @if($errors->any())
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-crimson, #c41e3a);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                </svg>
                <div class="flex-1">{{ $errors->first() }}</div>
            </div>
        @endif

        {{-- Deals Table --}}
        <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
            <div class="overflow-x-auto">
                <table class="ds-table min-w-full text-sm">
                    <colgroup>
                        <col style="width: 85px">
                        <col>
                        <col style="width: 120px">
                        <col style="width: 115px">
                        <col style="width: 105px">
                        @if(($branchIdContext ?? 0) > 0)
                        <col style="width: 105px">
                        @endif
                        <col style="width: 80px">
                        <col style="width: 50px">
                        <col style="width: 140px">
                    </colgroup>
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <x-sort-header field="deal_no" label="Deal" />
                            <x-sort-header field="property_address" label="Property" />
                            <th class="text-left px-3 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                            <x-sort-header field="property_value" label="Price" align="right" />
                            <th class="text-right px-3 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Comm.</th>
                            @if(($branchIdContext ?? 0) > 0)
                                <th class="text-right px-3 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch Comm.</th>
                            @endif
                            <th class="text-center px-2 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="px-2 py-2.5"></th>
                            <th class="text-right px-3 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deals as $deal)
                            @php
                                $b = $branches->firstWhere('id', $deal->branch_id);
                                $acceptedMap = ['P'=>'Pending','D'=>'Declined','G'=>'Granted','R'=>'Registered'];
                                $asVal = (string)($deal->accepted_status ?? '');
                                $csVal = (string)($deal->commission_status ?? '');
                                $acceptedLabel = $acceptedMap[$asVal] ?? ($asVal ?: '—');

                                $statusBadge = 'ds-badge-default';
                                if ($asVal === 'G') $statusBadge = 'ds-badge-success';
                                elseif ($asVal === 'R') $statusBadge = 'ds-badge-info';
                                elseif ($asVal === 'D') $statusBadge = 'ds-badge-danger';
                                elseif ($asVal === 'P') $statusBadge = 'ds-badge-warning';

                                $commBadge = 'ds-badge-default';
                                if ($csVal === 'Paid') $commBadge = 'ds-badge-paid';
                                elseif ($csVal === 'Not Paid') $commBadge = 'ds-badge-notpaid';
                                elseif ($csVal === 'Loss') $commBadge = 'ds-badge-loss';
                            @endphp

                            <tr class="relative">
                                <td class="px-3 py-2.5">
                                    <a href="{{ route('admin.deals.edit', $deal) }}" class="ds-agent-link font-bold">{{ $deal->deal_no }}</a>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $deal->deal_date ? \Carbon\Carbon::parse($deal->deal_date)->format('d M Y') : '—' }}</div>
                                </td>

                                <td class="px-3 py-2.5">
                                    <div class="text-sm font-medium truncate" style="color: var(--text-primary);" title="{{ $deal->property_address }}">{{ \Illuminate\Support\Str::limit($deal->property_address, 35) ?: '—' }}</div>
                                    <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $deal->seller_name ?: '—' }} &rarr; {{ $deal->buyer_name ?: '—' }}</div>
                                    @if(!empty($deal->attorney_name))
                                        <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted); opacity: 0.7;" title="{{ $deal->attorney_name }}">Atty: {{ $deal->attorney_name }}</div>
                                    @endif
                                </td>

                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <div class="text-xs font-medium" style="color: var(--text-primary);">{{ $b?->name ?? '—' }}</div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $deal->period ?: '—' }}</div>
                                </td>

                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format((float)$deal->property_value, 0) }}</div>
                                </td>

                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    <div class="font-bold" style="color: var(--text-primary);">R {{ number_format((float)$deal->totalOurCommission(), 0) }}</div>
                                </td>

                                @if(($branchIdContext ?? 0) > 0)
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                        <div class="font-bold" style="color: var(--text-primary);">R {{ number_format((float)$deal->branchCommission($branchIdContext), 0) }}</div>
                                    </td>
                                @endif

                                <td class="px-2 py-2.5">
                                    <div class="flex flex-col gap-1 items-center">
                                        <span class="ds-badge {{ $statusBadge }}">{{ $acceptedLabel }}</span>
                                        <span class="ds-badge {{ $commBadge }}">{{ $csVal ?: '—' }}</span>
                                    </div>
                                </td>

                                <td class="px-2 py-2.5 text-center" x-data="{ open: false }">
                                    <button type="button" @click="open = !open" class="deals-quick-edit p-1 rounded-md transition-colors duration-150" style="color: var(--text-muted);" title="Quick status update">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </button>
                                    <div x-show="open" @click.outside="open = false" x-cloak
                                         class="absolute right-0 z-20 mt-1 p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border); min-width: 200px; box-shadow: 0 8px 24px rgba(0,0,0,0.18);">
                                        <form method="POST" action="{{ route('admin.deals.quickUpdate', $deal) }}">
                                            @csrf
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Accepted</label>
                                            <select name="accepted_status" class="w-full rounded-md text-xs mb-2 px-2 py-1" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">—</option>
                                                <option value="P" {{ $asVal === 'P' ? 'selected' : '' }}>Pending</option>
                                                <option value="G" {{ $asVal === 'G' ? 'selected' : '' }}>Granted</option>
                                                <option value="R" {{ $asVal === 'R' ? 'selected' : '' }}>Registered</option>
                                                <option value="D" {{ $asVal === 'D' ? 'selected' : '' }}>Declined</option>
                                            </select>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Commission</label>
                                            <select name="commission_status" class="w-full rounded-md text-xs mb-2 px-2 py-1" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">—</option>
                                                <option value="Not Paid" {{ $csVal === 'Not Paid' ? 'selected' : '' }}>Not Paid</option>
                                                <option value="Paid" {{ $csVal === 'Paid' ? 'selected' : '' }}>Paid</option>
                                                <option value="Loss" {{ $csVal === 'Loss' ? 'selected' : '' }}>Loss</option>
                                            </select>
                                            <button type="submit" class="corex-btn-primary w-full text-xs px-2 py-1.5">Save</button>
                                        </form>
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a href="{{ route('admin.deals.log', $deal) }}" class="corex-btn-outline text-xs px-2 py-1">Log</a>
                                        @permission('deals.edit')
                                        <a href="{{ route('admin.deals.edit', $deal) }}" class="corex-btn-outline text-xs px-2 py-1">Edit</a>
                                        @endpermission
                                        @permission('settle_deals')
                                            <a href="{{ route('admin.deals.settle', $deal) }}" class="corex-btn-primary text-xs px-2 py-1">Pay</a>
                                        @endpermission
                                    </div>
                                </td>
                            </tr>
                        @endforeach

                        @if($deals->isEmpty())
                            <tr>
                                <td colspan="{{ ($branchIdContext ?? 0) > 0 ? 9 : 8 }}" class="px-6 py-12 text-center">
                                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/>
                                        </svg>
                                    </div>
                                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No deals found</h3>
                                    <p class="text-sm mb-4" style="color: var(--text-muted);">
                                        @if(collect(request()->except(['sort', 'direction', 'page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
                                            Try adjusting your filters or search.
                                        @else
                                            Add your first deal to start tracking transactions.
                                        @endif
                                    </p>
                                    @permission('deals.create')
                                    <a href="{{ route('admin.deals.create') }}" class="corex-btn-primary text-sm">+ Add Deal</a>
                                    @endpermission
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if($deals->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $deals->links() }}
            </div>
            @endif
        </div>

    </div>

</div>

@push('head')
<style>
    .deals-quick-edit:hover { background: var(--surface-2); }
</style>
@endpush
@endsection
