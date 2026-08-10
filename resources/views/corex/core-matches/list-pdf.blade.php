{{--
    Core-Match / Buyer-Pipeline wishlist list — printable A4 (landscape) PDF.

    Rendered server-side by CoreMatchListPdfService via dompdf. INTERNAL
    DOCUMENT — carries seller PII + full addresses; every page is stamped
    "Internal use only". Keep this Blade dompdf-safe: tables + inline/CSS
    classes, NO flex/grid, self-contained (no remote assets). $d is the
    view-model built by the service.
--}}
@php $d = $d ?? []; @endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 78px 26px 54px 26px; }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.5px;
            color: #1a1d21;
            margin: 0;
        }

        /* Fixed running header — repeats on every page */
        .doc-header {
            position: fixed;
            top: -62px; left: 0; right: 0;
            height: 52px;
        }
        .doc-header .title {
            font-size: 13px; font-weight: bold; color: #0f172a;
        }
        .doc-header .sub { font-size: 8px; color: #64748b; }
        .doc-header .meta { font-size: 8px; color: #64748b; text-align: right; }
        .rule { border-bottom: 1.5px solid #0f172a; margin-top: 4px; }

        /* Fixed running footer — repeats on every page (internal-use stamp) */
        .doc-footer {
            position: fixed;
            bottom: -40px; left: 0; right: 0;
            height: 32px;
            border-top: 1px solid #cbd5e1;
            padding-top: 4px;
        }
        .doc-footer .warn {
            font-size: 8px; font-weight: bold; color: #b91c1c;
        }
        .doc-footer .note { font-size: 7.5px; color: #94a3b8; }

        /* Intro / criteria block (page 1, normal flow) */
        .intro { margin-bottom: 8px; }
        .intro .buyer { font-size: 12px; font-weight: bold; color: #0f172a; }
        .intro .line { font-size: 8.5px; color: #334155; margin-top: 2px; }
        .intro .crit {
            font-size: 8.5px; color: #0f172a; margin-top: 4px;
            background: #f1f5f9; border: 1px solid #e2e8f0;
            padding: 4px 6px; border-radius: 3px;
        }
        .pill {
            display: inline-block; font-size: 7.5px; font-weight: bold;
            padding: 1px 5px; border-radius: 3px; color: #fff;
        }

        /* Property table */
        table.list { width: 100%; border-collapse: collapse; }
        table.list thead th {
            background: #0f172a; color: #fff;
            font-size: 8px; font-weight: bold; text-align: left;
            padding: 4px 5px; border: 1px solid #0f172a;
        }
        table.list tbody td {
            padding: 4px 5px; border: 1px solid #e2e8f0;
            vertical-align: top;
        }
        table.list tbody tr:nth-child(even) td { background: #f8fafc; }
        .addr { font-weight: bold; color: #0f172a; font-size: 9px; }
        .muted { color: #64748b; }
        .price { font-weight: bold; color: #0f172a; white-space: nowrap; }
        .badge {
            display: inline-block; font-size: 7px; font-weight: bold;
            padding: 0 4px; border-radius: 2px; color: #fff; margin-right: 3px;
        }
        .b-strong { background: #16a34a; }
        .b-good   { background: #2563eb; }
        .b-fair   { background: #d97706; }
        .b-status { background: #64748b; }
        .b-hidden { background: #9ca3af; }
        .num { color: #94a3b8; font-size: 8px; }
        /* Per-row photo (with-photos variant) */
        .photo-cell { width: 60px; padding: 3px; text-align: center; }
        .photo-cell img { width: 54px; height: 40px; border-radius: 2px; border: 1px solid #e2e8f0; }
        .photo-none {
            width: 54px; height: 40px; border-radius: 2px; border: 1px solid #e2e8f0;
            background: #f1f5f9; color: #cbd5e1; font-size: 6.5px; line-height: 40px;
            text-align: center; display: inline-block;
        }
        .empty-note {
            padding: 16px; text-align: center; color: #64748b;
            border: 1px dashed #cbd5e1; border-radius: 4px; font-size: 10px;
        }
    </style>
</head>
<body>

    {{-- Running header (every page) --}}
    <div class="doc-header">
        <table style="width:100%; border-collapse:collapse;"><tr>
            <td style="vertical-align:top;">
                <div class="title">Core Match List</div>
                <div class="sub">{{ $d['agency_name'] }} · Internal working list</div>
            </td>
            <td style="vertical-align:top;" class="meta">
                {{ $d['listing_type'] }} · {{ $d['total'] }} {{ \Illuminate\Support\Str::plural('property', $d['total']) }} · {{ ($d['with_photos'] ?? true) ? 'With photos' : 'Text only' }}<br>
                Generated {{ $d['generated_at'] }}@if(!empty($d['generated_by'])) · {{ $d['generated_by'] }}@endif
            </td>
        </tr></table>
        <div class="rule"></div>
    </div>

    {{-- Running footer (every page) — internal-use stamp --}}
    <div class="doc-footer">
        <table style="width:100%; border-collapse:collapse;"><tr>
            <td style="vertical-align:top;">
                <span class="warn">INTERNAL USE ONLY — contains seller &amp; address details. Not for client distribution.</span>
                <div class="note">Seller contact numbers and property access notes are confidential. Do not share this document with buyers or third parties.</div>
            </td>
            <td style="vertical-align:top; text-align:right; width:120px;" class="note">
                {{ $d['agency_name'] }}
            </td>
        </tr></table>
    </div>

    {{-- Intro / criteria (page 1) --}}
    <div class="intro">
        <span class="buyer">{{ $d['buyer_name'] }}</span>
        <span class="pill" style="background:#0f172a;">{{ $d['listing_type'] }}</span>
        <div class="line">
            @if(!empty($d['buyer_phone'])){{ $d['buyer_phone'] }}@endif
            @if(!empty($d['buyer_email'])) &nbsp;·&nbsp; {{ $d['buyer_email'] }}@endif
            @if(!empty($d['wishlist_name'])) &nbsp;·&nbsp; Wishlist: {{ $d['wishlist_name'] }}@endif
        </div>
        @if(!empty($d['criteria']))
        <div class="crit"><strong>Search criteria:</strong> {{ $d['criteria'] }}</div>
        @endif
    </div>

    @if(empty($d['rows']))
        <div class="empty-note">No properties currently match this wishlist's criteria.</div>
    @else
    <table class="list">
        <thead>
            <tr>
                @if($d['with_photos'])<th class="photo-cell">Photo</th>@endif
                <th style="width:20px;">#</th>
                <th style="width:26%;">Property address</th>
                <th style="width:74px;">Price</th>
                <th style="width:82px;">Specs</th>
                <th style="width:15%;">Agent</th>
                <th style="width:16%;">Seller contact</th>
                <th>Access / key arrangements</th>
            </tr>
        </thead>
        <tbody>
            @foreach($d['rows'] as $i => $r)
            <tr>
                @if($d['with_photos'])
                <td class="photo-cell">
                    @if(!empty($r['photo']))
                        <img src="{{ $r['photo'] }}" alt="">
                    @else
                        <span class="photo-none">no photo</span>
                    @endif
                </td>
                @endif
                <td class="num">{{ $i + 1 }}</td>
                <td>
                    <div class="addr">{{ $r['address'] ?: '—' }}</div>
                    <div style="margin-top:2px;">
                        @if($r['score'] > 0)
                            @php
                                $tier = $r['tier'] ?? 'fair';
                                $tierClass = $tier === 'strong' ? 'b-strong' : ($tier === 'good' ? 'b-good' : 'b-fair');
                                $tierLabel = $tier === 'strong' ? 'Strong' : ($tier === 'good' ? 'Good' : 'Fair');
                            @endphp
                            <span class="badge {{ $tierClass }}">{{ $r['score'] }}% {{ $tierLabel }}</span>
                        @endif
                        @if(!empty($r['status']))<span class="badge b-status">{{ $r['status'] }}</span>@endif
                        @if(!empty($r['hidden']))<span class="badge b-hidden">Hidden</span>@endif
                    </div>
                </td>
                <td class="price">{{ $r['price'] }}</td>
                <td>
                    @php
                        $specs = [];
                        if ($r['beds'] !== null)    $specs[] = $r['beds'] . ' bd';
                        if ($r['baths'] !== null)   $specs[] = rtrim(rtrim(number_format($r['baths'], 1), '0'), '.') . ' ba';
                        if ($r['garages'] !== null && $r['garages'] > 0) $specs[] = $r['garages'] . ' gar';
                    @endphp
                    {{ $specs ? implode(' · ', $specs) : '—' }}
                    @if(!empty($r['size']))<div class="muted">{{ $r['size'] }}</div>@endif
                </td>
                <td>
                    {{ $r['agent_name'] ?: '—' }}
                    @if(!empty($r['agent_phone']))<div class="muted">{{ $r['agent_phone'] }}</div>@endif
                </td>
                <td>
                    @if(!empty($r['seller_name']))
                        <strong>{{ $r['seller_name'] }}</strong>
                        @if(!empty($r['seller_phone']))<div class="muted">{{ $r['seller_phone'] }}</div>@endif
                    @else
                        <span class="muted">Not linked</span>
                    @endif
                </td>
                <td>
                    @if(!$d['access_shown'])
                        <span class="muted">—</span>
                    @elseif(!empty($r['access']))
                        {{ $r['access'] }}
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

</body>
</html>
