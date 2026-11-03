{{-- The "Create work order" form of a fault (Johan, 8 Oct 2026: the agent only chooses WHO does the work). Section 22: it is the step AFTER the owner has authorised
     (appoint the contractor). Before that, someone is sent only through Decision 1 ("Send <name> to inspect") in the fault page's own block.
     Expects the fault screen's variables: $faultReport, $latestDecision, $prefillSupplier, $prefillOwner, $decisionSummary, $contractorPicker. --}}
@php
    $ownersOwnContractor = $prefillOwner !== null || ($latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED && $faultReport->approval_route === \App\Models\RentalFaultReport::ROUTE_OWNER_HANDLES);
    $agencyAppoints = ! $ownersOwnContractor && $latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED;
    $defaultWho = $agencyAppoints ? \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER : \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;
@endphp
@if($ownersOwnContractor)
<form id="raise-work-order-form" data-raise-work-order method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}" class="space-y-3 pt-2" x-data="{ busy: false }" x-on:submit="busy = true">
    @csrf
    <p class="text-xs" style="color: var(--text-muted);">The work order is made from this fault - the title, description and photos the owner saw, the property and the tenant.</p>
    <input type="hidden" name="assignment_type" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}">
    <input type="hidden" name="contractor_name" value="{{ $latestDecision?->contractor_name }}">
    <input type="hidden" name="contractor_phone" value="{{ $latestDecision?->contractor_phone }}">
    <div class="rounded-md p-3 text-sm" style="background: var(--surface-2);" data-owner-contractor>
        <div class="text-xs" style="color: var(--text-muted);">Owner's contractor &mdash; the owner approved and chose their own</div>
        <div class="font-medium">{{ $latestDecision?->contractor_name ?: 'Name not given' }}{{ $latestDecision?->contractor_phone ? ' - ' . $latestDecision->contractor_phone : '' }}</div>
    </div>
    <button type="submit" class="corex-btn-primary text-xs" data-raise-submit x-bind:disabled="busy" x-text="busy ? 'Creating...' : 'Confirm and create work order'">Confirm and create work order</button>
</form>
@else
{{-- section 31.4 (S4) - ONE picker: the agency's contractors AND its own crews, each row with its own "Send to X" button. The press makes the work order and sends the link
     (email now; WhatsApp-only contacts get the two-press WhatsApp row on the work order). Nothing to choose first, nothing to confirm after. --}}
@php
    $apCls = $contractorClassify ?? null;
    $apGroups = collect($contractorPicker)->groupBy(fn ($c) => ($c['first_responder'] ?? false) ? 'set' : ($apCls && $apCls['category'] ? ($c['suits'] ? 'suits' : 'others') : 'all'));
    $apCrews = $crewPicker ?? collect();
@endphp
<form id="raise-work-order-form" data-raise-work-order method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}" class="space-y-2 pt-2"
      x-data="{ q: '', busy: false }" x-on:submit="busy = true">
    @csrf
    <p class="text-xs" style="color: var(--text-muted);">Press <strong>Send to</strong> on whoever does the work: the work order is made from this fault and their link goes out at once.</p>
    <input type="search" x-model="q" placeholder="Search contractors and crews by name, trade or phone" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);" data-picker-search>

    @if($apCls && $apCls['category'] && $apCls['suits']->isEmpty())
        @php($apType = $apCls['type'] ?: $apCls['category'])
        <div class="text-xs" data-no-contractor-suits>
            <p class="font-semibold">No contractor loaded for {{ $apType }}.@if($apCls['mapping_gap']) <span class="font-normal">Your trade list has no &ldquo;{{ $apCls['category'] }}&rdquo; yet.</span>@endif</p>
            @if(!empty($canManageContractors))
                <button type="button" class="underline" data-contractor-popup="add" data-fault-id="{{ $faultReport->id }}" data-type="{{ $apType }}" data-trade-code="{{ $tradeCode ?? '' }}">Add a contractor</button> &middot;
                <button type="button" class="underline" data-contractor-popup="types" data-fault-id="{{ $faultReport->id }}" data-type="{{ $apType }}">Add {{ $apType }} to an existing contractor</button>
            @endif
        </div>
    @endif

    <div class="space-y-1" data-contractor-picker data-refresh-on-contractor-change="fault-picker-primary">
        @forelse($apGroups as $groupKey => $groupRows)
            @if($groupKey === 'set')
                <div class="text-xs font-semibold pt-1" data-contractor-group="set">Set for this property</div>
            @elseif($apCls && $apCls['category'])
                <div class="text-xs font-semibold pt-1" data-contractor-group="{{ $groupKey }}">{{ $groupKey === 'suits' ? 'Suits ' . ($apCls['type'] ?: $apCls['category']) : 'Other contractors' }}</div>
            @else
                <div class="text-xs font-semibold pt-1" data-contractor-group="all">Contractors</div>
            @endif
            @foreach($groupRows as $c)
                <div class="ap-row rounded-md px-2 py-1 text-sm" style="border: 1px solid var(--border);" data-contractor-row="{{ $c['id'] }}"
                     data-search="{{ $c['search'] ?? mb_strtolower($c['name'] . ' ' . $c['trades'] . ' ' . $c['phone']) }}"
                     x-show="q === '' || $el.dataset.search.indexOf(q.toLowerCase()) !== -1">
                    <div class="ap-info">
                        <span class="font-medium">{{ $c['name'] }}</span>
                        <span class="text-xs" style="color: var(--text-muted);">@if(($c['kind'] ?? 'contractor') !== 'contractor')<span data-contractor-kind="{{ $c['kind'] }}">{{ $c['kind_label'] }}</span>@if($c['trades']) &middot; @endif @endif{{ $c['trades'] }}</span>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $c['phone'] }}</span>
                        @if(isset($c['callout']))<span class="text-xs" style="color: var(--text-muted);" data-callout-suffix="{{ $c['callout_status'] }}">{{ $c['callout'] }}</span>@endif
                        @if(! empty($c['callout_notice']))<span class="text-xs w-full" style="color: var(--text-muted);" data-callout-notice>{{ $c['callout_notice'] }}</span>@endif
                        @if(! ($c['has_contact'] ?? true))<span class="cx-crow-warn" data-no-contact-details>No email or mobile on file - they cannot be sent the job</span>@endif
                    </div>
                    <button type="submit" name="pick" value="s:{{ $c['id'] }}" class="corex-btn-primary text-xs" data-send-to="s:{{ $c['id'] }}" data-raise-submit x-bind:disabled="busy">Send to {{ $c['name'] }}</button>
                </div>
            @endforeach
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No contractors are set up yet. Add one under Suppliers, or use one of your own crews below.</p>
        @endforelse
    </div>

    <div class="space-y-1" data-crew-picker>
        <div class="text-xs font-semibold pt-1" data-contractor-group="crews">Our own crews <span class="font-normal" style="color: var(--text-muted);">(also makes the job card; their link goes out at once)</span></div>
        @forelse($apCrews as $c)
            <div class="ap-row rounded-md px-2 py-1 text-sm" style="border: 1px solid var(--border);" data-crew-row="{{ $c->id }}"
                 data-search="{{ mb_strtolower($c->name . ' ' . $c->members->pluck('name')->implode(' ')) }}"
                 x-show="q === '' || $el.dataset.search.indexOf(q.toLowerCase()) !== -1">
                <div class="ap-info">
                    <span class="font-medium">{{ $c->name }}</span>
                    @if($c->members->isNotEmpty())<span class="text-xs" style="color: var(--text-muted);">{{ $c->members->pluck('name')->implode(', ') }}</span>@endif
                </div>
                <button type="submit" name="pick" value="c:{{ $c->id }}" class="corex-btn-primary text-xs" data-send-to="c:{{ $c->id }}" data-raise-submit x-bind:disabled="busy">Assign {{ $c->name }}</button>
            </div>
        @empty
            <div class="ap-row rounded-md px-2 py-1 text-sm" style="border: 1px solid var(--border);" data-crew-row="none">
                <div class="ap-info"><span class="font-medium">Our own maintenance team</span> <span class="text-xs" style="color: var(--text-muted);">No crew is set up yet - the job card opens without one; add a crew under Maintenance crews and assign it from the card.</span></div>
                <button type="submit" name="pick" value="c:" class="corex-btn-outline text-xs" data-send-to="c:" data-raise-submit x-bind:disabled="busy">Create job card</button>
            </div>
        @endforelse
    </div>
</form>
@once
<style>
    .ap-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; }
    .ap-info { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 10px; min-width: 0; flex: 1 1 260px; }
    .ap-row .cx-crow-warn { font-size: 12px; padding: 2px 8px; border-radius: 6px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b); }
    @media (max-width: 639px) { .ap-row .corex-btn-primary, .ap-row .corex-btn-outline { width: 100%; } }
</style>
@endonce
@endif
@if(request()->boolean('create_work_order'))
    <script>document.addEventListener('DOMContentLoaded', function () { var f = document.getElementById('raise-work-order-form'); if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } });</script>
@endif
