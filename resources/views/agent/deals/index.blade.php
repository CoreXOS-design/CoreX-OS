<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">My Deals</h2>
                    <div class="text-sm text-white/60">Deals where you are allocated on listing and/or selling side.</div>
                </div>
                <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/20">
                    {{ $deals->count() }} deals
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div>
            <h2 class="ds-section-header">Deal Register</h2>
            <div class="ds-section-sub mb-4">Read-only. You can add remarks in the log.</div>

            <div class="ds-status-card overflow-hidden" style="padding:0">
                <div class="table-scroll">
                    <table class="w-full text-sm ds-table table-sticky">
                        <thead>
                            <tr>
                                <th class="text-left px-5 py-3">Deal</th>
                                <th class="text-left px-5 py-3">Property</th>
                                <th class="text-left px-5 py-3">Branch</th>
                                <th class="text-right px-5 py-3">Selling Price</th>
                                <th class="text-right px-5 py-3">Our Total (Ex VAT)</th>
                                <th class="text-right px-5 py-3">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($deals->sortByDesc('deal_no') as $deal)
                                @php
                                    $b = $branches->firstWhere('id', $deal->branch_id);
                                    $acceptedMap = ['P'=>'Pending','D'=>'Declined','G'=>'Granted','R'=>'Registered'];
                                    $acceptedLabel = $deal->accepted_status
                                        ? ($acceptedMap[$deal->accepted_status] ?? $deal->accepted_status)
                                        : '—';

                                    $asVal = (string)($deal->accepted_status ?? '');
                                    $statusBadge = 'ds-badge-default';
                                    if ($asVal === 'G') $statusBadge = 'ds-badge-success';
                                    elseif ($asVal === 'R') $statusBadge = 'ds-badge-info';
                                    elseif ($asVal === 'D') $statusBadge = 'ds-badge-danger';
                                    elseif ($asVal === 'P') $statusBadge = 'ds-badge-warning';

                                    $csVal = (string)($deal->commission_status ?? '');
                                    $commBadge = 'ds-badge-default';
                                    if ($csVal === 'Paid') $commBadge = 'ds-badge-paid';
                                    elseif ($csVal === 'Not Paid') $commBadge = 'ds-badge-notpaid';
                                    elseif ($csVal === 'Loss') $commBadge = 'ds-badge-loss';
                                @endphp
                                <tr class="hover:bg-gray-50/70">
                                    <td class="px-5 py-4">
                                        <div class="font-bold" style="color:#0b2a4a">#{{ $deal->deal_no }}</div>
                                        <div class="mt-1 flex items-center gap-1.5">
                                            <span class="ds-badge {{ $statusBadge }}">{{ $acceptedLabel }}</span>
                                            <span class="ds-badge {{ $commBadge }}">{{ $csVal ?: '—' }}</span>
                                        </div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="text-gray-900">{{ \Illuminate\Support\Str::limit($deal->property_address, 60) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">{{ $deal->seller_name }} &rarr; {{ $deal->buyer_name }}</div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="font-medium" style="color:#0b2a4a">{{ $b?->name ?? '—' }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">Period: <span class="text-gray-700">{{ $deal->period ?: '—' }}</span></div>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <div class="font-semibold ds-value">R {{ number_format((float)$deal->property_value, 0) }}</div>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <div class="font-semibold ds-value">R {{ number_format((float)$deal->totalOurCommission(), 0) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">Company + agents (Ex VAT)</div>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('agent.deals.log', $deal) }}"
                                           class="corex-btn-primary text-xs px-3 py-1.5">
                                            Log
                                        </a>
                                    </td>
                                </tr>
                            @endforeach

                            @if($deals->isEmpty())
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">No deals allocated to you yet.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
