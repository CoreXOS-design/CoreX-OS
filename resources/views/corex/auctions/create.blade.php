@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">New Auction</h1>
    </div>
    @include('corex.auctions._form')
</div>
@endsection
