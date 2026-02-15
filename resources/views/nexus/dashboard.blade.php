@extends('layouts.nexus')

@section('nexus-content')
    {{-- KPI Cards --}}
    <div class="nexus-kpi-grid mb-6">
        <x-nexus-kpi-card
            title="Active Deals"
            :value="$activeDeals"
            :trend="$dealsTrend"
            :trend-up="$dealsTrend >= 0"
            icon-bg="bg-indigo-100 text-indigo-600"
        >
            <x-slot:icon>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                </svg>
            </x-slot:icon>
        </x-nexus-kpi-card>

        <x-nexus-kpi-card
            title="Active Listings"
            :value="$activeListings"
            :trend="0"
            :trend-up="true"
            icon-bg="bg-emerald-100 text-emerald-600"
        >
            <x-slot:icon>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
            </x-slot:icon>
        </x-nexus-kpi-card>

        <x-nexus-kpi-card
            title="Revenue"
            :value="'R ' . number_format($revenue, 0, '.', ',')"
            :trend="$revenueTrend"
            :trend-up="$revenueTrend >= 0"
            icon-bg="bg-amber-100 text-amber-600"
        >
            <x-slot:icon>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </x-slot:icon>
        </x-nexus-kpi-card>

        <x-nexus-kpi-card
            title="Pending Deals"
            :value="$pendingDeals"
            :trend="0"
            :trend-up="false"
            icon-bg="bg-red-100 text-red-600"
        >
            <x-slot:icon>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </x-slot:icon>
        </x-nexus-kpi-card>
    </div>

    {{-- Chart + Panels --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        {{-- Transaction Volume Chart --}}
        <div class="xl:col-span-2 nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">Transaction Volume</h3>
                <span class="text-xs text-gray-500">Last 6 months</span>
            </div>
            <div class="nexus-panel-body">
                <div class="nexus-chart-container"
                     x-data
                     x-init="NexusCharts.transactionVolume('txVolumeChart', @js($chartData->keys()->toArray()), @js($chartData->values()->toArray()))">
                    <canvas id="txVolumeChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Approval Queue --}}
        <div class="nexus-panel">
            <div class="nexus-panel-header">
                <h3 class="nexus-panel-title">Approval Queue</h3>
                <a href="{{ route('admin.deals') }}" class="text-xs text-indigo-600 font-medium hover:underline">View all</a>
            </div>
            <div class="nexus-panel-body">
                @forelse($approvalQueue as $deal)
                    <div class="nexus-queue-item">
                        <div>
                            <div class="nexus-queue-label">{{ $deal->deal_no ?: ('Deal #' . $deal->id) }}</div>
                            <div class="nexus-queue-sub">{{ Str::limit($deal->property_address, 30) }}</div>
                        </div>
                        @php
                            $badgeClass = match($deal->commission_status) {
                                'Granted' => 'nexus-badge-green',
                                'Pending' => 'nexus-badge-yellow',
                                'Declined' => 'nexus-badge-red',
                                'Registered' => 'nexus-badge-blue',
                                default => 'nexus-badge-yellow',
                            };
                        @endphp
                        <span class="nexus-badge {{ $badgeClass }}">{{ $deal->commission_status }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 py-4 text-center">No items in queue</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recent Activity --}}
    <div class="nexus-panel">
        <div class="nexus-panel-header">
            <h3 class="nexus-panel-title">Recent Activity</h3>
        </div>
        <div class="nexus-panel-body">
            @forelse($recentActivity as $log)
                <div class="nexus-activity-item">
                    <div class="nexus-activity-dot"></div>
                    <div>
                        <div class="nexus-activity-text">
                            <strong>{{ $log->actor?->name ?? 'System' }}</strong>
                            {{ $log->event_type }}
                            @if($log->deal)
                                on deal <strong>{{ $log->deal->deal_no ?: ('#' . $log->deal_id) }}</strong>
                            @endif
                            @if($log->message)
                                &mdash; {{ Str::limit($log->message, 60) }}
                            @endif
                        </div>
                        <div class="nexus-activity-time">{{ $log->created_at?->diffForHumans() ?? '' }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-400 py-4 text-center">No recent activity</p>
            @endforelse
        </div>
    </div>
@endsection
