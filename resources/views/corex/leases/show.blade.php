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
            {{-- .ai/specs/rental-work-orders.md §3.4b, Johan's ruling — only
                 shown when set; a blank field for every lease would be a
                 fact nobody needs printed on the common case (screen space
                 to function only). --}}
            @if($lease->rental_no_approval_spend_threshold !== null)
                <div><span style="color: var(--text-muted);">No-approval spend threshold:</span> R{{ number_format((float) $lease->rental_no_approval_spend_threshold, 2) }}</div>
            @endif
        </div>

        {{-- .ai/specs/leases.md — full CRUD floor: deposit/end date/lease type
             editable after creation. Rent amount is deliberately NOT editable
             here — it only ever changes via a recorded escalation (§3.4), so
             the rate history stays a true, unbroken record. Start date is
             fixed once a lease exists; correcting it is a delete-and-recreate
             (only possible while nothing has attached — see isDeletable()),
             not a silent edit of a term the tenant agreed to. --}}
        {{-- Johan, 2026-09-22 — "why are the fields not lined up? its such an
             easy thing to do. just line it all up." Every field below now
             uses the SAME shared `.prop-input`/`.prop-select`/`.prop-label`
             classes (resources/css/corex.css) already used across the app
             (properties, onboarding, rental inventories) instead of one-off
             Tailwind+inline-style combos — that's what guarantees identical
             label position and field height down the column, not a visual
             approximation. End date now sits next to Month-to-month instead
             of alone (a lone `col-span-2` item after it was stranding End
             date in CSS grid's default auto-placement). The read-only
             Monthly rental display below reuses `.prop-input`'s own
             `:disabled` styling instead of a hand-rolled grey background.

             COORDINATION (cc5, work-order quotes, 2026-09-22): the
             no-approval spend threshold field is being removed from this
             screen entirely by cc5 (Johan's ruling — it belongs on the
             PROPERTY, not the lease). Its label/input classes and grid
             placement are left EXACTLY as they were before this layout
             pass — only its two lines of helper text are removed, per
             Johan's screen-space rule (§3 below). Do not "fix" its
             mismatched styling; cc5 deletes the whole field next. --}}
        @permission('leases.create')
        <form id="lease-edit-form" x-show="editing" x-cloak method="POST" action="{{ route('corex.leases.update', $lease) }}" class="space-y-3">
            @csrf
            @method('PUT')
            {{-- The container stays a CONSTANT grid-cols-2 (exactly what it
                 was before this layout pass) — never grid-cols-1. The
                 no-approval threshold field below keeps its own untouched
                 `col-span-2` class (cc5 coordination, see the comment
                 above); if the container itself dropped to 1 explicit
                 column at mobile width, that field's span-2 request would
                 force CSS Grid to create an unwanted IMPLICIT second
                 column, corrupting every row above it too — confirmed via
                 a real headless-browser render at 390px before landing on
                 this shape, not assumed. Responsiveness instead lives on
                 the fields THIS commit owns: each is `col-span-2` (full
                 width) up to `sm:`, then `sm:col-span-1` (half width,
                 paired) from 640px up — the exact same visual outcome,
                 without ever touching the container's own explicit column
                 count out from under the field this commit may not
                 relayout. --}}
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2 sm:col-span-1">
                    {{-- Johan, 2026-09-22 — "the rental amount shows on the
                         lease screen, but not on the edit screen... displaying
                         the rent amount makes it easy to type again [the
                         deposit]." Read-only display only — rent is never
                         editable here, it only ever changes via a recorded
                         escalation (see the form's own comment above). --}}
                    <label class="prop-label">Monthly rental (R)</label>
                    <input type="text" value="R{{ number_format((float) $lease->rental_amount, 2) }}" disabled class="prop-input">
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="prop-label">Deposit (R)</label>
                    <input type="number" name="deposit_amount" step="0.01" min="0" value="{{ old('deposit_amount', $lease->deposit_amount) }}" class="prop-input">
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="prop-label">End date</label>
                    <input type="date" name="end_date" min="{{ $lease->start_date?->format('Y-m-d') }}" value="{{ old('end_date', $lease->end_date?->format('Y-m-d')) }}" class="prop-input" style="color-scheme: light dark;">
                </div>
                <div class="col-span-2 sm:col-span-1 flex items-end pb-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_month_to_month" value="1" @checked(old('is_month_to_month', $lease->is_month_to_month))>
                        Month-to-month (no fixed end date)
                    </label>
                </div>
                {{-- Johan, 2026-09-22 — hidden by default, agency-configurable
                     (Settings → Leases). See the display-mode comment above. --}}
                @if($showLeaseType ?? false)
                <div class="col-span-2">
                    <label class="prop-label">Lease type</label>
                    <select name="lease_type" class="prop-select">
                        <option value="" @selected(!$lease->lease_type)>—</option>
                        {{-- .ai/specs/rental-property-tab.md §5, Part 4 — agency-editable
                             list, same source as the property screen's Lease Type select. --}}
                        @foreach($leaseTypes ?? [] as $lt)
                            <option value="{{ $lt->name }}" @selected($lease->lease_type === $lt->name)>{{ $lt->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                {{-- cc5 coordination — see the comment above the form. Field
                     untouched except the helper text removal (Johan's
                     screen-space rule, §3). --}}
                <div class="col-span-2">
                    <label class="text-xs font-medium">No-approval spend threshold (R)</label>
                    <input type="number" name="rental_no_approval_spend_threshold" step="0.01" min="0" value="{{ old('rental_no_approval_spend_threshold', $lease->rental_no_approval_spend_threshold) }}" placeholder="Agency default" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
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

        @php
            // Johan, 2026-09-22 — "save cancel cancel lease... they can live
            // on 1 line." Mirrors the exact same checks the @permission/@if
            // blocks below use, so this flag can never disagree with what
            // actually renders — it only decides whether the destructive
            // group's divider/right-alignment renders at all (nothing to
            // separate from if neither button is showing).
            $showCancelLeaseBtn = auth()->check() && auth()->user()->hasPermission('leases.cancel') && in_array($lease->status, ['draft', 'active'], true);
            $showArchiveBtn = auth()->check() && auth()->user()->hasPermission('leases.create') && $lease->isDeletable() && $lease->status !== 'active';
        @endphp
        <div class="flex flex-wrap items-center gap-2 pt-1">
            @permission('leases.create')
                <button type="button" x-show="!editing" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
                <button type="submit" form="lease-edit-form" x-show="editing" x-cloak class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" x-show="editing" x-cloak @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
                @if($lease->status === 'draft')
                    <form method="POST" action="{{ route('corex.leases.activate', $lease) }}">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs">Activate</button>
                    </form>
                @endif
            @endpermission
            {{-- Johan, 2026-09-22 — "Cancel lease is the destructive one —
                 keep it visually distinct and separated... not made to look
                 like an equal sibling of Save." Same red-outline convention
                 Archive already uses elsewhere in CoreX, grouped together
                 and pushed to the trailing edge of the row with a divider,
                 not just a colour change. $showCancelLeaseBtn/$showArchiveBtn
                 (above) each mirror their own ORIGINAL, independent
                 permission check (leases.cancel / leases.create) exactly —
                 this group is NOT nested inside @permission('leases.create')
                 above, so a user with leases.cancel but not leases.create
                 still sees Cancel lease, matching the pre-existing behaviour
                 this screen already had before this layout pass. --}}
            @if($showCancelLeaseBtn || $showArchiveBtn)
            <div class="flex items-center gap-2 ml-auto pl-3" style="border-left: 1px solid var(--border);">
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
