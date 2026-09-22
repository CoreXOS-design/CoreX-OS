@extends('layouts.corex')

{{-- .ai/specs/leases.md — the lease detail screen. --}}

@php
    $statusBadgeClass = match ($lease->status) {
        'active' => 'ds-badge-success',
        'draft' => 'ds-badge-muted',
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-info',
    };
@endphp

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst($lease->status) }}</span>
            @if($lease->migrated_from_table)
                <span class="text-xs" style="color: var(--text-muted);">— migrated from {{ $lease->migrated_from_table }}#{{ $lease->migrated_from_id }}</span>
            @endif
        </div>
        <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">&larr; All leases</a>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
            <div><span style="color: var(--text-muted);">Tenant(s):</span> {{ $lease->tenantNames() }}</div>
            <div><span style="color: var(--text-muted);">Monthly rental:</span> R{{ number_format((float) $lease->rental_amount, 2) }}</div>
            <div><span style="color: var(--text-muted);">Deposit:</span> {{ $lease->deposit_amount !== null ? 'R' . number_format((float) $lease->deposit_amount, 2) : '—' }}</div>
            <div><span style="color: var(--text-muted);">Start date:</span> {{ $lease->start_date?->format('Y-m-d') }}</div>
            <div><span style="color: var(--text-muted);">End date:</span> {{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</div>
            {{-- Johan, 2026-09-22 — "the freaking lease type is showing here
                 again... hide it, dont remove it." Agency-configurable,
                 default hidden (LeaseSetting::showLeaseTypeFieldFor()).
                 Settings → Leases turns it back on. --}}
            @if($showLeaseType ?? false)
            <div><span style="color: var(--text-muted);">Lease type:</span> {{ $lease->lease_type ?? '—' }}</div>
            @endif
            <div><span style="color: var(--text-muted);">Source:</span> {{ str_replace('_', ' ', ucfirst($lease->source)) }}</div>
            {{-- .ai/specs/rental-work-orders.md §3.4b, Johan's ruling 2026-09-29
                 — "per property, populated to the leases screen." The
                 property is the one editable place this number lives; this
                 screen only ever reads through to it
                 (RentalWorkOrderSetting::thresholdFor()), never stores its
                 own value. --}}
            @if($lease->property)
                <div>
                    <span style="color: var(--text-muted);">No-approval spend threshold:</span>
                    R{{ number_format(\App\Models\RentalWorkOrderSetting::thresholdFor($lease->property), 2) }}
                    <span class="text-xs" style="color: var(--text-muted);">(set on the property —
                        <a href="{{ route('corex.properties.show', $lease->property_id) }}" class="underline">edit there</a>)</span>
                </div>
            @endif
        </div>

        {{-- .ai/specs/leases.md — full CRUD floor: deposit/end date/lease type
             editable after creation. Rent amount is deliberately NOT editable
             here — it only ever changes via a recorded escalation (§3.4), so
             the rate history stays a true, unbroken record. Start date is
             fixed once a lease exists; correcting it is a delete-and-recreate
             (only possible while nothing has attached — see isDeletable()),
             not a silent edit of a term the tenant agreed to. --}}
        @permission('leases.create')
        <div x-show="!editing" class="pt-1">
            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        </div>
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.leases.update', $lease) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-2 gap-3">
                <div>
                    {{-- Johan, 2026-09-22 — "the rental amount shows on the
                         lease screen, but not on the edit screen... displaying
                         the rent amount makes it easy to type again [the
                         deposit]." Read-only display only — rent is never
                         editable here, it only ever changes via a recorded
                         escalation (see the form's own comment above). --}}
                    <label class="text-xs font-medium">Monthly rental (R)</label>
                    <input type="text" value="R{{ number_format((float) $lease->rental_amount, 2) }}" disabled class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border); background: var(--surface-2, #f3f4f6); color: var(--text-muted);">
                </div>
                <div>
                    <label class="text-xs font-medium">Deposit (R)</label>
                    <input type="number" name="deposit_amount" step="0.01" min="0" value="{{ old('deposit_amount', $lease->deposit_amount) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">End date</label>
                    <input type="date" name="end_date" min="{{ $lease->start_date?->format('Y-m-d') }}" value="{{ old('end_date', $lease->end_date?->format('Y-m-d')) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                {{-- Johan, 2026-09-22 — hidden by default, agency-configurable
                     (Settings → Leases). See the display-mode comment above. --}}
                @if($showLeaseType ?? false)
                <div class="col-span-2">
                    <label class="text-xs font-medium">Lease type</label>
                    <select name="lease_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="" @selected(!$lease->lease_type)>—</option>
                        {{-- .ai/specs/rental-property-tab.md §5, Part 4 — agency-editable
                             list, same source as the property screen's Lease Type select. --}}
                        @foreach($leaseTypes ?? [] as $lt)
                            <option value="{{ $lt->name }}" @selected($lease->lease_type === $lt->name)>{{ $lt->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <label class="flex items-center gap-2 text-sm col-span-2">
                    <input type="checkbox" name="is_month_to_month" value="1" @checked(old('is_month_to_month', $lease->is_month_to_month))>
                    Month-to-month (no fixed end date)
                </label>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endpermission

        @if($lease->previousLease)
            <p class="text-xs" style="color: var(--text-muted);">Renewed from <a href="{{ route('corex.leases.show', $lease->previousLease) }}" class="underline">lease #{{ $lease->previousLease->id }}</a>.</p>
        @endif
        @if($lease->renewedLease)
            <p class="text-xs" style="color: var(--text-muted);">Renewed into <a href="{{ route('corex.leases.show', $lease->renewedLease) }}" class="underline">lease #{{ $lease->renewedLease->id }}</a>.</p>
        @endif

        @if($lease->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $lease->cancelled_at?->format('Y-m-d') }}: {{ $lease->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('leases.create')
                @if($lease->status === 'draft')
                    <form method="POST" action="{{ route('corex.leases.activate', $lease) }}">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs">Activate</button>
                    </form>
                @endif
            @endpermission
            @permission('leases.cancel')
                @if(in_array($lease->status, ['draft', 'active'], true))
                    <button type="button" onclick="document.getElementById('cancel-lease-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel lease</button>
                @endif
            @endpermission
            @permission('leases.create')
                @if($lease->isDeletable() && $lease->status !== 'active')
                    <form method="POST" action="{{ route('corex.leases.destroy', $lease) }}" onsubmit="return confirm('Archive this lease?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
            @endpermission
        </div>

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

    {{-- Johan, 2026-09-22 — "every feature needs a navigation link where the
         work happens." Both records carry a real lease_id FK; this is where
         an agent looking at a tenancy actually needs to reach them from. --}}
    @if(auth()->user()?->hasPermission('rental_fault_reports.view') || auth()->user()?->hasPermission('rental_work_orders.view'))
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">This tenancy</h2>
        <div class="flex gap-2">
            @permission('rental_fault_reports.view')
                <a href="{{ route('corex.rental-fault-reports.index', ['lease_id' => $lease->id]) }}" class="corex-btn-outline text-xs">Fault reports</a>
            @endpermission
            @permission('rental_work_orders.view')
                <a href="{{ route('corex.rental-work-orders.index', ['lease_id' => $lease->id]) }}" class="corex-btn-outline text-xs">Work orders</a>
            @endpermission
        </div>
    </div>
    @endif

    {{-- .ai/specs/rental-inventory.md §4 — reachable from where the work
         happens, not only the sidebar list (Johan, 2026-09-22). --}}
    @if($lease->property)
        @include('corex.rental-inventories.partials._related-inventories', ['property' => $lease->property])
    @endif
</div>
@endsection
