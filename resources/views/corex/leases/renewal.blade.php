@extends('layouts.corex')

{{--
    .ai/specs/rental-renewals.md §4-§9 — AT-444's "one small screen": the
    agent enters the new term/rent and either sends a renewal (copy-forward
    draft or manual upload) or records a one-click outcome. Path (b) —
    drafting fresh from an agency lease template — is not offered here yet;
    that's item 5's own WAIT gate. None of the actions below send anything
    by themselves — every path still ends with an explicit agent action on
    the next screen (the e-sign wizard, or this form's own submit).
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

    {{-- Renewal: new term entry, shared by the copy-forward and manual-upload paths. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Renew this lease</h2>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs font-medium">New rent (R)</label>
                <input form="renewal-term-form" type="number" name="rental_amount" step="0.01" min="0" required value="{{ $lease->rental_amount }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Deposit (R)</label>
                <input form="renewal-term-form" type="number" name="deposit_amount" step="0.01" min="0" value="{{ $lease->deposit_amount }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">New start date</label>
                <input form="renewal-term-form" type="date" name="start_date" required value="{{ $lease->end_date ? $lease->end_date->copy()->addDay()->toDateString() : '' }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">New end date</label>
                <input form="renewal-term-form" type="date" name="end_date" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if($canCopyForward)
                <form id="renewal-term-form" method="POST" action="{{ route('corex.leases.renewal.draft', $lease) }}">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">Prepare e-sign renewal</button>
                </form>
            @else
                <p class="text-xs" style="color: var(--text-muted);">This lease wasn't e-signed through CoreX — prepare the renewal document outside CoreX, then upload it signed below.</p>
            @endif
        </div>
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
                <span>{{ $lease->notice_given_by === 'tenant' ? 'Tenant gave notice' : 'Landlord not renewing' }} — move-out {{ optional($lease->move_out_date)->format('d M Y') }}</span>
                <form method="POST" action="{{ route('corex.leases.renewal.notice.reverse', $lease) }}">
                    @csrf
                    <button type="submit" class="text-xs" style="color: var(--ds-crimson);">Reverse</button>
                </form>
            </div>
        @else
            <form method="POST" action="{{ route('corex.leases.renewal.tenant-notice', $lease) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="text-xs font-medium">Move-out date</label>
                    <input type="date" name="move_out_date" required class="rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <button type="submit" class="corex-btn-secondary text-xs">Tenant gave notice</button>
            </form>
            <form method="POST" action="{{ route('corex.leases.renewal.landlord-notice', $lease) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="text-xs font-medium">End date</label>
                    <input type="date" name="move_out_date" required class="rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
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
