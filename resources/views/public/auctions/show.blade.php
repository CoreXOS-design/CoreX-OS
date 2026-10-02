@extends('public.auctions._layout')
@section('title', $auction->title)
@section('content')
<div class="grid gap-6 md:grid-cols-3">
    <div class="md:col-span-2 space-y-4">
        <h1 class="text-2xl font-bold">{{ $auction->title }}</h1>
        @if($auction->notes)<p class="text-sm text-slate-600 whitespace-pre-line">{{ $auction->notes }}</p>@endif
        @forelse($lots as $lot)
        @php $img = $lot->property?->publicGalleryUrls()[0] ?? null; @endphp
        <a href="{{ route('public.auctions.lot', [$auction->id, $lot->id]) }}" class="flex gap-4 rounded-xl bg-white border border-slate-200 p-3 hover:border-slate-400">
            @if($img)<img src="{{ $img }}" alt="" class="w-32 h-24 object-cover rounded">@endif
            <div class="space-y-1">
                <div class="text-xs text-slate-400">Lot {{ $lot->lot_number }}</div>
                <div class="font-semibold">{{ $lot->property?->headline ?: $lot->property?->title ?: $lot->property?->address }}</div>
                <div class="text-sm text-slate-500">{{ collect([$lot->property?->suburb, $lot->property?->city])->filter()->implode(', ') }}</div>
                @include('public.auctions._lot-facts', ['lot' => $lot])
            </div>
        </a>
        @empty
        <p class="text-slate-500">Lots will be listed here shortly.</p>
        @endforelse
    </div>
    <aside>@include('public.auctions._auctioneer')</aside>
</div>
@endsection
