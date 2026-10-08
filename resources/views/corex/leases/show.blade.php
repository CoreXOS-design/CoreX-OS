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

    // AT-444 follow-up (conductor, 2026-10-05) — which "Lease actions"
    // dialog (if any) opens on load: a ?action= query param (e.g. from the
    // Command Centre), or a just-failed dialog submission reopening with
    // its entered values. See LeaseActionDialogResolver's own docblock.
    $reopenAction = old('_lease_action');
    $hasDialogErrors = $errors->any() && $reopenAction !== null;
    $openDialog = \App\Services\Rentals\LeaseActionDialogResolver::resolve(
        $lease,
        request()->query('action'),
        $errors->any(),
        $reopenAction
    );
    $isReopening = fn (string $key) => $hasDialogErrors && $reopenAction === $key;

    // AT-444 follow-up 2 (2026-10-05) — one state marker next to the status
    // badge so the agent sees the lease's current outcome without opening
    // the tenancy log. Priority order: an active notice is the most urgent
    // fact; month-to-month and a pending renewal draft are independent of
    // each other in the data model but mutually exclusive with notice in
    // practice, so only the single most relevant one is ever shown.
    $leaseStateMarker = null;
    if ($lease->hasActiveNotice()) {
        $who = $lease->notice_given_by === \App\Models\Lease::NOTICE_BY_TENANT ? 'Notice given' : 'Landlord not renewing';
        $leaseStateMarker = $who . ' · move-out ' . optional($lease->move_out_date)->format('d M Y');
    } elseif ($lease->is_month_to_month) {
        $leaseStateMarker = 'Month-to-month';
    } elseif ($lease->hasPendingRenewalDraft()) {
        $leaseStateMarker = 'Renewal in progress';
    }

    // Johan's ruling, 2026-10-05: a renewal only exists because an agent
    // started one via "Renew lease" — the Renew dialog shows that
    // in-progress draft instead of a blank term-entry invitation that
    // would just create a SECOND draft.
    $pendingRenewalDraft = $lease->status === 'active' ? $lease->renewalDrafts()->first() : null;
@endphp

@section('corex-content')
<div class="lease-screen" id="leaseScreen">
    {{-- AT-444 follow-up 2 — session('success') is already surfaced by the
         app's standard toast (components.toast-notifications reads the
         same flash key on DOMContentLoaded); an inline banner here showed
         the same message twice. --}}

    {{-- Header: address, status, tenant(s), rent, term, actions. Fixed at the
         top of the locked screen (.lease-head); the two panels below it scroll
         on their own. --}}
    <div class="lease-head flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <div>
            {{-- Johan, QA1, 2026-10-07 — the property, tenant and landlord open in a NEW tab so the agent never loses this screen. --}}
            <h1 class="text-lg font-semibold">
                @if($lease->property && !$lease->property->trashed() && auth()->user()->hasPermission('properties.view'))
                    <a href="{{ route('corex.properties.show', $lease->property) }}" target="_blank" rel="noopener" class="hover:underline" data-test="lease-property-link">{{ $lease->property->buildDisplayAddress() }}</a>
                @else
                    {{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}
                @endif{{ $lease->property?->trashed() ? ' (archived)' : '' }}
            </h1>
            <div class="flex items-center gap-2 mt-1 text-sm">
                <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst($lease->status) }}</span>
                @if($lease->signingStatusLabel())
                    <span class="ds-badge ds-badge-info" data-qa="header-signing-status">Agreement: {{ $lease->signingStatusLabel() }}</span>
                @endif
                @if($leaseStateMarker)
                    <span class="ds-badge ds-badge-info">{{ $leaseStateMarker }}</span>
                @endif
                <span>
                    @php $leaseTenantContacts = $lease->tenants->map(fn ($t) => $t->contact)->filter(); @endphp
                    @forelse($leaseTenantContacts as $tc)
                        @if(auth()->user()->hasPermission('contacts.view'))<a href="{{ route('corex.contacts.show', $tc) }}" target="_blank" rel="noopener" class="hover:underline" data-test="lease-tenant-link">{{ $tc->full_name }}</a>@else{{ $tc->full_name }}@endif{{ $loop->last ? '' : ', ' }}
                    @empty
                        {{ $lease->tenantNames() }}
                    @endforelse
                </span>
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
                @feature('rental-faults')
                <a href="{{ route('corex.rental-fault-reports.create', array_filter(['property_id' => $lease->property_id, 'lease_id' => $lease->id])) }}" class="corex-btn-outline text-xs">Report a fault</a>
                @endfeature
            @endpermission
            {{-- AT-442 fix #3 — the Lease Hub had no direct work-order action at all (only "Report a fault"); pass lease_id so it wins and derives the property. --}}
            @permission('rental_work_orders.create')
                @feature('rental-work-orders')
                <a href="{{ route('corex.rental-work-orders.create', array_filter(['property_id' => $lease->property_id, 'lease_id' => $lease->id])) }}" class="corex-btn-outline text-xs">Work order</a>
                @endfeature
            @endpermission
            @permission('rental_notices.create')
                <a href="{{ route('corex.leases.notices.create', $lease) }}" class="corex-btn-outline text-xs">Send notice</a>
            @endpermission
            @permission('leases.create')
                <button type="button" class="corex-btn-outline text-xs" onclick="document.getElementById('lease-edit-panel').classList.toggle('hidden')">Edit</button>
            @endpermission
            @permission('leases.renew')
                @if(in_array($lease->status, ['draft', 'active'], true))
                    <div x-data="{ open: {{ $openDialog === 'outcomes' ? 'true' : 'false' }} }" class="relative" data-qa="lease-actions-menu">
                        <button type="button" @click="open = !open" @click.outside="open = false" class="corex-btn-outline text-xs">Lease actions &#9662;</button>
                        <div x-show="open" x-cloak class="absolute right-0 z-10 mt-1 w-64 rounded-md p-1 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,.15);">
                            @if($lease->status === 'active')
                                <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-renew')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Renew lease&hellip;</button>
                                @if($lease->is_month_to_month)
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-reverse-m2m')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Reverse month-to-month&hellip;</button>
                                @else
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-m2m')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Goes month-to-month&hellip;</button>
                                @endif
                                @if($lease->hasActiveNotice())
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-change-notice-outcome')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Change notice outcome&hellip;</button>
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-reverse-notice')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Reverse notice&hellip;</button>
                                @else
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-tenant-notice')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Tenant gave notice&hellip;</button>
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-landlord-notice')" class="block w-full text-left px-2 py-1.5 rounded" style="background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Landlord not renewing&hellip;</button>
                                @endif
                            @endif
                            {{-- A draft chained to a previous (renewing) lease is a renewal
                                 draft — cancelling it is a distinct action from cancelling an
                                 ordinary lease: it requires a reason, logs who/when/why on BOTH
                                 this draft and the lease it was drafted from.
                                 Gated like the renew action (leases.renew, this whole menu's own
                                 permission), not leases.cancel. --}}
                            @if($lease->status === 'draft' && $lease->previous_lease_id)
                                <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-cancel-renewal-draft')" class="block w-full text-left px-2 py-1.5 rounded" style="color: var(--ds-red, #dc2626); background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Cancel renewal draft&hellip;</button>
                            @else
                                @permission('leases.cancel')
                                    <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-cancel')" class="block w-full text-left px-2 py-1.5 rounded" style="color: var(--ds-red, #dc2626); background: transparent;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Cancel lease&hellip;</button>
                                @endpermission
                            @endif
                            @permission('leases.cancel')
                                <button type="button" x-on:click="open = false; $dispatch('open-modal', 'lease-dialog-archive')" class="block w-full text-left px-2 py-1.5 rounded" style="color: var(--ds-red, #dc2626); background: transparent;" data-test="lease-archive-menu-item" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">Archive lease&hellip;</button>
                            @endpermission
                        </div>
                    </div>
                @endif
            @endpermission
            {{-- leases.md §3.8 — a cancelled or expired lease has no "Lease actions" menu; Archive stands on its own. --}}
            @if(in_array($lease->status, ['cancelled', 'expired'], true))
                @permission('leases.create')
                    <button type="button" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);" data-test="lease-archive-button" x-data x-on:click="$dispatch('open-modal', 'lease-dialog-archive')">Archive lease&hellip;</button>
                @endpermission
            @endif
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">&larr; All leases</a>
        </div>
    </div>

    {{--
        AT-444 follow-up (conductor, 2026-10-05) — "Lease actions" menu
        dialogs. Same routes/fields the old inline panels used (below
        "Open items"); presented as a modal now so the agent sees something
        happen immediately on click, instead of a card far down the page.
        ?action=renew|month-to-month|tenant-notice|landlord-notice opens
        the matching dialog on load (ignored when not valid for this
        lease's current state — LeaseActionDialogResolver::resolve()). A
        failed submission reopens the SAME dialog with the entered values;
        the hidden _lease_action field disambiguates forms that share
        field names (tenant-notice vs landlord-notice both post
        move_out_date/note — the route alone can't tell error-recipient
        forms apart once Laravel's default error bag merges them).
    --}}
    {{-- leases.md §3.8 — Archive lease: reason required; a draft or active lease is cancelled with it and the property is released. --}}
    @php
        $mayArchive = in_array($lease->status, ['draft', 'active'], true)
            ? auth()->user()->hasPermission('leases.cancel')
            : auth()->user()->hasPermission('leases.create');
    @endphp
    @if($mayArchive)
        <x-modal name="lease-dialog-archive" :show="$errors->has('archive_reason')" focusable>
            <form method="POST" action="{{ route('corex.leases.archive', $lease) }}" class="p-6 space-y-3">
                @csrf
                <h2 class="text-lg font-medium">Archive this lease</h2>
                @if($lease->status === 'active')
                    <p class="text-sm" style="color: var(--text-muted);">This ends the tenancy on the system: the lease is cancelled and hidden from the Leases list, and {{ $lease->property?->buildDisplayAddress() ?? 'the property' }} stops showing as let out (unless another lease is active on it). Nothing is deleted — you can bring it back with Restore under "Show archived", as long as no other lease is active on the property by then.</p>
                @elseif($lease->status === 'draft')
                    <p class="text-sm" style="color: var(--text-muted);">The draft is cancelled and hidden from the Leases list. Nothing is deleted — Restore brings it back from "Show archived".</p>
                @else
                    <p class="text-sm" style="color: var(--text-muted);">The lease is hidden from the Leases list. Nothing is deleted — Restore brings it back from "Show archived".</p>
                @endif
                <div>
                    <label class="text-xs font-medium">Reason for archiving (required)</label>
                    <textarea name="archive_reason" required maxlength="500" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('archive_reason') }}</textarea>
                    <x-input-error :messages="$errors->get('archive_reason')" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Keep lease</button>
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Archive lease</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if(in_array($lease->status, ['draft', 'active'], true))
        @if($lease->status === 'active')
            <x-modal name="lease-dialog-renew" :show="$openDialog === 'renew'" focusable>
                <div class="p-6 space-y-4">
                    <h2 class="text-lg font-medium">Renew this lease</h2>
                    @if($pendingRenewalDraft)
                        <p class="text-sm" style="color: var(--text-muted);">A renewal draft is already in progress — R{{ number_format((float) $pendingRenewalDraft->rental_amount, 2) }}/mo from {{ $pendingRenewalDraft->start_date?->format('Y-m-d') }}. Review and send when ready, or start a different one on the renewal screen.</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            {{-- Takes the agent straight to the draft's own Lease Hub page,
                                 where "Cancel renewal draft…" asks for a reason and logs it on
                                 both leases — one confirmation dialog, not duplicated here. --}}
                            <a href="{{ route('corex.leases.show', $pendingRenewalDraft) }}" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Cancel renewal draft&hellip;</a>
                            <a href="{{ route('corex.leases.renewal.create', $lease) }}" class="corex-btn-outline text-xs">Start a different renewal</a>
                            @if($pendingRenewalDraft->renewal_draft_flow_id)
                                <a href="{{ route('docuperfect.esign.step', ['flow' => $pendingRenewalDraft->renewal_draft_flow_id, 'step' => 2]) }}" class="corex-btn-primary text-xs">Review draft</a>
                            @endif
                        </div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted);">Enter the new term on the renewal screen, or attach the signed renewal if you already have it.</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <a href="{{ route('corex.leases.renewal.create', $lease) }}" class="corex-btn-primary text-xs">Continue to renewal</a>
                        </div>
                    @endif
                </div>
            </x-modal>

            @if(!$lease->is_month_to_month)
                <x-modal name="lease-dialog-m2m" :show="$openDialog === 'month-to-month'" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.month-to-month', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <input type="hidden" name="_lease_action" value="month-to-month">
                        <h2 class="text-lg font-medium">Goes month-to-month</h2>
                        <div>
                            <label class="text-xs font-medium">Note (optional)</label>
                            <textarea name="note" maxlength="500" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ $isReopening('month-to-month') ? old('note') : '' }}</textarea>
                            <x-input-error :messages="$isReopening('month-to-month') ? $errors->get('note') : []" class="mt-1" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>
            @else
                <x-modal name="lease-dialog-reverse-m2m" :show="false" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.month-to-month.reverse', $lease) }}" class="p-6 space-y-4">
                        @csrf
                        <h2 class="text-lg font-medium">Reverse month-to-month?</h2>
                        <p class="text-sm" style="color: var(--text-muted);">This lease returns to having a fixed end date, which you set again afterwards.</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>
            @endif

            @if(!$lease->hasActiveNotice())
                <x-modal name="lease-dialog-tenant-notice" :show="$openDialog === 'tenant-notice'" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.tenant-notice', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <input type="hidden" name="_lease_action" value="tenant-notice">
                        <h2 class="text-lg font-medium">Tenant gave notice</h2>
                        <div>
                            <label class="text-xs font-medium">Move-out date (required)</label>
                            <input type="date" name="move_out_date" required min="{{ now()->toDateString() }}" max="{{ now()->addYears(2)->toDateString() }}" value="{{ $isReopening('tenant-notice') ? old('move_out_date') : '' }}" class="prop-input mt-1">
                            <x-input-error :messages="$isReopening('tenant-notice') ? $errors->get('move_out_date') : []" class="mt-1" />
                        </div>
                        <div>
                            <label class="text-xs font-medium">Note (optional)</label>
                            <textarea name="note" maxlength="500" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ $isReopening('tenant-notice') ? old('note') : '' }}</textarea>
                        </div>
                        @include('corex.leases._notice-outcome-fields', ['isReopening' => $isReopening('tenant-notice'), 'showAvailableFromOnPortals' => $showAvailableFromOnPortals])
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>

                <x-modal name="lease-dialog-landlord-notice" :show="$openDialog === 'landlord-notice'" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.landlord-notice', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <input type="hidden" name="_lease_action" value="landlord-notice">
                        <h2 class="text-lg font-medium">Landlord not renewing</h2>
                        <div>
                            <label class="text-xs font-medium">Move-out date (required)</label>
                            <input type="date" name="move_out_date" required min="{{ now()->toDateString() }}" max="{{ now()->addYears(2)->toDateString() }}" value="{{ $isReopening('landlord-notice') ? old('move_out_date') : '' }}" class="prop-input mt-1">
                            <x-input-error :messages="$isReopening('landlord-notice') ? $errors->get('move_out_date') : []" class="mt-1" />
                        </div>
                        <div>
                            <label class="text-xs font-medium">Note (optional)</label>
                            <textarea name="note" maxlength="500" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ $isReopening('landlord-notice') ? old('note') : '' }}</textarea>
                        </div>
                        @include('corex.leases._notice-outcome-fields', ['isReopening' => $isReopening('landlord-notice'), 'showAvailableFromOnPortals' => $showAvailableFromOnPortals])
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>
            @else
                <x-modal name="lease-dialog-change-notice-outcome" :show="$openDialog === 'change-notice-outcome'" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.notice.change-outcome', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <input type="hidden" name="_lease_action" value="change-notice-outcome">
                        <h2 class="text-lg font-medium">Change notice outcome</h2>
                        <p class="text-sm" style="color: var(--text-muted);">Move-out date and who gave notice stay as recorded — only what happens to the property changes.</p>
                        @include('corex.leases._notice-outcome-fields', [
                            'isReopening' => $isReopening('change-notice-outcome'),
                            'presetOutcome' => $isReopening('change-notice-outcome') ? null : $lease->notice_outcome,
                            'showAvailableFromOnPortals' => $showAvailableFromOnPortals,
                        ])
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>

                <x-modal name="lease-dialog-reverse-notice" :show="false" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.notice.reverse', $lease) }}" class="p-6 space-y-4">
                        @csrf
                        <h2 class="text-lg font-medium">Reverse notice?</h2>
                        <p class="text-sm" style="color: var(--text-muted);">Clears the recorded move-out date and who gave notice. Any status change this notice triggered (readvertised or withdrawn) is reversed too.</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-xs">Confirm</button>
                        </div>
                    </form>
                </x-modal>
            @endif
        @endif

        {{-- Mutually exclusive with the renewal-draft modal below: a lease is
             either an ordinary draft/active lease (this one) or a renewal
             draft chained via previous_lease_id (the other one), never both
             — so the two never collide on the shared cancel_reason field. --}}
        @unless($lease->status === 'draft' && $lease->previous_lease_id)
            @permission('leases.cancel')
                <x-modal name="lease-dialog-cancel" :show="$errors->has('cancel_reason')" focusable>
                    <form method="POST" action="{{ route('corex.leases.cancel', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <h2 class="text-lg font-medium">Cancel this lease</h2>
                        <div>
                            <label class="text-xs font-medium">Reason for cancellation (required)</label>
                            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('cancel_reason') }}</textarea>
                            <x-input-error :messages="$errors->get('cancel_reason')" class="mt-1" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
                        </div>
                    </form>
                </x-modal>
            @endpermission
        @else
            @permission('leases.renew')
                <x-modal name="lease-dialog-cancel-renewal-draft" :show="$errors->has('cancel_reason')" focusable>
                    <form method="POST" action="{{ route('corex.leases.renewal.cancel-draft', $lease) }}" class="p-6 space-y-3">
                        @csrf
                        <h2 class="text-lg font-medium">Cancel this renewal draft</h2>
                        <p class="text-sm" style="color: var(--text-muted);">This draft (for <a href="{{ route('corex.leases.show', $lease->previous_lease_id) }}" class="underline">lease #{{ $lease->previous_lease_id }}</a>) will be cancelled and kept for history — never deleted. Use "Renew lease" there to start a new one when ready.</p>
                        <div>
                            <label class="text-xs font-medium">Reason for cancellation (required)</label>
                            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('cancel_reason') }}</textarea>
                            <x-input-error :messages="$errors->get('cancel_reason')" class="mt-1" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="$dispatch('close')">Cancel</button>
                            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
                        </div>
                    </form>
                </x-modal>
            @endpermission
        @endif
    @endif

    <div class="lease-ctx"><x-rental-context-bar :lease="$lease" current="lease" /></div>

    <div class="lease-panels">
        {{-- LEFT panel (scrolls on its own): stage strip, next action, agreement,
             job cards, tenancy log with its filters. --}}
        <div class="lease-panel space-y-3">
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
            @if($nextStep)
                <div class="rounded-md p-3 flex items-center justify-between" style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 8%, var(--surface)); border: 1px solid var(--brand-button, #0ea5e9);">
                    <span class="text-sm font-medium">Next: {{ $nextStep['label'] }}</span>
                    {{-- leases.md §15.13 — a step with nothing to click is a statement; "Prepare again" is a form post. --}}
                    @if(empty($nextStep['route_name']))
                        {{-- statement only --}}
                    @elseif(!empty($nextStep['post']))
                        <form method="POST" action="{{ route($nextStep['route_name'], $nextStep['route_param']) }}">
                            @csrf
                            <button type="submit" class="corex-btn-primary text-xs">{{ $nextStep['label'] }}</button>
                        </form>
                    @else
                        <a href="{{ route($nextStep['route_name'], $nextStep['route_param']) }}" class="corex-btn-primary text-xs">{{ $nextStep['label'] }}</a>
                    @endif
                </div>
            @endif

            {{-- LEASE-AGREEMENT BEGIN (leases.md §15.13 — Build L3a) --}}
            @include('corex.leases._agreement-card')
            {{-- LEASE-AGREEMENT END --}}

            @feature('rental-job-cards')
            @include('corex.leases._job-cards-panel', ['jobCards' => $jobCards])
            @endfeature

            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Tenancy log</h2>

                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="q" value="{{ $timelineFilters['q'] ?? '' }}" placeholder="Search description or actor"
                           class="rounded-md px-3 py-1.5 text-xs flex-1 min-w-[180px]" style="border: 1px solid var(--border);">
                    {{-- Johan, QA1, 2026-10-07 — these were read as status ticks. They are FILTERS for the log:
                         a "Show:" label, compact chips, every type shown when none is on, and a clear/reset. --}}
                    <style>
                        .log-chip input { position: absolute; opacity: 0; pointer-events: none; }
                        .log-chip span { display: inline-block; padding: 1px 8px; border-radius: 9999px; font-size: 11px; border: 1px solid var(--border); color: var(--text-secondary); cursor: pointer; }
                        .log-chip input:checked + span { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; }
                        .log-chip input:focus-visible + span { outline: 2px solid var(--brand-icon, #0ea5e9); outline-offset: 1px; }
                    </style>
                    <div class="flex flex-wrap items-center gap-1 w-full" data-test="tenancy-log-filter-chips">
                        <span class="text-[11px] font-semibold" style="color: var(--text-muted);">Show:</span>
                        @foreach($timelineTypes as $t)
                            <label class="log-chip relative">
                                <input type="checkbox" name="type[]" value="{{ $t }}" @checked(in_array($t, (array) ($timelineFilters['type'] ?? []), true)) onchange="this.form.submit()">
                                <span>{{ ucfirst(str_replace('_', ' ', $t)) }}</span>
                            </label>
                        @endforeach
                        <span class="text-[11px]" style="color: var(--text-muted);">{{ empty(array_filter((array) ($timelineFilters['type'] ?? []))) ? 'All types shown' : 'Only the highlighted types' }}</span>
                        @if(array_filter($timelineFilters))
                            <a href="{{ route('corex.leases.show', $lease) }}" class="text-[11px] underline" style="color: var(--text-secondary);" data-test="tenancy-log-filter-clear">Clear filters</a>
                        @endif
                    </div>
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

        {{-- RIGHT panel (scrolls on its own): lease terms, open items, edit (when opened),
             escalation history, inventory, tenant and landlord portal access. --}}
        <div class="lease-panel space-y-3">
            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Lease terms</h2>
                <div><span style="color: var(--text-muted);">Deposit:</span> {{ $lease->deposit_amount !== null ? 'R' . number_format((float) $lease->deposit_amount, 2) : '—' }}</div>
                @if($showLeaseType ?? false)
                    <div><span style="color: var(--text-muted);">Lease type:</span> {{ $lease->lease_type ?? '—' }}</div>
                @endif
                <div><span style="color: var(--text-muted);">Source:</span> {{ str_replace('_', ' ', ucfirst($lease->source)) }}</div>
                {{-- rental-takeon-import.md §6 — facts captured on take-on,
                     read-only, never fed into any calculation (no scheduled-
                     escalation feature, no rental ledger — neither exists).
                     Shown only when at least one was actually captured. --}}
                @if($lease->migrated_escalation_percent !== null || $lease->migrated_next_escalation_date || $lease->migrated_opening_arrears !== null || $lease->migrated_last_inspection_date)
                <div class="pt-1 mt-1" style="border-top: 1px dashed var(--border);">
                    <div class="text-xs font-semibold" style="color: var(--text-muted);">As captured at take-on</div>
                    @if($lease->migrated_escalation_percent !== null)
                        <div class="text-xs"><span style="color: var(--text-muted);">Escalation:</span> {{ number_format((float) $lease->migrated_escalation_percent, 2) }}%@if($lease->migrated_next_escalation_date) &middot; next due {{ $lease->migrated_next_escalation_date->format('Y-m-d') }}@endif</div>
                    @elseif($lease->migrated_next_escalation_date)
                        <div class="text-xs"><span style="color: var(--text-muted);">Next escalation due:</span> {{ $lease->migrated_next_escalation_date->format('Y-m-d') }}</div>
                    @endif
                    @if($lease->migrated_opening_arrears !== null)
                        <div class="text-xs"><span style="color: var(--text-muted);">Opening balance at take-on:</span> R{{ number_format((float) $lease->migrated_opening_arrears, 2) }} <span style="color: var(--text-muted);">(note only — not posted to any ledger)</span></div>
                    @endif
                    @if($lease->migrated_last_inspection_date)
                        <div class="text-xs"><span style="color: var(--text-muted);">Last inspection before take-on:</span> {{ $lease->migrated_last_inspection_date->format('Y-m-d') }}</div>
                    @endif
                </div>
                @endif
                @if($lease->property)
                    <div>
                        <span style="color: var(--text-muted);">No-approval spend limit:</span>
                        R{{ number_format(\App\Models\RentalWorkOrderSetting::thresholdFor($lease->property), 2) }}
                    </div>
                    {{-- BUILD 2 (§17.6.2) — the lease hub shows both of the owner's work terms (read-only; edited on the property's Rental tab). --}}
                    <div>
                        <span style="color: var(--text-muted);">Extra work approved automatically up to:</span>
                        {{ rtrim(rtrim(number_format(\App\Models\RentalWorkOrderSetting::variationToleranceFor($lease->property), 2, '.', ''), '0'), '.') ?: '0' }} % above the approved amount
                    </div>
                @endif
                <div>
                    <span style="color: var(--text-muted);">Landlord(s):</span>
                    @if($landlords->isEmpty())
                        No landlord linked
                        {{-- A trashed property can't be navigated to (its own show route 404s under
                             default route-model binding) — no point offering a dead-end "Link landlord". --}}
                        @if($lease->property && !$lease->property->trashed())
                            <a href="{{ route('corex.properties.show', $lease->property) }}?tab=contacts" class="underline text-xs" style="color: var(--brand-icon, #0ea5e9);">Link landlord</a>
                        @elseif($lease->property?->trashed())
                            <span class="text-xs" style="color: var(--text-muted);">(property archived)</span>
                        @endif
                    @else
                        @foreach($landlords as $ll)
                            @if(auth()->user()->hasPermission('contacts.view'))<a href="{{ route('corex.contacts.show', $ll) }}" target="_blank" rel="noopener" class="underline" data-test="lease-landlord-link">{{ $ll->full_name }}</a>@else{{ $ll->full_name }}@endif{{ $loop->last ? '' : ', ' }}
                        @endforeach
                    @endif
                </div>
                {{-- FICA is a WARNING here, never a stop (Johan, 2026-10-08): activating, signing and portal access carry on
                     whatever the FICA state; this only says who still needs to submit it, with the link to request/complete it. --}}
                @foreach($ficaWarnings as $ficaWarning)
                    <div class="text-xs rounded-md px-3 py-2" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #b45309); border: 1px solid var(--ds-amber, #f59e0b);" data-qa="lease-fica-warning">
                        {{ $ficaWarning['warning'] }}
                        @if($ficaWarning['url'])
                            <a href="{{ $ficaWarning['url'] }}" class="underline" target="_blank" rel="noopener" data-qa="lease-fica-link">Request / complete FICA</a>
                        @endif
                    </div>
                @endforeach
                @if($lease->previousLease)
                    <div class="text-xs" style="color: var(--text-muted);">Renewed from
                        @if($lease->previousLease->trashed())
                            lease #{{ $lease->previousLease->id }} (archived).
                        @else
                            <a href="{{ route('corex.leases.show', $lease->previousLease) }}" class="underline">lease #{{ $lease->previousLease->id }}</a>.
                        @endif
                    </div>
                @endif
                @if($lease->renewedLease)
                    <div class="text-xs" style="color: var(--text-muted);">Renewed into
                        @if($lease->renewedLease->trashed())
                            lease #{{ $lease->renewedLease->id }} (archived).
                        @else
                            <a href="{{ route('corex.leases.show', $lease->renewedLease) }}" class="underline">lease #{{ $lease->renewedLease->id }}</a>.
                        @endif
                    </div>
                @endif
                @if($lease->status === 'cancelled')
                    <div class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $lease->cancelled_at?->format('Y-m-d') }}: {{ $lease->cancel_reason }}</div>
                @endif
            </div>

            {{-- leases.md §17 (Johan, 8 Oct 2026) — the lease's own two agents. They are who the tenant's and the landlord's portal
                 links show as "who to call". Changing one is logged in the tenancy log (who, from, to, when). --}}
            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="{ editing: {{ $errors->has('owner_agent_user_id') || $errors->has('tenant_agent_user_id') ? 'true' : 'false' }} }" data-qa="lease-agents-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Agents</h2>
                    @permission('leases.create')
                        <button type="button" class="text-xs underline" style="color: var(--brand-icon, #0ea5e9);" x-show="!editing" x-on:click="editing = true" data-qa="lease-agents-change">Change</button>
                    @endpermission
                </div>
                <div x-show="!editing" class="space-y-1">
                    @foreach($leaseAgents as $row)
                        <div data-qa="lease-agent-{{ $row['side'] }}">
                            <span style="color: var(--text-muted);">{{ $row['label'] }}:</span>
                            {{ $row['name'] ?? '—' }}@if($row['derived'] && $row['name']) <span class="text-xs" style="color: var(--text-muted);">(default)</span>@endif
                        </div>
                    @endforeach
                </div>
                @permission('leases.create')
                    <form method="POST" action="{{ route('corex.leases.agents.update', $lease) }}" x-show="editing" x-cloak class="space-y-3" data-qa="lease-agents-form">
                        @csrf
                        @method('PUT')
                        @foreach($leaseAgents as $row)
                            @include('corex.leases._agent-select', [
                                'name' => $row['side'] . '_agent_user_id', 'label' => $row['label'],
                                'selected' => old($row['side'] . '_agent_user_id', $row['id']),
                                'agentOptions' => $agentOptions, 'required' => true,
                            ])
                        @endforeach
                        <div class="flex items-center gap-2">
                            <button type="submit" class="corex-btn-primary text-xs">Save agents</button>
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="editing = false">Cancel</button>
                        </div>
                    </form>
                @endpermission
            </div>

            {{-- leases.md §18 — the lease's own notice and early-cancellation terms: what the signed lease says, what the tenant / owner portal
                 FAQ answers from, and what the lease agreement is filled from. Starts from the agency defaults; changing is logged. --}}
            @php $nt = $noticeTerms ?? null; @endphp
            @if($nt)
            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="{ editing: {{ $errors->has('notice') || $errors->hasAny(array_map(fn ($k) => 'notice.' . $k, \App\Services\Rentals\LeaseNoticeTermsService::EDIT_KEYS)) ? 'true' : 'false' }} }" data-qa="lease-notice-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Notice terms</h2>
                    @permission('lease_notice_terms.edit')
                        @unless($nt['locked'])
                            <button type="button" class="text-xs underline" style="color: var(--brand-icon, #0ea5e9);" x-show="!editing" x-on:click="editing = true" data-qa="lease-notice-change">{{ $nt['has'] ? 'Change' : 'Set' }}</button>
                        @endunless
                    @endpermission
                </div>
                @if($errors->has('notice'))
                    <div class="text-xs" style="color: var(--ds-crimson);">{{ $errors->first('notice') }}</div>
                @endif
                <div x-show="!editing" class="space-y-1" data-qa="lease-notice-values">
                    @if(! $nt['has'])
                        <div style="color: var(--text-muted);">Not on record — the portal FAQ shows nothing for this lease.</div>
                    @else
                        @php $nv = $nt['values']; $w = $nt['worked']; @endphp
                        <div><span style="color: var(--text-muted);">Notice period:</span> {{ $nt['periodText'] ?? 'not on record' }}</div>
                        <div><span style="color: var(--text-muted);">Earliest notice may be given:</span> {{ $w['earliest_notice'] ?? 'not on record' }}</div>
                        <div><span style="color: var(--text-muted);">Earliest the lease may end:</span> {{ $w['earliest_end'] ?? 'not on record' }}</div>
                        @if($w['notice_by'])
                            <div><span style="color: var(--text-muted);">Notice to leave at the end date, by:</span> {{ $w['notice_by'] }}</div>
                        @endif
                        <div><span style="color: var(--text-muted);">Early cancellation:</span>
                            @if(($nv['early_cancellation_allowed'] ?? null) === 'yes')
                                allowed{{ $nt['earlyPeriodText'] ? ' with ' . $nt['earlyPeriodText'] . ' notice' : '' }}
                            @elseif(($nv['early_cancellation_allowed'] ?? null) === 'no')
                                not allowed
                            @else
                                not on record
                            @endif
                        </div>
                        @if(! empty($nv['early_cancellation_penalty']))
                            <div><span style="color: var(--text-muted);">Penalty:</span> {{ $nv['early_cancellation_penalty'] }}</div>
                        @endif
                        @if($nt['source'] === 'agency_default')
                            <div class="text-xs" style="color: var(--text-muted);">Filled from your agency defaults — check it against the signed lease.</div>
                        @endif
                        @if($nt['signedDocument'])
                            <div class="text-xs" style="color: var(--text-muted);">Changing these does not change the signed document.</div>
                        @endif
                    @endif
                </div>
                @permission('lease_notice_terms.edit')
                    @unless($nt['locked'])
                        <form method="POST" action="{{ route('corex.leases.notice-terms.update', $lease) }}" x-show="editing" x-cloak class="space-y-3" data-qa="lease-notice-form">
                            @csrf
                            @method('PUT')
                            @include('corex.leases._notice-terms', [
                                'noticeValues' => $nt['has'] ? $nt['values'] : $nt['defaults'],
                                'noticePrefix' => 'notice',
                                'noticeHide' => [],
                            ])
                            <div class="flex items-center gap-2">
                                <button type="submit" class="corex-btn-primary text-xs">Save notice terms</button>
                                <button type="button" class="corex-btn-outline text-xs" x-on:click="editing = false">Cancel</button>
                            </div>
                        </form>
                    @endunless
                @endpermission
                @if($nt['locked'])
                    <div class="text-xs" style="color: var(--text-muted);">{{ \App\Models\Lease::LOCKED_FOR_SIGNING_MESSAGE }}</div>
                @endif
            </div>
            @endif

            <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Open items</h2>
                @feature('rental-faults')
                <a href="{{ route('corex.rental-fault-reports.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Open faults</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['faults'] }}</span>
                </a>
                @endfeature
                @feature('rental-work-orders')
                <a href="{{ route('corex.rental-work-orders.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Open work orders</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['work_orders'] }}</span>
                </a>
                @endfeature
                @feature('rental-inspections')
                <a href="{{ route('corex.rental-inspections.index', ['lease_id' => $lease->id]) }}" class="flex items-center justify-between no-underline" style="color: inherit;">
                    <span>Unsigned inspections</span><span class="ds-badge ds-badge-muted">{{ $openItemCounts['inspections'] }}</span>
                </a>
                @endfeature
            </div>

            <div id="lease-edit-panel" class="hidden rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Edit lease</h2>
                @permission('leases.create')
                <form id="lease-edit-form" method="POST" action="{{ route('corex.leases.update', $lease) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    {{-- LEASE-AGREEMENT BEGIN (leases.md §15.13 — Build L2): while the agreement is out for
                         signing these fields change in the agreement, not here. Read-only, with the reason. --}}
                    @php $leaseLocked = $lease->isLockedForSigning(); @endphp
                    @if($leaseLocked)
                        <p class="text-xs" style="color: var(--text-muted);">{{ \App\Models\Lease::LOCKED_FOR_SIGNING_MESSAGE }}</p>
                    @endif
                    {{-- LEASE-AGREEMENT END --}}
                    {{-- Johan, 2026-09-22 — "the rental amount shows on the lease screen, but not on the edit screen... displaying the
                         rent amount makes it easy to type again [the deposit]." Read-only display only — rent is never editable here,
                         it only ever changes through a recorded escalation / renewal. (Dropped by the Lease Hub rebuild, restored.) --}}
                    <div>
                        <label class="prop-label">Monthly rental (R)</label>
                        <input type="text" value="R{{ number_format((float) $lease->rental_amount, 2) }}" disabled class="prop-input">
                    </div>
                    <div>
                        <label class="prop-label">Deposit (R)</label>
                        <input type="number" name="deposit_amount" step="0.01" min="0" value="{{ old('deposit_amount', $lease->deposit_amount) }}" @disabled($leaseLocked) class="prop-input">
                    </div>
                    <div>
                        <label class="prop-label">End date</label>
                        <input type="date" name="end_date" min="{{ $lease->start_date?->format('Y-m-d') }}" value="{{ old('end_date', $lease->end_date?->format('Y-m-d')) }}" @disabled($leaseLocked) class="prop-input" style="color-scheme: light dark;">
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_month_to_month" value="1" @checked(old('is_month_to_month', $lease->is_month_to_month)) @disabled($leaseLocked)>
                        Month-to-month (no fixed end date)
                    </label>
                    @if($showLeaseType ?? false)
                        <div>
                            <label class="prop-label">Lease type</label>
                            <select name="lease_type" @disabled($leaseLocked) class="prop-select">
                                <option value="" @selected(!$lease->lease_type)>—</option>
                                @foreach($leaseTypes ?? [] as $lt)
                                    <option value="{{ $lt->name }}" @selected($lease->lease_type === $lt->name)>{{ $lt->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="flex items-center gap-2">
                        <button type="submit" @disabled($leaseLocked) class="corex-btn-primary text-xs">Save changes</button>
                        <button type="button" onclick="document.getElementById('lease-edit-panel').classList.add('hidden')" class="corex-btn-outline text-xs">Cancel</button>
                        {{-- The lease's own Activate is not offered while its agreement is being prepared / out for signing / waiting for
                             approval (it goes active through the signing), nor when the signed agreement needs its differences
                             confirmed first (the hub's "Confirm and activate" does that). --}}
                        @if($lease->status === 'draft'
                            && ! in_array($lease->signing_status, \App\Models\Lease::SIGNING_IN_FLIGHT, true)
                            && ! ($lease->signing_status === \App\Models\Lease::SIGNING_SIGNED && app(\App\Services\Rentals\LeaseHubService::class)->awaitingConfirmation($lease)))
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

            {{-- AT-445 — .ai/specs/rental-portal-access.md §9. Portal access for
             this lease's own tenants and landlords, invited from here, same
             mechanism the Contact page already uses. --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Tenant portal access</h2>
                @forelse($lease->tenantContacts() as $tenantContact)
                    @include('corex.leases._portal-access-person', ['lease' => $lease, 'contact' => $tenantContact, 'role' => 'tenant'])
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">No tenant linked to this lease yet.</p>
                @endforelse
            </div>
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Landlord portal access</h2>
                @forelse($lease->landlordContacts() as $landlordContact)
                    @include('corex.leases._portal-access-person', ['lease' => $lease, 'contact' => $landlordContact, 'role' => 'landlord'])
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">No landlord linked to this property yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

@push('head')
<style>
    /* Locked two-panel working screen — same approach as the rental application
       review screen (rental-applications/review.blade.php): the header is fixed at
       the top, the two panels below it scroll independently, and the screen itself
       never scrolls at laptop sizes. The panel height is measured from the real
       space left (script below); the calc() is only the first-paint fallback.
       Johan, 2026-10-07: "why do we not have a locked screen with the left panel
       scrolling separately to the right panel" + the blank area where the portal
       cards used to wrap onto a second grid row. */
    .lease-screen { display: flex; flex-direction: column; gap: 8px; }
    .lease-head { padding-bottom: 6px; border-bottom: 1px solid var(--border); }
    .lease-panels { display: flex; flex-direction: column; gap: 12px; }
    .lease-panel { min-width: 0; }
    /* Compact: screen space goes to function. Overrides only the spacing of the
       existing cards, so their own markup stays untouched. */
    .lease-panel > .rounded-md.p-4 { padding: 10px 12px; }
    .lease-panel .space-y-3 > :not([hidden]) ~ :not([hidden]) { margin-top: 8px; }
    .lease-panel .space-y-2 > :not([hidden]) ~ :not([hidden]) { margin-top: 4px; }
    @media (min-width: 1024px) {
        .lease-screen { height: var(--ls-h, calc(100vh - 140px)); }
        .lease-head, .lease-ctx { flex: 0 0 auto; }
        .lease-panels {
            flex: 1 1 auto; min-height: 0;
            display: grid; grid-template-columns: minmax(0, 1fr) clamp(320px, 30%, 420px); gap: 12px;
        }
        .lease-panel { min-height: 0; overflow-y: auto; overflow-x: hidden; scrollbar-gutter: stable; padding-right: 2px; }
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var root = document.getElementById('leaseScreen');
    if (!root) return;
    function recalc() {
        var scrollEl = document.getElementById('appScroll');
        if (!scrollEl) return;
        var cs = getComputedStyle(scrollEl);
        var avail = scrollEl.getBoundingClientRect().bottom - root.getBoundingClientRect().top - parseFloat(cs.paddingBottom || 0) - 2;
        root.style.setProperty('--ls-h', Math.max(360, avail) + 'px');
    }
    recalc();
    window.addEventListener('resize', recalc);
    // "Edit" toggles #lease-edit-panel (a card inside the right panel); bring it into view when it opens.
    var edit = document.getElementById('lease-edit-panel');
    if (edit && window.MutationObserver) {
        new MutationObserver(function () {
            if (!edit.classList.contains('hidden')) edit.scrollIntoView({ block: 'nearest' });
        }).observe(edit, { attributes: true, attributeFilter: ['class'] });
    }
    setTimeout(recalc, 300);
})();
</script>
@endpush
@endsection
