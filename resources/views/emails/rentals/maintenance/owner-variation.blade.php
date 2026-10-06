@include('emails.rentals.maintenance._head', ['heading' => 'Extra work needs your approval'])
        <p>Dear {{ $ownerName }},</p>
        @if($isUpdate)
            <p>The request for extra work at {{ $address }} has changed — please look at the latest figures below.</p>
        @else
            <p>While working on <strong>{{ $title }}</strong> at {{ $address }}, extra work has come up that goes beyond what you approved and needs your approval before it is done:</p>
        @endif
        <div class="box">
            <table class="sum">
                <tr><td>Approved so far</td><td class="r">R{{ $baseline }}</td></tr>
                <tr><td>Extra work</td><td class="r">R{{ $extra }}</td></tr>
                <tr><td><strong>New total</strong></td><td class="r"><strong>R{{ $newTotal }}</strong></td></tr>
            </table>
        </div>
        <p>The variation notice is attached with the details. The rest of the job carries on as approved; the extra waits for your answer.</p>
        <p><a class="btn" href="{{ $portalUrl }}">Approve or decline in my portal</a></p>
        <p class="muted">You can also reply to this email and the office will record your answer.</p>
        @if($termText !== '')<div class="term">{{ $termText }}</div>@endif
@include('emails.rentals.maintenance._foot')
