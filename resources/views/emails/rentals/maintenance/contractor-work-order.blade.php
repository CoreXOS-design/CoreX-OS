@include('emails.rentals.maintenance._head', ['heading' => $goAheadText ? 'You may go ahead' : 'Work order'])
        <p>Dear {{ $contractorName }},</p>
        @if($goAheadText)
            <p><strong>{{ $goAheadText }}</strong></p>
            <p>The work order is attached.</p>
        @else
            <p>Please find the work order for the job at {{ $address }} attached. The owner has approved it, so you can go ahead.</p>
        @endif
        <div class="box">
            <strong>{{ $title }}</strong>
            @if($trade)<br>Trade: {{ $trade }}@endif
            @if($description && $description !== $title)<br>{{ $description }}@endif
            <br><br>{{ $ownerApprovalLine }}
        </div>
        @if($jobUrl)
            <p style="text-align:center; margin: 22px 0;"><a href="{{ $jobUrl }}" style="background:#0b2a4a; color:#ffffff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold; display:inline-block;">Open the job</a></p>
            <p class="muted">Use your private link to tell us the work is done, send photos and your invoice, or ask for extra money before doing extra work.@if($jobUrlExpires) The link works until {{ $jobUrlExpires }}.@endif</p>
        @endif
        <p class="muted">Please contact {{ $agencyName }} to arrange access to the property.</p>
@include('emails.rentals.maintenance._foot')
