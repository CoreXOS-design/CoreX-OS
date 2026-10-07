@include('emails.rentals.maintenance._head', ['heading' => $heading])
        <p>Hello,</p>
        @if($outcome === 'signed')
            <p>The lease agreement for {{ $address }} has been signed by everyone and you have approved it. The lease is now active, with its start and end dates as captured. The signed copy is filed against the lease and the property.</p>
        @elseif($outcome === 'signed_not_active')
            <p>The lease agreement for {{ $address }} has been signed by everyone and you have approved it, but the lease could not be made active yet.</p>
            @if($detail)<div class="box">{{ $detail }}</div>@endif
        @elseif($outcome === 'declined')
            <p>The lease agreement for {{ $address }} was declined.</p>
            @if($detail)<div class="box">{{ $detail }}</div>@endif
            <p class="muted">Nothing has changed on the lease — it is still a draft. Open it to prepare the agreement again.</p>
        @else
            <p>The signing links for the lease agreement for {{ $address }} have expired before everyone signed.</p>
            @if($detail)<div class="box">{{ $detail }}</div>@endif
            <p class="muted">Nothing has changed on the lease — it is still a draft. Open it to prepare the agreement again.</p>
        @endif
        <p><a class="btn" href="{{ $leaseUrl }}">Open the lease</a></p>
@include('emails.rentals.maintenance._foot')
