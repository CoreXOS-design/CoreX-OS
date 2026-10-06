@include('emails.rentals.maintenance._head', ['heading' => 'Work order'])
        <p>Dear {{ $contractorName }},</p>
        <p>Please find the work order for the job at {{ $address }} attached. The owner has approved it, so you can go ahead.</p>
        <div class="box">
            <strong>{{ $title }}</strong>
            @if($trade)<br>Trade: {{ $trade }}@endif
            @if($description && $description !== $title)<br>{{ $description }}@endif
            <br><br>{{ $ownerApprovalLine }}
        </div>
        <p class="muted">Please contact {{ $agencyName }} to arrange access to the property.</p>
@include('emails.rentals.maintenance._foot')
