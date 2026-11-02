{{-- Road to Sold 23.23: ONE property's MIC Adverts card on its own page, for the pitch popup's Property step and the clickable header on the pitch screens.
     Chrome-less in embed mode. Same partials and data as the Adverts list (MarketIntelligenceController::work with _pitch_card), nothing added to the card. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div data-qa="pitch-card" style="width: 100%; max-width: 1200px; margin: 0 auto; padding: 12px 8px;">
    @include('corex.market-intelligence._listings')
</div>
@include('corex.market-intelligence._advert-fetch-script')
@endsection
