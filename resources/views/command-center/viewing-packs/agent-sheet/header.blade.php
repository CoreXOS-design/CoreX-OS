<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    <div class="pg">
        {{-- Prominent CONFIDENTIAL band — clear at a glance on a desk. --}}
        <div style="background:#b91c1c; color:#fff; padding:14px 20px; border-radius:4px; margin-bottom:28px;">
            <div style="font-size:17px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase;">Confidential — Agent Eyes Only</div>
            <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:2px;">Do not hand to the buyer. This sheet carries unredacted information. Keep it with you.</div>
        </div>

        {{-- Minimal header (NOT a branded cover). --}}
        <div style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--brand);">Agent Sheet</div>
        <div style="width:64px; height:4px; background:var(--brand); border-radius:2px; margin:14px 0 18px;"></div>
        <h1 style="font-size:26px; color:var(--brand); margin:0 0 10px;">{{ $buyerName }}</h1>
        <table style="border-collapse:collapse; font-size:13px; color:var(--text);">
            <tr><td style="padding:3px 18px 3px 0; color:var(--text-muted);">Properties</td><td style="padding:3px 0; font-weight:600;">{{ $propertyCount }}</td></tr>
            <tr><td style="padding:3px 18px 3px 0; color:var(--text-muted);">Agent</td><td style="padding:3px 0; font-weight:600;">{{ $agentName }}</td></tr>
            <tr><td style="padding:3px 18px 3px 0; color:var(--text-muted);">Agency</td><td style="padding:3px 0; font-weight:600;">{{ $agencyName }}</td></tr>
            <tr><td style="padding:3px 18px 3px 0; color:var(--text-muted);">Date</td><td style="padding:3px 0; font-weight:600;">{{ $date }}</td></tr>
        </table>
    </div>
</body>
</html>
