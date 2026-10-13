@include('emails.rentals.maintenance._head', ['heading' => $heading])
        <p>Dear {{ $contractorName }},</p>
        <p>{{ $line }}</p>
        <div class="box">
            <strong>{{ $title }}</strong><br>{{ $address }}
        </div>
        <p class="muted">Your private job link (from the go-ahead email) shows the details. Please contact {{ $agencyName }} if anything is not right.</p>
@include('emails.rentals.maintenance._foot')
