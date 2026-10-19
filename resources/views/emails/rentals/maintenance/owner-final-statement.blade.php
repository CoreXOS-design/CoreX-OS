@include('emails.rentals.maintenance._head', ['heading' => 'Work completed'])
        <p>Dear {{ $ownerName }},</p>
        <p>The following work at {{ $address }} has been completed and closed:</p>
        <div class="box">
            <strong>{{ $title }}</strong>
            @if($amount !== null)<br>Final amount: <strong>R{{ $amount }}</strong>@endif
        </div>
        @if($emergencyBanner)<div class="banner">{{ $emergencyBanner }}</div>@endif
        @if(! empty($rulingLine))<p data-final-statement-ruling>Who pays has now been decided: <strong>{{ $rulingLine }}</strong>. This updated statement replaces the one sent earlier.</p>@endif
        <p>Your final statement is attached.</p>
@include('emails.rentals.maintenance._foot')
