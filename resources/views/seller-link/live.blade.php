<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $property->title ?? 'Property' }} — Live Marketing Update</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Figtree', sans-serif; background: #0f172a; color: #e2e8f0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; }
    </style>
</head>
<body class="min-h-screen">
    <div class="max-w-4xl mx-auto px-4 py-8">
        {{-- Header --}}
        <div class="mb-8">
            @if($agency && $agency->logo_path)
                <img src="{{ asset($agency->logo_path) }}" alt="{{ $agency->name }}" class="h-8 mb-4">
            @endif
            <h1 class="text-2xl font-bold text-white">{{ $property->title ?? 'Your Property' }}</h1>
            <p class="text-sm text-slate-400 mt-1">Live Marketing Update</p>
            <p class="text-xs text-slate-500 mt-2">Hi {{ $seller->first_name ?? 'Seller' }}, here's what's happening with your listing.</p>
            <p class="text-[10px] text-slate-600 mt-1">Last refreshed: <span id="refresh-time">just now</span></p>
        </div>

        {{-- Performance Dashboard --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $feedbackRollup['total_viewings'] }}</div>
                <div class="text-[10px] uppercase text-slate-500 mt-1">Viewings</div>
            </div>
            <div class="card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $compliance['days_on_market'] ?? '—' }}</div>
                <div class="text-[10px] uppercase text-slate-500 mt-1">Days Listed</div>
            </div>
            @if($marketPosition)
            <div class="card p-4 text-center">
                <div class="text-lg font-bold text-white">R {{ number_format($marketPosition['recommended_price'] ?? 0) }}</div>
                <div class="text-[10px] uppercase text-slate-500 mt-1">Market Value</div>
            </div>
            <div class="card p-4 text-center">
                <div class="text-lg font-bold text-white">R {{ number_format($marketPosition['area_avg_price'] ?? 0) }}</div>
                <div class="text-[10px] uppercase text-slate-500 mt-1">Area Average</div>
            </div>
            @endif
        </div>

        {{-- Agent Insights (seller-facing recommendations) --}}
        @if($recommendations->isNotEmpty())
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Agent Insights</h2>
            <div class="space-y-3">
                @foreach($recommendations as $rec)
                    <div class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-teal-400 mt-1.5 flex-shrink-0"></div>
                        <div>
                            <div class="text-sm text-slate-200">{{ $rec->seller_facing_title }}</div>
                            @if($rec->seller_facing_reasoning)
                                <div class="text-xs text-slate-400 mt-0.5">{{ $rec->seller_facing_reasoning }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Feedback Summary --}}
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Viewing Feedback Summary</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-slate-400">Total viewings:</span> <span class="text-white font-medium">{{ $feedbackRollup['total_viewings'] }}</span></div>
                <div><span class="text-slate-400">Feedback captured:</span> <span class="text-white font-medium">{{ $feedbackRollup['total_feedback_rows'] }}</span></div>
            </div>
        </div>

        {{-- Marketing Activity --}}
        @if($marketing->isNotEmpty())
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Marketing Activity</h2>
            <div class="space-y-2">
                @foreach($marketing as $ma)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="text-[10px] w-20 flex-shrink-0 text-slate-500">{{ $ma->occurred_at->format('d M') }}</span>
                        <span class="text-slate-300">{{ str_replace('_', ' ', ucfirst($ma->activity_type)) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Comparable Listings --}}
        @if($comparables->isNotEmpty())
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Similar Properties in Your Area</h2>
            <div class="space-y-2">
                @foreach($comparables as $comp)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-300">{{ $comp['title'] }} <span class="text-slate-500 text-xs">{{ $comp['suburb'] }}</span></span>
                        <span class="text-slate-400">R {{ number_format($comp['price'] ?? 0) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Presentations --}}
        @if($presentations->isNotEmpty())
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Market Presentation</h2>
            @php $latest = $presentations->first(); @endphp
            <p class="text-sm text-slate-300">{{ $latest->title }}</p>
            <p class="text-xs text-slate-500 mt-1">Generated {{ \Carbon\Carbon::parse($latest->created_at)->format('d M Y') }}</p>
        </div>
        @endif

        {{-- Compliance Status --}}
        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Listing Status</h2>
            <div class="flex flex-wrap gap-2">
                <span class="text-[10px] px-2 py-1 rounded font-medium" style="background: {{ ($compliance['published'] ?? false) ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' }}; color: {{ ($compliance['published'] ?? false) ? '#10b981' : '#ef4444' }};">
                    Listing: {{ ($compliance['published'] ?? false) ? 'Active' : 'Unpublished' }}
                </span>
                <span class="text-[10px] px-2 py-1 rounded font-medium" style="background: {{ ($compliance['mandate_expired'] ?? true) ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)' }}; color: {{ ($compliance['mandate_expired'] ?? true) ? '#ef4444' : '#10b981' }};">
                    Mandate: {{ ($compliance['mandate_expired'] ?? true) ? 'Review needed' : 'Active' }}
                </span>
            </div>
        </div>

        {{-- Footer --}}
        <div class="text-center pt-8 pb-4" style="border-top: 1px solid #334155;">
            @php $agent = $link->generatedBy; @endphp
            @if($agent)
                <p class="text-sm text-slate-400">Questions? Contact your agent:</p>
                <p class="text-sm text-white font-medium mt-1">{{ $agent->name }}</p>
                @if($agent->email)
                    <a href="mailto:{{ $agent->email }}" class="text-xs text-teal-400 hover:underline">{{ $agent->email }}</a>
                @endif
            @endif
            <p class="text-[10px] text-slate-600 mt-6">Powered by CoreX OS — {{ $agency->name ?? 'Real Estate Operating System' }}</p>
        </div>
    </div>

    <script>
        // Auto-refresh indicator
        setInterval(() => {
            document.getElementById('refresh-time').textContent = 'refreshing...';
            setTimeout(() => window.location.reload(), 500);
        }, 60000);
    </script>
</body>
</html>
