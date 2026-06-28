<!DOCTYPE html>
<html lang="en">
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    {{-- Page 1 — the property brochure (reused) or a minimal fallback --}}
    @if($brochure)
        @include('corex.properties._brochure', ['b' => $brochure])
    @else
        <div class="pg">
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
        </div>
    @endif

    {{-- Page 2 — buyer notes block (same layout on every property page).
         page-break-before keeps it on its own page without a double-break blank. --}}
    <div class="pg" style="page-break-before:always;">
        <div style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--teal);">Your notes</div>
        <div style="width:64px; height:4px; background:var(--brand); border-radius:2px; margin:14px 0 16px;"></div>
        <div style="font-size:16px; font-weight:700; color:var(--brand); margin-bottom:4px;">{{ $address }}</div>
        <div style="font-size:12px; color:var(--text-muted); margin-bottom:26px;">Jot your thoughts during the viewing — this space is yours to keep.</div>
        @for($i = 0; $i < 16; $i++)
            <div style="border-bottom:1px solid var(--line); height:34px;"></div>
        @endfor
    </div>
</body>
</html>
