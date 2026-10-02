@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col p-6 gap-4">
    <h1 class="text-xl font-semibold">New Auction</h1>
    @include('corex.auctions._form')
</div>
@endsection
