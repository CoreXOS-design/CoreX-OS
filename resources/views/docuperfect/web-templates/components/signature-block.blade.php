{{--
    Signature Block — reusable across all web document templates

    Usage: @include('docuperfect.web-templates.components.signature-block', [
        'parties' => ['Owner', 'Owner', 'Agent'],
        'party_names' => [$lessor_name ?? '', $lessor_name_2 ?? '', $agent_name ?? ''],
        'signed_at_location' => $signed_at_location ?? null,
        'signed_day' => $signed_day ?? null,
        'signed_month' => $signed_month ?? null,
        'signed_time' => $signed_time ?? null,
        'signed_ampm' => $signed_ampm ?? null,
    ])
--}}
<div class="signature-section">
    <p>This Agreement has been accepted and signed by the Owner/s at
        <span class="field" data-field="signed_at_location">{{ $signed_at_location ?? '' }}</span>
    </p>
    <p>on this <span class="field field-short" data-field="signed_day">{{ $signed_day ?? '' }}</span> day of
        <span class="field" data-field="signed_month">{{ $signed_month ?? '' }}</span>
        at <span class="field field-short" data-field="signed_time">{{ $signed_time ?? '' }}</span>
        (<span class="field field-tiny" data-field="signed_ampm">{{ $signed_ampm ?? '' }}</span>)
    </p>

    <div class="signature-grid" style="grid-template-columns: repeat({{ count($parties ?? ['Owner','Owner','Agent']) }}, 1fr);">
        @foreach(($parties ?? ['Owner', 'Owner', 'Agent']) as $i => $party)
            <div class="signature-col" data-party="{{ strtolower($party) }}" data-name="{{ $party_names[$i] ?? '' }}" data-marker-party="{{ strtolower($party) }}" data-marker-index="{{ $i }}">
                <div class="signature-line"></div>
                <div class="signature-label">{{ $party }}</div>
                <div class="print-line"></div>
                <div class="print-label">{{ ($party_names[$i] ?? '') ?: 'Print Name' }}</div>
            </div>
        @endforeach
    </div>
</div>
