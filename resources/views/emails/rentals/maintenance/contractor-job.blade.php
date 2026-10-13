@include('emails.rentals.maintenance._head', ['heading' => 'Job request'])
        <p>Dear {{ $contractorName }},</p>
        <p>{{ $agencyName }} has a job for you at {{ $address }}.</p>
        <div class="box">
            <strong>{{ $title }}</strong>
            @if($trade)<br>Trade: {{ $trade }}@endif
            @if($description && $description !== $title)<br>{{ $description }}@endif
            @if($photoCount > 0)<br><br>{{ $photoCount }} {{ $photoCount === 1 ? 'photo' : 'photos' }} of the problem{{ $photoCount === 1 ? ' is' : ' are' }} on the job page, with our notes marked on {{ $photoCount === 1 ? 'it' : 'them' }}.@endif
            @if($accessNotes)<br><br><strong>Getting in:</strong> {{ $accessNotes }}@endif
            @if($tenantLine)<br><br><strong>Tenant:</strong> {{ $tenantLine }}@endif
            @if(!empty($calloutFee))<br><br><strong>Call-out fee on our records:</strong> {{ $calloutFee }}@if(!empty($calloutNote)) ({{ $calloutNote }})@endif. Please tell us if this is not right.@endif
        </div>
        @if(!empty($lookAndQuote))<p data-look-and-quote><strong>This is a visit to look at the problem and give a quote.</strong> Any repair work is agreed with the owner separately, before it starts.</p>@endif
        <p><a href="{{ $url }}" style="display:inline-block;padding:11px 18px;background:#0b2a4a;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:bold;">Open the job</a></p>
        <p>On the job page you can look at the photos, accept or decline the job, and upload your quote. No login is needed.@if($expiresOn) The link works until {{ $expiresOn }}.@endif</p>
        @if($agentLine)<p class="muted">To arrange access or ask a question, contact {{ $agentLine }}.</p>@else<p class="muted">Please contact {{ $agencyName }} to arrange access to the property.</p>@endif
@include('emails.rentals.maintenance._foot')
