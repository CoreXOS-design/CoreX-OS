@extends('public.auctions._layout')
@php
    $property = $lot->property;
    $images = $property?->publicGalleryUrls() ?? [];
    // 2.0 -> 2, 2.5 stays 2.5; the label follows the count (1 Garage, 2 Garages).
    $room = fn ($n, string $label) => '<strong>'.(float) $n.'</strong> '.((float) $n === 1.0 ? $label : \Illuminate\Support\Str::plural($label));
@endphp
@section('title', 'Lot '.$lot->lot_number.' — '.($property?->headline ?: $property?->title ?: $auction->title))
@if(!empty($images))@section('og_image', $images[0])@endif
@section('content')
<a href="{{ route('public.auctions.show', $auction->id) }}" class="text-sm text-slate-500">← {{ $auction->title }}</a>
<div class="grid gap-6 md:grid-cols-3 mt-3">
    <div class="md:col-span-2 space-y-4">
        @if(!empty($images))
        <div class="grid grid-cols-3 gap-2">
            @foreach(array_slice($images, 0, 9) as $i => $url)
            <img src="{{ $url }}" alt="" class="rounded object-cover w-full h-32 {{ $i === 0 ? 'col-span-3 h-72' : '' }}">
            @endforeach
        </div>
        @endif
        <div>
            <div class="text-xs text-slate-400">Lot {{ $lot->lot_number }}</div>
            <h1 class="text-2xl font-bold">{{ $property?->headline ?: $property?->title ?: $property?->address }}</h1>
            <div class="text-slate-500">{{ collect([$property?->suburb, $property?->city])->filter()->unique()->implode(', ') }}</div>
        </div>
        @include('public.auctions._lot-facts')
        <div class="flex flex-wrap gap-5 text-sm border-y border-slate-200 py-3">
            @if($property?->beds)<div>{!! $room($property->beds, 'Bedroom') !!}</div>@endif
            @if($property?->baths)<div>{!! $room($property->baths, 'Bathroom') !!}</div>@endif
            @if($property?->garages)<div>{!! $room($property->garages, 'Garage') !!}</div>@endif
            @if($property?->size_m2)<div><strong>{{ $property->size_m2 }}</strong> m² floor</div>@endif
            @if($property?->erf_size_m2)<div><strong>{{ $property->erf_size_m2 }}</strong> m² erf</div>@endif
        </div>
        <p class="text-sm whitespace-pre-line">{{ $property?->description }}</p>

        @if(! in_array($lot->status, \App\Models\AuctionLot::CONCLUDED_STATUSES, true) && $lot->upcomingViewings->isNotEmpty())
        <div>
            <h2 class="font-semibold mb-1">Viewings</h2>
            <ul class="text-sm text-slate-600 space-y-1">
                @foreach($lot->upcomingViewings as $v)
                <li>{{ $v->starts_at->format('D, d M Y H:i') }} – {{ $v->ends_at->format('H:i') }}@if($v->is_by_appointment) (by appointment)@endif</li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>

    <aside class="space-y-4">
        @include('public.auctions._auctioneer')

        @if(! in_array($lot->status, ['sold', 'withdrawn'], true))
        <form method="POST" action="{{ route('public.auctions.enquire', [$auction->id, $lot->id]) }}" class="rounded-xl bg-white border border-slate-200 p-4 space-y-2 text-sm">
            @csrf
            <div class="font-semibold">I'm interested in this lot</div>
            <input type="text" name="name" value="{{ old('name') }}" placeholder="Your name *" required class="w-full rounded border border-slate-300 px-3 py-2">
            <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" class="w-full rounded border border-slate-300 px-3 py-2">
            <input type="text" name="phone" value="{{ old('phone') }}" placeholder="Phone" class="w-full rounded border border-slate-300 px-3 py-2">
            <textarea name="message" rows="3" placeholder="Message (optional)" class="w-full rounded border border-slate-300 px-3 py-2">{{ old('message') }}</textarea>
            <label class="flex gap-2 text-xs text-slate-500"><input type="checkbox" name="popia_consent" value="1" required> I agree that {{ $agency->name }} may contact me about this property and store my details (POPIA).</label>
            <button type="submit" class="rounded bg-slate-900 text-white font-semibold px-4 py-2 w-full">Send enquiry</button>
        </form>
        @endif

        @if($property?->agent)
        <div class="rounded-xl bg-white border border-slate-200 p-4 text-sm">
            <div class="text-xs text-slate-400">Marketed by</div>
            <div class="font-semibold">{{ $property->agent->name }}</div>
            @if($property->agent->email)<a href="mailto:{{ $property->agent->email }}" class="underline">{{ $property->agent->email }}</a>@endif
        </div>
        @endif
    </aside>
</div>
@endsection
