<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    <div class="pg">
        <div style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--teal);">At a glance</div>
        <div style="width:64px; height:4px; background:var(--brand); border-radius:2px; margin:14px 0 8px;"></div>
        <h1 style="font-size:24px; color:var(--brand); margin:0 0 22px;">Compare your properties</h1>

        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:var(--brand); color:#fff;">
                    <th style="text-align:left; padding:9px 10px; font-weight:600;">#</th>
                    <th style="text-align:left; padding:9px 10px; font-weight:600;">Property</th>
                    <th style="text-align:right; padding:9px 10px; font-weight:600;">Price</th>
                    <th style="text-align:center; padding:9px 10px; font-weight:600;">Beds</th>
                    <th style="text-align:center; padding:9px 10px; font-weight:600;">Baths</th>
                    <th style="text-align:center; padding:9px 10px; font-weight:600;">Garages</th>
                    <th style="text-align:right; padding:9px 10px; font-weight:600;">Size</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    <tr style="border-bottom:1px solid var(--line); {{ $loop->even ? 'background:var(--bg-alt);' : '' }}">
                        <td style="padding:8px 10px; color:var(--text-muted);">{{ $r['seq'] }}</td>
                        <td style="padding:8px 10px; color:var(--text);">
                            <div style="font-weight:600;">{{ $r['address'] }}</div>
                            @if($r['suburb'])<div style="color:var(--text-muted); font-size:11px;">{{ $r['suburb'] }}</div>@endif
                        </td>
                        <td style="padding:8px 10px; text-align:right; font-weight:700; color:var(--brand);">{{ $r['price'] }}</td>
                        <td style="padding:8px 10px; text-align:center;">{{ $r['beds'] }}</td>
                        <td style="padding:8px 10px; text-align:center;">{{ $r['baths'] }}</td>
                        <td style="padding:8px 10px; text-align:center;">{{ $r['garages'] }}</td>
                        <td style="padding:8px 10px; text-align:right;">{{ $r['size'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top:22px; font-size:11px; color:var(--text-muted);">Prepared by {{ $agentName }} · {{ $agencyName }} · {{ $date }}</p>
    </div>
</body>
</html>
