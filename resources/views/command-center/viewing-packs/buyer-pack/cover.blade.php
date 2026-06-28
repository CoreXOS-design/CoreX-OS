<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    <div class="pg" style="display:flex; flex-direction:column;">
        {{-- Brand bar --}}
        <div style="display:flex; align-items:center; gap:14px; margin-bottom:40px;">
            @if($logo)
                <img src="{{ $logo }}" style="height:46px; width:auto;">
            @endif
            <span style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--brand);">{{ $agencyName }}</span>
        </div>

        <div style="margin-top:90px;">
            <div style="font-size:13px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:var(--teal);">Viewing Pack</div>
            <div style="width:80px; height:4px; background:var(--brand); border-radius:2px; margin:18px 0 26px;"></div>
            <h1 style="font-size:34px; line-height:1.15; color:var(--brand); margin:0 0 10px;">Prepared for<br>{{ $buyerName }}</h1>
            <div style="font-size:14px; color:var(--text-muted); line-height:1.7;">
                {{ $propertyCount }} {{ \Illuminate\Support\Str::plural('property', $propertyCount) }} selected for viewing<br>
                {{ $date }}
            </div>
        </div>

        {{-- Agent block, anchored low --}}
        <div style="margin-top:120px; border-top:2px solid var(--brand); padding-top:20px;">
            <div style="display:flex; align-items:flex-end; justify-content:space-between;">
                <div style="flex:1;">
                    <div style="font-size:11px; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Your agent</div>
                    <div style="font-size:18px; font-weight:700; color:var(--brand);">{{ $agentName }}</div>
                    @if($agentPhone)<div style="font-size:13px; color:var(--text);">{{ $agentPhone }}</div>@endif
                    @if($agentEmail)<div style="font-size:13px; color:var(--text);">{{ $agentEmail }}</div>@endif
                </div>
                @if($agentPhoto)
                    <img src="{{ $agentPhoto }}" style="width:72px; height:72px; border-radius:50%; object-fit:cover;">
                @endif
            </div>
        </div>
    </div>
</body>
</html>
