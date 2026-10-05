@extends('layouts.corex')

{{--
    .ai/specs/rental-renewals.md §4-§9 — AT-444's "one small screen": the
    agent enters the new term/rent and either sends a renewal (copy-forward,
    draft-from-template, or manual upload) or records a one-click outcome.
    None of the actions below send anything by themselves — every path
    still ends with an explicit agent action on the next screen (the e-sign
    wizard, or this form's own submit).
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Renew or end tenancy</h1>
        <p class="text-sm" style="color: var(--text-muted);">{{ $lease->property?->buildDisplayAddress() }} — {{ $lease->tenantNames() }}</p>
    </div>

    @if ($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{--
        Renewal term entry. Each send path is its own self-contained form
        (same pattern as the manual-upload card below) carrying its own
        copy of the term fields — simplest way to let several distinct
        submit targets (copy-forward, N templates) share one visual block
        without a single <form> trying to serve multiple actions.
    --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Renew this lease</h2>

        @if($canCopyForward)
            <form method="POST" action="{{ route('corex.leases.renewal.draft', $lease) }}" class="space-y-3">
                @csrf
                @include('corex.leases._renewal-term-fields', ['lease' => $lease])
                <button type="submit" class="corex-btn-primary text-xs">Prepare e-sign renewal</button>
            </form>
        @else
            <p class="text-xs" style="color: var(--text-muted);">This lease wasn't e-signed through CoreX — use one of your agency's lease templates below, or upload a signed renewal directly.</p>
        @endif

        @foreach($leaseTemplates as $entry)
            <div class="pt-3" style="border-top: 1px solid var(--border);">
                <form method="POST" action="{{ route('corex.leases.renewal.draft-from-template', $lease) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="rental_lease_template_id" value="{{ $entry['template']->id }}">
                    @include('corex.leases._renewal-term-fields', ['lease' => $lease])
                    @if(empty($entry['missing']))
                        <button type="submit" class="corex-btn-secondary text-xs">Draft from "{{ $entry['template']->name }}"</button>
                    @else
                        <button type="submit" class="corex-btn-secondary text-xs" disabled style="opacity:0.5;cursor:not-allowed;">Draft from "{{ $entry['template']->name }}"</button>
                        <p class="text-xs" style="color: var(--ds-crimson);">Missing: {{ implode(', ', $entry['missing']) }}</p>
                    @endif
                </form>
            </div>
        @endforeach
    </div>

    {{-- Manual upload — path (c), always available. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Upload a signed renewal</h2>
        <form method="POST" action="{{ route('corex.leases.renewal.upload', $lease) }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="number" name="rental_amount" step="0.01" min="0" required placeholder="New rent (R)" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="number" name="deposit_amount" step="0.01" min="0" placeholder="Deposit (R)" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="date" name="start_date" required placeholder="New start date" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="date" name="end_date" placeholder="New end date" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="file" name="signed_document" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="w-full text-sm">
            <button type="submit" class="corex-btn-primary text-xs">Upload and activate</button>
        </form>
    </div>

    {{-- One-click outcomes — no e-sign cycle. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Or record an outcome</h2>

        @if($lease->hasActiveNotice())
            <div class="flex items-center justify-between text-sm rounded px-3 py-2" style="background: var(--surface-2);">
                <span>{{ $lease->notice_given_by === 'tenant' ? 'Tenant gave notice' : 'Landlord not renewing' }} — move-out {{ optional($lease->move_out_date)->format('d M Y') }} — {{ match($lease->notice_outcome) { 'readvertise' => 'back on the market', 'withdraw' => 'withdrawn', default => 'left as is' } }}</span>
                <form method="POST" action="{{ route('corex.leases.renewal.notice.reverse', $lease) }}">
                    @csrf
                    <button type="submit" class="text-xs" style="color: var(--ds-crimson);">Reverse</button>
                </form>
            </div>
        @else
            {{-- .ai/specs/rental-renewals.md §19 — three-way choice, nothing pre-selected, required. --}}
            <form method="POST" action="{{ route('corex.leases.renewal.tenant-notice', $lease) }}" class="space-y-2">
                @csrf
                <div>
                    <label class="text-xs font-medium">Move-out date</label>
                    <input type="date" name="move_out_date" required class="rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                @include('corex.leases._notice-outcome-fields', ['showAvailableFromOnPortals' => $showAvailableFromOnPortals])
                <button type="submit" class="corex-btn-secondary text-xs">Tenant gave notice</button>
            </form>
            <form method="POST" action="{{ route('corex.leases.renewal.landlord-notice', $lease) }}" class="space-y-2 pt-3" style="border-top: 1px solid var(--border);">
                @csrf
                <div>
                    <label class="text-xs font-medium">End date</label>
                    <input type="date" name="move_out_date" required class="rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                @include('corex.leases._notice-outcome-fields', ['showAvailableFromOnPortals' => $showAvailableFromOnPortals])
                <button type="submit" class="corex-btn-secondary text-xs">Landlord not renewing</button>
            </form>
        @endif

        @if($lease->is_month_to_month)
            <div class="flex items-center justify-between text-sm rounded px-3 py-2" style="background: var(--surface-2);">
                <span>Month-to-month</span>
                <form method="POST" action="{{ route('corex.leases.renewal.month-to-month.reverse', $lease) }}">
                    @csrf
                    <button type="submit" class="text-xs" style="color: var(--ds-crimson);">Reverse</button>
                </form>
            </div>
        @else
            <form method="POST" action="{{ route('corex.leases.renewal.month-to-month', $lease) }}">
                @csrf
                <button type="submit" class="corex-btn-secondary text-xs">Goes month-to-month</button>
            </form>
        @endif

        <p class="text-xs" style="color: var(--text-muted);">Tenant notice period: {{ $tenantNoticePeriodDays }} days (agency setting).</p>
    </div>
</div>
@endsection
