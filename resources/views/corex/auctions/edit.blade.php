@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col p-6 gap-4">
    <h1 class="text-xl font-semibold">Edit Auction — {{ $auction->reference }}</h1>
    @include('corex.auctions._form')
</div>
@endsection
