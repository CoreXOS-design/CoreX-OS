<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Property Matches</title>
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>body { font-family: 'Figtree', sans-serif; background: #0f172a; color: #e2e8f0; } .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; }</style>
</head>
<body class="min-h-screen">
<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-white">Hi {{ $buyer->first_name ?? 'there' }}, here are your matches</h1>
        <p class="text-xs text-slate-500 mt-1">Updated: {{ now()->format('d M Y, H:i') }}</p>
    </div>

    {{-- Preferences summary --}}
    @if($primaryMatch)
    <div class="card p-4 mb-6">
        <h2 class="text-xs font-semibold uppercase text-slate-500 mb-2">Your Preferences</h2>
        <div class="flex flex-wrap gap-3 text-sm text-slate-300">
            @if($primaryMatch->price_min || $primaryMatch->price_max)
                <span>Budget: R {{ number_format($primaryMatch->price_min ?? 0) }} – R {{ number_format($primaryMatch->price_max ?? 0) }}</span>
            @endif
            @php $areas = $primaryMatch->suburbList(); @endphp
            @if(!empty($areas))
                <span>Areas: {{ implode(', ', $areas) }}</span>
            @endif
        </div>
    </div>
    @endif

    {{-- Perfect Matches --}}
    @php $perfect = $matches->where('tier', 'perfect'); @endphp
    @if($perfect->isNotEmpty())
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-white mb-3">Perfect Matches <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background: #10b98120; color: #10b981;">{{ $perfect->count() }}</span></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach($perfect as $match)
                @php $prop = $properties[$match->property_id] ?? null; $resp = $responses[$match->property_id] ?? null; @endphp
                @if($prop)
                @include('buyer-portal._property-card', ['prop' => $prop, 'match' => $match, 'resp' => $resp, 'token' => $token])
                @endif
            @endforeach
        </div>
    </div>
    @endif

    {{-- Strong Matches --}}
    @php $strong = $matches->where('tier', 'strong'); @endphp
    @if($strong->isNotEmpty())
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-white mb-3">Strong Matches <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background: #00d4aa20; color: #00d4aa;">{{ $strong->count() }}</span></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach($strong as $match)
                @php $prop = $properties[$match->property_id] ?? null; $resp = $responses[$match->property_id] ?? null; @endphp
                @if($prop)
                @include('buyer-portal._property-card', ['prop' => $prop, 'match' => $match, 'resp' => $resp, 'token' => $token])
                @endif
            @endforeach
        </div>
    </div>
    @endif

    {{-- Approximate Matches --}}
    @php $approx = $matches->where('tier', 'approximate'); @endphp
    @if($approx->isNotEmpty())
    <details class="mb-6">
        <summary class="text-sm font-semibold text-slate-400 cursor-pointer">Show {{ $approx->count() }} approximate matches</summary>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            @foreach($approx as $match)
                @php $prop = $properties[$match->property_id] ?? null; $resp = $responses[$match->property_id] ?? null; @endphp
                @if($prop)
                @include('buyer-portal._property-card', ['prop' => $prop, 'match' => $match, 'resp' => $resp, 'token' => $token])
                @endif
            @endforeach
        </div>
    </details>
    @endif

    {{-- Viewed Properties --}}
    @if($viewed->isNotEmpty())
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-white mb-3">Properties You've Viewed</h2>
        <div class="space-y-2">
            @foreach($viewed as $v)
                <div class="card px-4 py-3 flex items-center justify-between">
                    <div>
                        <span class="text-sm text-slate-200">{{ $v->property?->title ?? 'Property' }}</span>
                        <span class="text-xs text-slate-500 ml-2">Viewed {{ $v->view_count }}x · Last: {{ $v->last_viewed_at?->diffForHumans() }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="text-center pt-8" style="border-top: 1px solid #334155;">
        <p class="text-[10px] text-slate-600">Powered by CoreX OS — {{ $agency->name ?? '' }}</p>
    </div>
</div>
</body>
</html>
