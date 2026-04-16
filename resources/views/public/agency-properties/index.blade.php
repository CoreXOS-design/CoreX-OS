<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $agency->name }} — Properties</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(!empty($agency->sidebar_color))
    <style>
        :root {
            --brand-sidebar: {{ $agency->sidebar_color ?? '#0ea5e9' }};
            --brand-icon:    {{ $agency->icon_color ?? '#0ea5e9' }};
            --brand-default: {{ $agency->default_color ?? '#0b2a4a' }};
            --brand-button:  {{ $agency->button_color ?? '#0ea5e9' }};
        }
    </style>
    @endif
</head>
<body class="bg-surface text-default font-sans">

<header class="px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
    <div class="max-w-7xl mx-auto flex items-center justify-between">
        <div class="flex items-center gap-3">
            @if($agency->logo_path)
                <img src="{{ asset('storage/'.$agency->logo_path) }}" alt="{{ $agency->name }}" class="h-10 w-auto">
            @endif
            <div>
                <h1 class="text-xl font-bold text-white">{{ $agency->name }}</h1>
                <div class="text-xs text-white/60">Properties for sale & rent</div>
            </div>
        </div>
    </div>
</header>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <form method="GET" class="mb-6 flex flex-wrap gap-3 items-center">
        <select name="type" class="rounded-md bg-surface-2 border border-subtle px-3 py-2 text-sm">
            <option value="">All types</option>
            <option value="Sale"   @selected(request('type') === 'Sale')>For Sale</option>
            <option value="Rental" @selected(request('type') === 'Rental')>To Rent</option>
        </select>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search suburb, street, title…"
               class="rounded-md bg-surface-2 border border-subtle px-3 py-2 text-sm flex-1 min-w-[220px]">
        <button type="submit" class="rounded-md px-4 py-2 text-sm text-white" style="background:var(--brand-button, #0ea5e9);">Search</button>
    </form>

    @if($properties->isEmpty())
        <div class="rounded-md bg-surface-2 p-10 text-center text-muted">
            No properties available right now.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @foreach ($properties as $p)
                @php
                    $images = is_array($p->images_json) ? $p->images_json : (json_decode($p->images_json ?? '[]', true) ?: []);
                    $cover  = $images[0] ?? null;
                @endphp
                <a href="{{ route('public.agency.properties.show', [$agency->slug, $p->id]) }}"
                   class="rounded-md bg-surface-2 border border-subtle/40 overflow-hidden block hover:border-subtle transition">
                    <div class="aspect-[4/3] bg-surface overflow-hidden">
                        @if($cover)
                            <img src="{{ asset('storage/'.$cover) }}" alt="" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-muted text-xs">No image</div>
                        @endif
                    </div>
                    <div class="p-4">
                        <div class="text-sm text-muted">{{ $p->suburb ?? $p->town ?? '' }}</div>
                        <div class="font-semibold mt-1 line-clamp-1">{{ $p->headline ?? $p->title ?? 'Property' }}</div>
                        <div class="mt-2 font-bold" style="color:var(--brand-icon, #0ea5e9);">
                            @if($p->listing_type === 'Rental')
                                R {{ number_format((float) ($p->rental_amount ?? $p->price ?? 0), 0, '.', ',') }} <span class="text-xs text-muted font-normal">/ month</span>
                            @else
                                R {{ number_format((float) ($p->price ?? 0), 0, '.', ',') }}
                            @endif
                        </div>
                        <div class="flex items-center gap-3 mt-3 text-xs text-muted">
                            @if($p->beds) <span>{{ $p->beds }} bed</span> @endif
                            @if($p->baths) <span>{{ $p->baths }} bath</span> @endif
                            @if($p->garages) <span>{{ $p->garages }} grg</span> @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">{{ $properties->links() }}</div>
    @endif
</section>

<footer class="px-6 py-6 text-center text-xs text-muted">
    Powered by CoreX OS
</footer>

</body>
</html>
