<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    {{-- AT-160 items 7+8 — ONE agent per pack: per-property agent card removed;
         a compact AGENT-notes block (confidential) fills the freed footer space.
         Property + notes = ONE page (the sheet's confidential band lives on the
         header segment already). --}}
    @php $agentNotes = ['label' => 'Agent notes · confidential', 'microcopy' => "Record the buyer's reactions during the viewing — transcribe into the calendar feedback afterwards.", 'confidential' => true]; @endphp
    @if($brochure)
        @include('corex.properties._brochure', ['b' => $brochure, 'vpNotes' => $agentNotes])
    @else
        <div class="pg" style="height:1020px; overflow:hidden;">
            <div style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--teal);">Property {{ $seq }}</div>
            <div style="width:64px; height:4px; background:var(--brand); border-radius:2px; margin:14px 0 22px;"></div>
            <h1 style="font-size:26px; color:var(--brand); margin:0 0 6px;">{{ $minimal['price'] ?: 'Price on application' }}</h1>
            <div style="font-size:18px; font-weight:700; color:var(--text); margin-bottom:18px;">{{ $minimal['address'] }}</div>
            <table style="border-collapse:collapse; font-size:13px; color:var(--text);">
                @foreach($minimal['rows'] as $k => $v)
                    @if($v !== null && $v !== '')
                        <tr>
                            <td style="padding:4px 18px 4px 0; color:var(--text-muted);">{{ $k }}</td>
                            <td style="padding:4px 0; font-weight:600;">{{ $v }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
            <p style="margin-top:24px; font-size:11px; color:var(--text-muted);">Full brochure detail unavailable for this property.</p>
            <div style="position:absolute; left:56px; right:56px; bottom:48px;">
                <div style="font-size:11px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:#b91c1c;">{{ $agentNotes['label'] }}</div>
                <div style="font-size:10.5px; color:var(--text-muted); margin:3px 0 8px;">{{ $agentNotes['microcopy'] }}</div>
                @for($vn = 0; $vn < 3; $vn++)<div style="border-bottom:1px solid var(--line); height:22px;"></div>@endfor
            </div>
        </div>
    @endif
</body>
</html>
