@include('emails.rentals.maintenance._head', ['heading' => $needsDecision ? 'Your approval is needed' : 'Quote'])
        <p>Dear {{ $ownerName }},</p>
        @if($needsDecision)
            <p>{{ $agencyName }} has a quote for work at {{ $address }} that needs your approval before the work can start:</p>
        @else
            <p>{{ $agencyName }} has {{ $revision > 1 ? 'sent you a revised quote' : 'sent you a quote' }} for work at {{ $address }}:</p>
        @endif
        <div class="box">
            <strong>{{ $title }}</strong>
            @if($description && $description !== $title)<br>{{ $description }}@endif
            <br>Quote{{ $revision > 1 ? ' (Rev ' . $revision . ')' : '' }}: <strong>R{{ $amount }}</strong>
        </div>
        @if($hasDocument)<p>The quote is attached.</p>@endif
        @if($revision > 1)<p>This replaces the quote you received earlier. Any approval you gave to the earlier quote no longer applies to this one.</p>@endif
        @if($needsDecision)
            <p>Please log in to your portal to approve or decline it:</p>
            <p><a class="btn" href="{{ $portalUrl }}">Open my portal</a></p>
            <p class="muted">You can also reply to this email and the office will record your answer.</p>
        @endif
        @if($termText !== '')<div class="term">{{ $termText }}</div>@endif
@include('emails.rentals.maintenance._foot')
