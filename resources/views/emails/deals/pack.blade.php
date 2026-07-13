<p>Good day{{ $recipientName ? ' ' . $recipientName : '' }},</p>

@if(!empty($messageBody))
    {!! nl2br(e($messageBody)) !!}
@else
    <p>Please find the documents for {{ $dealReference }}@if($propertyAddress) — {{ $propertyAddress }}@endif.</p>
@endif

@if(!empty($secureLinks))
    <p>Secure document links (each opens after a one-time PIN sent to you):</p>
    <ul>
        @foreach($secureLinks as $link)
            <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
        @endforeach
    </ul>
@else
    <p>The documents are attached to this email.</p>
@endif

@if($partLabel)
    <p style="color:#6b7280;font-size:12px;">This is {{ $partLabel }} of a multi-part delivery (sent in parts to keep each email within size limits).</p>
@endif

@if(!empty($agentFooter))
    <hr>
    <p style="color:#6b7280;font-size:12px;">
        @foreach($agentFooter as $line){{ $line }}<br>@endforeach
    </p>
@endif
