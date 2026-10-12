<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register to Bid — {{ $auction->title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="w-full max-w-lg mx-auto py-8">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <span class="inline-block text-xs font-semibold tracking-wide uppercase bg-amber-100 text-amber-800 rounded px-2 py-1 mb-2">Auction</span>
            <h1 class="text-xl font-bold text-slate-800 mb-1">{{ $auction->title }}</h1>
            <p class="text-sm text-slate-500 mb-4">
                {{ $auction->starts_at?->format('d M Y, H:i') }}
                @if($auction->venue_name) &middot; {{ $auction->venue_name }} @endif
            </p>

            @if($auction->lots->isNotEmpty())
            <div class="border-t border-slate-100 pt-4 mb-4">
                <h2 class="text-sm font-semibold text-slate-700 mb-2">Lots on offer</h2>
                <ul class="text-sm text-slate-600 space-y-1">
                    @foreach($auction->lots as $lot)
                    <li>
                        Lot {{ $lot->lot_number }} — {{ $lot->property?->address ?? $lot->property?->title ?? 'Property #'.$lot->property_id }}
                        @if($lot->guide_price_min || $lot->guide_price_max)
                            <span class="text-slate-400">
                                (Guide: R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }})
                            </span>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if(session('status'))
            <div class="rounded bg-green-50 text-green-800 px-3 py-2 text-sm mb-4">{{ session('status') }}</div>
            @endif
            @if($errors->any())
            <div class="rounded bg-red-50 text-red-800 px-3 py-2 text-sm mb-4">{{ $errors->first() }}</div>
            @endif

            @if(! $registrationOpen)
            <div class="rounded bg-slate-100 text-slate-700 px-3 py-3 text-sm">
                Registration for this auction is not currently open. If you believe this is a mistake, please contact the agency directly.
            </div>
            @else
            <p class="text-sm text-slate-600 mb-4">
                Register below to receive a paddle. Before the sale, our team will verify your FICA documentation
                @if(!empty($ficaChecklist)) ({{ implode(', ', $ficaChecklist) }}) @endif
                and ask you to sign the Rules of Auction — we'll be in touch to complete both.
            </p>

            <form method="POST" action="{{ route('public.auctions.register.store', $auction->id) }}" class="space-y-3" x-data="{ biddingFor: 'self' }">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">First name *</label>
                        <input type="text" name="first_name" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Last name</label>
                        <input type="text" name="last_name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Email</label>
                        <input type="email" name="email" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Phone</label>
                        <input type="text" name="phone" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <p class="text-xs text-slate-400">Enter at least an email address or a phone number.</p>

                @if($entityBiddersAllowed)
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Bidding as</label>
                    <select name="bidding_for" x-model="biddingFor" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="self">Myself</option>
                        <option value="entity">A company / trust / entity</option>
                    </select>
                </div>
                <div x-show="biddingFor === 'entity'">
                    <label class="block text-xs text-slate-500 mb-1">Entity name</label>
                    <input type="text" name="entity_name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                @else
                <input type="hidden" name="bidding_for" value="self">
                @endif

                <label class="flex items-start gap-2 text-xs text-slate-600 pt-2">
                    <input type="checkbox" name="popia_consent" value="1" required class="mt-0.5">
                    <span>I consent to my details being processed to register me for this auction, in line with POPIA and the agency's privacy policy.</span>
                </label>

                <button type="submit" class="w-full rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold py-2.5 mt-2">
                    Register to Bid
                </button>
            </form>
            @endif
        </div>
    </div>
</body>
</html>
