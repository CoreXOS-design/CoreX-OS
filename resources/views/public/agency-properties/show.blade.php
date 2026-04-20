<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $property->headline ?? $property->title ?? 'Property' }} — {{ $agency->name }}</title>
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

@php
    $images = is_array($property->images_json) ? $property->images_json : (json_decode($property->images_json ?? '[]', true) ?: []);
@endphp

<header class="px-6 py-4" style="background:var(--brand-default, #0b2a4a);">
    <div class="max-w-7xl mx-auto flex items-center justify-between">
        <a href="{{ route('public.agency.properties.index', $agency->slug) }}" class="text-white/80 hover:text-white text-sm">← Back to {{ $agency->name }}</a>
    </div>
</header>

<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach (array_slice($images, 0, 9) as $i => $img)
            <div class="{{ $i === 0 ? 'md:col-span-2 md:row-span-2 aspect-[4/3]' : 'aspect-[4/3]' }} bg-surface-2 rounded-md overflow-hidden">
                <img src="{{ asset('storage/'.$img) }}" class="w-full h-full object-cover">
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-2 space-y-4">
            <div>
                <div class="text-sm text-muted">{{ $property->suburb ?? $property->town ?? '' }}</div>
                <h1 class="text-2xl font-bold mt-1">{{ $property->headline ?? $property->title ?? 'Property' }}</h1>
                <div class="mt-2 text-2xl font-bold" style="color:var(--brand-icon, #0ea5e9);">
                    @if($property->listing_type === 'Rental')
                        R {{ number_format((float) ($property->rental_amount ?? $property->price ?? 0), 0, '.', ',') }} <span class="text-base text-muted font-normal">/ month</span>
                    @else
                        R {{ number_format((float) ($property->price ?? 0), 0, '.', ',') }}
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-5 text-sm border-y border-subtle/30 py-3">
                @if($property->beds) <div><span class="font-bold">{{ $property->beds }}</span> <span class="text-muted">Bedrooms</span></div> @endif
                @if($property->baths) <div><span class="font-bold">{{ $property->baths }}</span> <span class="text-muted">Bathrooms</span></div> @endif
                @if($property->garages) <div><span class="font-bold">{{ $property->garages }}</span> <span class="text-muted">Garages</span></div> @endif
                @if($property->size_m2) <div><span class="font-bold">{{ $property->size_m2 }}</span> <span class="text-muted">m² floor</span></div> @endif
                @if($property->erf_size_m2) <div><span class="font-bold">{{ $property->erf_size_m2 }}</span> <span class="text-muted">m² erf</span></div> @endif
            </div>

            <div class="prose prose-invert max-w-none whitespace-pre-line text-sm">
                {{ $property->description }}
            </div>
        </div>

        <aside class="space-y-4">
            @if($property->agent)
                <div class="rounded-md bg-surface-2 border border-subtle/30 p-4">
                    <div class="text-xs text-muted">Marketed by</div>
                    <div class="flex items-center gap-3 mt-2">
                        @if($property->agent->profilePhotoUrl())
                            <img src="{{ $property->agent->profilePhotoUrl() }}" class="w-12 h-12 rounded-full object-cover">
                        @else
                            <div class="w-12 h-12 rounded-full flex items-center justify-center text-sm font-bold" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">{{ $property->agent->initials() }}</div>
                        @endif
                        <div>
                            <div class="font-semibold">{{ $property->agent->name }}</div>
                            @if($property->agent->email)
                                <a href="mailto:{{ $property->agent->email }}" class="text-xs" style="color:var(--brand-icon, #0ea5e9);">{{ $property->agent->email }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </aside>
    </div>

</section>

<footer class="px-6 py-6 text-center text-xs text-muted">Powered by CoreX OS</footer>

</body>
</html>
