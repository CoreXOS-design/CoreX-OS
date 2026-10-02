<p>Hi {{ $recipientName }},</p>
@if($kind === 'registration_closes')
<p>Registration for <strong>{{ $auction->title }}</strong> closes on {{ $auction->registration_closes_at?->format('l, d F Y \a\t H:i') }}.</p>
@else
<p><strong>{{ $auction->title }}</strong> takes place on {{ $auction->starts_at?->format('l, d F Y \a\t H:i') }}@if($auction->venue_name) at {{ $auction->venue_name }}@endif.</p>
@endif
<p>You asked about one of the properties in this auction. See the lots, the Rules of Auction and the Conditions of Sale here:</p>
<p><a href="{{ $pageUrl }}">{{ $pageUrl }}</a></p>
<p>{{ $auction->agency?->name }}</p>
