@extends('layouts.corex')

{{--
    .ai/specs/leases.md §12 — the Lease Hub. "The lease detail becomes the
    tenancy file" (Johan, 4 Oct 2026): one full-width screen answering what
    happened, what's next, and what's open — without checking four other
    screens. Every existing CRUD action (edit/activate/cancel/archive/
    escalate) from the prior narrow-column screen is preserved exactly —
    same routes, same field names — just reorganised into this layout.
    Screen rule: every line of space is data or a control, no fact shown
    twice, no always-on helper text.
--}}

@php
    $statusBadgeClass = match ($lease->status) {
        'active' => 'ds-badge-success',
        'draft' => 'ds-badge-muted',
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-info',
    };
    $lifecycleStateClass = fn ($state) => match ($state) {
        'done' => 'background: var(--ds-green, #16a34a); color: #fff;',
        'current' => 'background: var(--brand-button, #0ea5e9); color: #fff;',
        default => 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    {{-- Header: address, status, tenant(s), rent, term, actions. --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <div class="flex items-center gap-2 mt-1 text-sm">
                <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst($lease->status) }}</span>
                <span>{{ $lease->tenantNames() }}</span>
                <span>&middot;</span>
                <span>R{{ number_format((float) $lease->rental_amount, 2) }}/mo</span>
                <span>&middot;</span>
                <span>{{ $lease->start_date?->format('Y-m-d') }}
                    &ndash; {{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'month-to-month' : 'no end date') }}</span>
                @if($lease->migrated_from_table)
                    <span class="text-xs" style="color: var(--text-muted);">(migrated from {{ $lease->migrated_from_table }}#{{ $lease->migrated_from_id }})</span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.leases.tenancy-report', $lease) }}" target="_blank" class="corex-btn-outline text-xs">Print tenancy report</a>
            @permission('rental_fault_reports.create')
                <a href="{{ route('corex.rental-fault-reports.create', array_filter(['property_id' => $lease->property_id, 'lease_id' => $lease->id])) }}" class="corex-btn-outline text-xs">Report a fault</a>
            @endpermission
            @permission('leases.create')
                <button type="button" class="corex-btn-outline text-xs" onclick="document.getElementById('lease-edit-panel').classList.toggle('hidden')">Edit</button>
            @endpermission
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">&larr; All leases</a>
        </div>
    </div>

    <x-rental-context-bar :lease="$lease" current="lease" />

    {{-- Lifecycle strip — every state derived live, never stored. --}}
    <div class="rounded-md p-3 overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-1 text-xs whitespace-nowrap">
            @foreach($lifecycle as $i => $step)
                <span class="rounded-full px-3 py-1" style="{{ $lifecycleStateClass($step['state']) }}">{{ $step['label'] }}</span>
                @if(!$loop->last)
                    <span style="color: var(--text-muted);">&rarr;</span>
                @endif
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4">
        {{-- Main column: next-step, tenancy log. --}}
        <div class="col-span-3 lg:col-span-2 space-y-4">
            @if($nextStep)
                <div class="rounded-md p-3 flex items-center justify-between" style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 8%, var(--surface)); border: 1px solid var(--brand-button, #0ea5e9);">
                    <span class="text-sm font-medium">Next: {{ $nextStep['label'] }}</span>
                    <a href="{{ route($nextStep['route_name'], $nextStep['route_param']) }}" class="corex-btn-primary text-xs">{{ $nextStep['label'] }}</a>
                </div>
            @endif

            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Tenancy log</h2>

                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="q" value="{{ $timelineFilters['q'] ?? '' }}" placeholder="Search description or actor"
                           class="rounded-md px-3 py-1.5 text-xs flex-1 min-w-[180px]" style="border: 1px solid var(--border);">
                    @foreach($timelineTypes as $t)
                        <label class="text-xs flex items-center gap-1">
                            <input type="checkbox" name="type[]" value="{{ $t }}" @checked(in_array($t, (array) ($timelineFilters['type'] ?? []), true))>
                            {{ ucfirst(str_replace('_', ' ', $t)) }}
                        </label>
                    @endforeach
                    <input type="date" name="date_from" value="{{ $timelineFilters['date_from'] ?? '' }}" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border); color-scheme: light dark;">
                    <input type="date" name="date_to" value="{{ $timelineFilters['date_to'] ?? '' }}" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border); color-scheme: light dark;">
                    <button type="submit" class="corex-btn-outline text-xs">Filter</button>
                </form>

                @if($timelineEntries->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">
                        @if(array_filter($timelineFilters))
                            No entries match this filter.
                        @else
                            Nothing recorded yet on this tenancy.
                        @endif
                    </p>
                @else
                    <ul class="space-y-1">
                        @foreach($timelineEntries as $entry)
                            <li class="flex items-center justify-between text-sm" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                                <div class="min-w-0">
                                    <span class="ds-badge ds-badge-muted text-[10px]">{{ ucfirst(str_replace('_', ' ', $entry['type'])) }}</span>
                                    {{ $entry['description'] }}
                                    @if($entry['actor'])
                                        <span class="text-xs" style="color: var(--text-muted);">— {{ $entry['actor'] }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="text-xs" style="color: var(--text-muted);">{{ \Illuminate\Support\Carbon::parse($entry['occurred_at'])->format('Y-m-d') }}</span>
                                    @if($entry['route_name'])
                                        <a href="{{ route($entry['route_name'], $entry['route_param']) }}" class="text-xs underline">View</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if($timelineTotal > $timelineEntries->count())
                        <p class="text-xs" style="color: var(--text-muted);">Showing {{ $timelineEntries->count() }} of {{ $timelineTotal }}.</p>
                    @endif
                @endif
            </div>
        </div>

        {{-- Side column: lease terms, open items, escalation history. --}}
        <div class="col-span-3 lg:col-span-1 space-y-4">
            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Lease terms</h2>
                <div><span style="color: var(--text-muted);">Monthly rental:</span> R{{ number_format((float) $lease->rental_amount, 2) }}</div>
                <div><span style="color: var(--text-muted);">Deposit:</span> {{ $lease->deposit_amount !== null ? 'R' . number_format((float) $lease->deposit_amount, 2) : '—' }}</div>
                <div><span style="color: var(--text-muted);">Term:</span> {{ $lease->start_date?->format('Y-m-d') }}
                    &ndash; {{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</div>
                @if($showLeaseType ?? false)
                    <div><span style="color: var(--text-muted);">Lease type:</span> {{ $lease->lease_type ?? '—' }}</div>
                @endif
                <div><span style="color: var(--text-muted);">Source:</span> {{ str_replace('_', ' ', ucfirst($lease->source)) }}</div>
                @if($lease->property)
                    <div>
                        <span style="color: var(--text-muted);">No-approval spend limit:</span>
                        R{{ number_format(\App\Models\RentalWorkOrderSetting::thresholdFor($lease->property), 2) }}
                    </div>
                @endif
                <div>
                    <span style="color: var(--text-muted);">Landlord(s):</span>
                    @if($landlords->isEmpty())
                        No landlord linked
                        <a href="{{ route('corex.properties.show', $lease->property) }}?tab=contacts" class="underline text-xs" style="color: var(--brand-icon, #0ea5e9);">Link landlord</a>
                    @else
                        {{ $landlords->map(fn ($c) => $c->full_name)->implode(', ') }}
                    @endif
                </div>
                @if($lease->previousLease)
                    <div class="text-xs" style="color: var(--text-muted);">Renewed from <a href="{{ route('corex.leases.show', $lease->previousLease) }}" class="underline">lease #{{ $lease->previousLease->id }}</a>.</div>
                @endif
                @if($lease->renewedLease)
                    <div class="text-xs" style="color: var(--text-muted);">Renewed into <a href="{{ route('corex.leases.show', $lease->renewedLease) }}" class="underline">lease #{{ $lease->renewedLease->id }}</a>.</div>
                @endif
                @if($lease->status === 'cancelled')
                    <div class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $lease->cancelled_at?->format('Y-m-d') }}: {{ $lease->cancel_reason }}</div>
                @endif
            </div>

            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Open items</h2>
                <a href="{{ route('corex.rental-fault-reports.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Open faults</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['faults'] }}</span>
                </a>
                <a href="{{ route('corex.rental-work-orders.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Open work orders</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['work_orders'] }}</span>
                </a>
                <a href="{{ route('corex.rental-inspections.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Unsigned inspections</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['inspections'] }}</span>
                </a>
            </div>

            <div id="lease-edit-panel" class="hidden rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Edit lease</h2>
                @permission('leases.create')
                <form id="lease-edit-form" method="POST" action="{{ route('corex.leases.update', $lease) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="prop-label">Deposit (R)</label>
                        <input type="number" name="deposit_amount" step="0.01" min="0" value="{{ old('deposit_amount', $lease->deposit_amount) }}" class="prop-input">
                    </div>
                    <div>
                        <label class="prop-label">End date</label>
                        <input type="date" name="end_date" min="{{ $lease->start_date?->format('Y-m-d') }}" value="{{ old('end_date', $lease->end_date?->format('Y-m-d')) }}" class="prop-input" style="color-scheme: light dark;">
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_month_to_month" value="1" @checked(old('is_month_to_month', $lease->is_month_to_month))>
                        Month-to-month (no fixed end date)
                    </label>
                    @if($showLeaseType ?? false)
                        <div>
                            <label class="prop-label">Lease type</label>
                            <select name="lease_type" class="prop-select">
                                <option value="" @selected(!$lease->lease_type)>—</option>
                                @foreach($leaseTypes ?? [] as $lt)
                                    <option value="{{ $lt->name }}" @selected($lease->lease_type === $lt->name)>{{ $lt->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="flex items-center gap-2">
                        <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                        <button type="button" onclick="document.getElementById('lease-edit-panel').classList.add('hidden')" class="corex-btn-outline text-xs">Cancel</button>
                        @if($lease->status === 'draft')
                            @php
                                // leases.md §12.5 point 2 — "a lease CAN be
                                // created/activated on a Withdrawn property."
                                // This confirm is a speed-bump only: on confirm
                                // (or when the property isn't withdrawn at all)
                                // the form submits straight through, same as
                                // before — activation itself never blocks on it
                                // server-side.
                                $propertyWithdrawn = strtolower(trim((string) ($lease->property?->status ?? ''))) === 'withdrawn';
                            @endphp
                            <button type="submit" form="lease-activate-form"
                                @if($propertyWithdrawn) onclick="return confirm('This property is withdrawn. Are you sure you want to use it for this lease?');" @endif
                                class="corex-btn-primary text-xs">Activate</button>
                        @endif
                    </div>
                </form>
                <form id="lease-activate-form" method="POST" action="{{ route('corex.leases.activate', $lease) }}" class="hidden">
                    @csrf
                </form>
                @endpermission

                @php
                    $showCancelLeaseBtn = auth()->check() && auth()->user()->hasPermission('leases.cancel') && in_array($lease->status, ['draft', 'active'], true);
                    $showArchiveBtn = auth()->check() && auth()->user()->hasPermission('leases.create') && $lease->isDeletable() && $lease->status !== 'active';
                @endphp
                @if($showCancelLeaseBtn || $showArchiveBtn)
                    <div class="flex items-center gap-2 pt-2" style="border-top: 1px solid var(--border);">
                        @if($showCancelLeaseBtn)
                            <button type="button" onclick="document.getElementById('cancel-lease-form').classList.toggle('hidden')" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626); border-color: var(--ds-red, #dc2626);">Cancel lease</button>
                        @endif
                        @if($showArchiveBtn)
                            <form method="POST" action="{{ route('corex.leases.destroy', $lease) }}" onsubmit="return confirm('Archive this lease?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        @endif
                    </div>
                @endif
                <form id="cancel-lease-form" method="POST" action="{{ route('corex.leases.cancel', $lease) }}" class="hidden space-y-2 pt-2">
                    @csrf
                    <label class="text-xs font-medium">Reason for cancellation (required)</label>
                    <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
                </form>
            </div>

            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Escalation history</h2>
                @permission('leases.renew')
                    @if($lease->status === 'active')
                        <form method="POST" action="{{ route('corex.leases.escalate', $lease) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label class="text-xs">Effective date</label><br>
                                <input type="date" name="effective_date" required class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs">New monthly rental (R)</label><br>
                                <input type="number" name="new_rental_amount" step="0.01" min="0" required class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs">Note</label><br>
                                <input type="text" name="note" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                            </div>
                            <button type="submit" class="corex-btn-outline text-xs">Record escalation</button>
                        </form>
                    @endif
                @endpermission

                @forelse($lease->escalations as $escalation)
                    <div class="text-sm flex items-center justify-between" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                        <span>{{ $escalation->effective_date->format('Y-m-d') }}: R{{ number_format((float) $escalation->previous_rental_amount, 2) }} &rarr; R{{ number_format((float) $escalation->new_rental_amount, 2) }} ({{ $escalation->escalation_rate_percent >= 0 ? '+' : '' }}{{ $escalation->escalation_rate_percent }}%)</span>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $escalation->createdByUser?->name }}</span>
                    </div>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">No escalations recorded yet.</p>
                @endforelse
            </div>

            @if($lease->property)
                @include('corex.rental-inventories.partials._related-inventories', ['property' => $lease->property])
            @endif
        </div>
    </div>
</div>
@endsection
