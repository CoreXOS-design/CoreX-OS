@include('emails.rentals.maintenance._head', ['heading' => 'Extra work added'])
        <p>Dear {{ $ownerName }},</p>
        <p>For your information: extra work was added to <strong>{{ $title }}</strong> at {{ $address }}. It falls {{ $withinTerm }}, so it went ahead without needing your approval.</p>
        @if(count($lines))
        <div class="box">
            <table class="sum">
                @foreach($lines as $line)
                    <tr><td>{{ $line['description'] }}@if($line['quantity'] !== '') &times; {{ $line['quantity'] }}@endif</td><td class="r">R{{ $line['total'] }}</td></tr>
                @endforeach
                <tr><td><strong>Extra work</strong></td><td class="r"><strong>R{{ $extra }}</strong></td></tr>
            </table>
        </div>
        @endif
        <p>The new total for the job is <strong>R{{ $newTotal }}</strong>.</p>
        @if($termText !== '')<div class="term">{{ $termText }}</div>@endif
@include('emails.rentals.maintenance._foot')
