{{-- AT-273 — Street & Complex Search PDF (dompdf). Self-contained, table-based
     layout with plain CSS only: dompdf has no flexbox/grid, no CSS variables,
     no color-mix(). Keep it simple and print-safe. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 11px;
            line-height: 1.4;
            margin: 0;
        }
        .header {
            border-bottom: 2px solid #0b2a4a;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header .agency { font-size: 15px; font-weight: bold; color: #0b2a4a; }
        .header .title  { font-size: 13px; font-weight: bold; color: #0ea5e9; margin-top: 2px; }
        .header .meta   { font-size: 9px; color: #6b7280; margin-top: 4px; }
        .header .term   { color: #0b2a4a; font-weight: bold; }

        .notice {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 6px 8px;
            font-size: 9px;
            margin-bottom: 12px;
            border-radius: 4px;
        }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th {
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            border-bottom: 1px solid #d1d5db;
            padding: 6px 6px;
        }
        table.rows td {
            vertical-align: top;
            padding: 8px 6px;
            border-bottom: 1px solid #e5e7eb;
        }
        tr { page-break-inside: avoid; }

        .name { font-size: 11px; font-weight: bold; color: #111827; }
        .sub  { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .type-badge {
            display: inline-block;
            font-size: 8px;
            font-weight: bold;
            color: #374151;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            padding: 1px 4px;
            margin-left: 4px;
        }

        .addr-label { color: #6b7280; font-weight: bold; font-size: 9px; }
        .addr-line  { font-size: 10px; font-weight: normal; color: #374151; margin-bottom: 3px; }
        .prop-item  { color: #111827; margin-bottom: 3px; }
        .prop-item .unit { font-size: 12px; font-weight: bold; color: #0b2a4a; }
        .prop-item .rest { font-size: 11px; font-weight: bold; color: #1f2937; }
        .prop-item .addr { font-size: 11px; font-weight: bold; color: #1f2937; }
        .prop-role  { color: #6b7280; font-size: 8.5px; font-weight: normal; }
        .no-addr    { font-size: 9px; color: #9ca3af; font-style: italic; }

        .tag {
            display: inline-block;
            font-size: 8px;
            font-weight: bold;
            border-radius: 3px;
            padding: 2px 5px;
            margin-bottom: 3px;
            white-space: nowrap;
        }
        .tag-linked   { background: #d1fae5; color: #065f46; }
        .tag-unlinked { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }
        .tag-meta     { background: #f9fafb; color: #374151; border: 1px solid #e5e7eb; }
        .tag-meta span { color: #6b7280; font-weight: normal; }

        .col-tags { width: 130px; }

        .footer {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
        }
        .empty { text-align: center; color: #6b7280; padding: 30px 0; font-size: 11px; }
    </style>
</head>
<body>

    <div class="header">
        <div class="agency">{{ $agency->name ?? config('app.name', 'CoreX OS') }}</div>
        <div class="title">Street &amp; Complex Search</div>
        <div class="meta">
            {{ $total }} {{ \Illuminate\Support\Str::plural('contact', $total) }} matching
            <span class="term">“{{ $term }}”</span>
            &nbsp;·&nbsp; searched by Address &amp; Linked Properties
            @isset($sortLabel)&nbsp;·&nbsp; sorted by {{ $sortLabel }}@endisset
            &nbsp;·&nbsp; generated {{ $generatedAt->format('d M Y H:i') }}
            by {{ $generatedBy->name }}
        </div>
    </div>

    @if($capped)
    <div class="notice">
        Showing the first {{ number_format($cap) }} of {{ number_format($total) }} matches.
        Narrow the search term to include the rest.
    </div>
    @endif

    @if($contacts->isEmpty())
        <div class="empty">No contacts match “{{ $term }}”.</div>
    @else
    <table class="rows">
        <thead>
            <tr>
                <th style="width: 30%;">Contact</th>
                <th>Address &amp; Linked Properties</th>
                <th class="col-tags">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($contacts as $contact)
                @php
                    $fullName    = trim($contact->first_name . ' ' . $contact->last_name) ?: '(no name)';
                    $lastContact = $contact->last_contacted_at;
                    $lastMod     = $contact->modified_at ?? $contact->updated_at;
                    $residential = trim((string) $contact->address);
                    $structured  = $contact->composeStructuredAddress();
                    $linked      = $contact->properties;
                @endphp
                <tr>
                    {{-- Contact --}}
                    <td>
                        <div class="name">{{ $fullName }}@if($contact->type)<span class="type-badge">{{ $contact->type->name }}</span>@endif</div>
                        <div class="sub">Agent: {{ optional($contact->agent)->name ?? optional($contact->createdBy)->name ?? '—' }}</div>
                        @if($contact->phone)<div class="sub">{{ $contact->phone }}</div>@endif
                    </td>

                    {{-- Addresses --}}
                    <td>
                        @if($residential !== '')
                            <div class="addr-line"><span class="addr-label">Address:</span> {{ $residential }}</div>
                        @endif
                        @if($structured)
                            <div class="addr-line"><span class="addr-label">Captured:</span> {{ $structured }}</div>
                        @endif
                        @if($linked->isNotEmpty())
                            <div class="addr-label" style="margin-bottom:3px;">Linked Properties:</div>
                            @foreach($linked as $property)
                                @php
                                    $addr = $property->buildDisplayAddress();
                                    $unitLabel = filled($property->unit_number) ? 'Unit ' . trim((string) $property->unit_number)
                                        : (filled($property->unit_section_block) ? trim((string) $property->unit_section_block)
                                        : (filled($property->floor_number) ? 'Floor ' . trim((string) $property->floor_number) : null));
                                    $rest = $addr;
                                    if ($unitLabel && \Illuminate\Support\Str::startsWith($addr, $unitLabel)) {
                                        $rest = ltrim(\Illuminate\Support\Str::after($addr, $unitLabel), ', ');
                                    }
                                @endphp
                                <div class="prop-item">
                                    @if($unitLabel)
                                        <span class="unit">{{ $unitLabel }}</span>@if($rest !== '')<span class="rest">, {{ $rest }}</span>@endif
                                    @else
                                        <span class="addr">{{ $addr }}</span>
                                    @endif
                                    @if($property->pivot && $property->pivot->role)<span class="prop-role">({{ ucfirst($property->pivot->role) }})</span>@endif
                                </div>
                            @endforeach
                        @endif
                        @if($residential === '' && ! $structured && $linked->isEmpty())
                            <div class="no-addr">No address on record.</div>
                        @endif
                    </td>

                    {{-- Status tags --}}
                    <td class="col-tags">
                        @if($linked->isNotEmpty())
                            <span class="tag tag-linked">Linked ({{ $linked->count() }})</span><br>
                        @else
                            <span class="tag tag-unlinked">Not linked</span><br>
                        @endif
                        <span class="tag tag-meta"><span>Last contacted:</span> {{ $lastContact ? $lastContact->format('d M Y') : 'Never' }}</span><br>
                        <span class="tag tag-meta"><span>Last modified:</span> {{ $lastMod ? $lastMod->format('d M Y') : '—' }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        {{ $agency->name ?? config('app.name', 'CoreX OS') }} · CoreX OS · Street &amp; Complex Search · {{ $generatedAt->format('d M Y H:i') }}
    </div>

</body>
</html>
