@extends('layouts.corex')

@section('corex-content')
@php
    $a = $lead->answers ?? [];
    $money = fn ($v) => ($v === null || $v === '') ? '—' : 'R ' . number_format((float) $v, 0, '.', ' ');
    $yn = fn ($v) => $v ? 'Yes' : 'No';
    $featureLabels = ['pool' => 'Pool', 'borehole' => 'Borehole', 'solar' => 'Solar power', 'garden_flat' => 'Garden flat', 'electric_fence' => 'Electric fence', 'fibre' => 'Fibre ready', 'staff_quarters' => 'Staff quarters', 'double_garage' => 'Double garage'];
    $docLabels = ['title_deed' => 'Title deed copy', 'rates_account' => 'Latest rates account', 'building_plans' => 'Approved building plans', 'levy_statement' => 'Levy statement', 'lease_agreement' => 'Lease agreement', 'bond_statement' => 'Bond statement', 'id_copies' => 'ID copies'];
    $occ = ['owner' => 'Owner occupied', 'tenant' => 'Tenanted', 'vacant' => 'Vacant'];
    $cond = ['excellent' => 'Excellent - move-in ready', 'good' => 'Good - minor wear', 'fair' => 'Fair - some repairs needed', 'needs_work' => 'Needs significant work'];
    $timing = ['asap' => 'As soon as possible', '1_3_months' => 'Within 1-3 months', '3_6_months' => 'Within 3-6 months', 'exploring' => 'Just exploring'];
    $viewing = ['most_days' => 'Available most days', 'appointment' => 'By appointment', 'restricted' => 'Restricted (tenant or security)'];
    $S = \App\Models\AuctionSellerSubmission::class;
    $sections = [
        '1. About the seller' => [
            ['Selling as', $S::SELLER_TYPES[$lead->seller_type] ?? $lead->seller_type],
            ['Spouse', $a['spouse_name'] ?? null], ['Executor', $a['executor_name'] ?? null],
            ['Name', $lead->name], ['Email', $lead->email], ['Mobile', $lead->phone], ['Best way to reach', $S::CONTACT_METHODS[$lead->preferred_contact] ?? '—'],
        ],
        '2. The property' => [
            ['Type', $S::PROPERTY_TYPES[$lead->property_type] ?? $lead->property_type], ['Address', $lead->address . ', ' . $lead->suburb], ['Zoning', $a['zoning'] ?? null],
            ['Erf size', isset($a['erf_size']) ? $a['erf_size'] . ' m²' : null], ['Floor size', isset($a['floor_size']) ? $a['floor_size'] . ' m²' : null],
            ['Bedrooms', $a['bedrooms'] ?? null], ['Bathrooms', $a['bathrooms'] ?? null],
            ['Features', collect($a['features'] ?? [])->map(fn ($f) => $featureLabels[$f] ?? $f)->implode(', ') ?: null],
        ],
        '3. Occupancy' => [
            ['Who lives there', $occ[$a['occupancy'] ?? ''] ?? null], ['Monthly rent', ($a['occupancy'] ?? '') === 'tenant' ? $money($a['monthly_rent'] ?? null) : null],
            ['Condition', $cond[$a['condition'] ?? ''] ?? null], ['Known defects', $a['defects'] ?? null],
        ],
        '4. Bond & finances' => [
            ['Bond registered', $yn($a['has_bond'] ?? false)], ['Bank', $a['bond_bank'] ?? null], ['Outstanding', ($a['has_bond'] ?? false) ? $money($a['bond_amount'] ?? null) : null],
            ['In arrears', ($a['has_bond'] ?? false) ? $yn($a['bond_arrears'] ?? false) : null],
            ['Monthly levies', $money($a['levies'] ?? null)], ['Municipal valuation', $money($a['municipal_valuation'] ?? null)], ['Price hoped for', $money($lead->price_expectation)],
        ],
        '5. Auction options' => [
            ['Format', $S::FORMATS[$lead->auction_format] ?? null], ['Reserve', ($a['reserve'] ?? '') === 'confidential' ? 'Confidential reserve' : 'No reserve'],
            ['Wants to sell', $timing[$a['timing'] ?? ''] ?? null], ['Viewing', $viewing[$a['viewing'] ?? ''] ?? null], ['Reason for selling', $a['sale_reason'] ?? null],
        ],
        '6. Documents' => [
            ['Has', collect($a['documents'] ?? [])->map(fn ($d) => $docLabels[$d] ?? $d)->implode(', ') ?: 'None ticked'], ['Heard about us', $a['referral_source'] ?? null],
        ],
        '7. Consent' => [
            ['Terms, authority and POPIA', 'Accepted'], ['News about auctions', $yn($lead->marketing_opt_in)],
        ],
    ];
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-5xl">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('corex.auctions.sellers.index') }}" class="text-xs underline" style="color:var(--text-muted);">← Seller leads</a>
            <h1 class="text-base font-bold leading-tight mt-1" style="color:var(--text-primary);">{{ $lead->name }} <span class="font-normal" style="color:var(--text-muted);">· {{ $lead->trashed() ? 'Archived' : $lead->statusLabel() }}</span></h1>
            <p class="text-xs" style="color:var(--text-muted);">Received {{ $lead->created_at->format('d M Y H:i') }} · {{ $S::PROPERTY_TYPES[$lead->property_type] ?? $lead->property_type }} in {{ $lead->suburb }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($lead->contact_id)<a href="{{ route('corex.contacts.show', $lead->contact_id) }}" class="corex-btn-outline text-xs px-3 py-2">Open the contact</a>@endif
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    @if(auth()->user()->hasPermission('auctions.sellers.manage'))
    <div class="rounded-md p-4 flex flex-wrap items-end gap-4" style="background:var(--surface);border:1px solid var(--border);" data-lead-actions>
        @unless($lead->trashed())
        <form method="POST" action="{{ route('corex.auctions.sellers.update', $lead->id) }}" class="flex flex-wrap items-end gap-3">
            @csrf @method('PUT')
            <div><label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Status</label>
                <select name="status" class="px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">@foreach($S::STATUSES as $s)<option value="{{ $s }}" @selected($lead->status === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
            <div><label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Assigned to</label>
                <select name="assigned_user_id" class="px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"><option value="">Nobody yet</option>@foreach($agents as $u)<option value="{{ $u->id }}" @selected((int) $lead->assigned_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Save</button>
        </form>
        <form method="POST" action="{{ route('corex.auctions.sellers.archive', $lead->id) }}" onsubmit="return confirm('Archive this lead? You can restore it later.')">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline text-sm px-4 py-2">Archive</button></form>
        @else
        <form method="POST" action="{{ route('corex.auctions.sellers.restore', $lead->id) }}">@csrf<button type="submit" class="corex-btn-primary text-sm px-4 py-2">Restore this lead</button></form>
        @endunless
    </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($sections as $title => $rows)
        <section class="rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold mb-3" style="color:var(--text-primary);">{{ $title }}</h2>
            <dl class="text-sm space-y-2">
                @foreach($rows as [$label, $value])
                    @continue($value === null || $value === '')
                    <div class="flex justify-between gap-4"><dt style="color:var(--text-muted);">{{ $label }}</dt><dd class="text-right" style="white-space:pre-line;">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
        @endforeach
    </div>
</div>
@endsection
