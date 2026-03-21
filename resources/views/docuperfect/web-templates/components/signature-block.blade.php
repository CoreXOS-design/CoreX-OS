{{--
    Signature Block — legal standard layout (SA property documents)

    Usage: @include('docuperfect.web-templates.components.signature-block', [
        'parties' => ['Lessor', 'Lessee', 'Agent'],
    ])
--}}
@php
    // signing_parties from template DB — generic keys like ['owner_party','agent']
    // parties from explicit @include parameter — display names like ['Seller','Agent']
    // Fall back to all three if neither provided
    if (isset($signing_parties) && is_array($signing_parties)) {
        // Map generic keys to display names based on document context
        $isSales = isset($document_context) && $document_context === 'sales';
        $keyMap = $isSales
            ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
            : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent'];
        $parties = array_values(array_map(
            fn($k) => $keyMap[$k] ?? ucfirst(str_replace('_', ' ', $k)),
            $signing_parties
        ));
    }
    $parties = $parties ?? ['Lessor', 'Lessee', 'Agent'];
@endphp

<style>
    .sig-section { margin-top: 16pt; }
    .sig-section .corex-section-heading { margin-bottom: 10pt; }
    .sig-party-block { margin-bottom: 20pt; }
    .sig-party-block:last-child { margin-bottom: 0; }
    .sig-text { font-size: 10pt; line-height: 1.6; margin: 0 0 2pt 0; }
    .sig-field {
        display: inline-block;
        min-width: 100pt;
        border-bottom: 1px solid #333;
        padding: 1pt 4pt;
        min-height: 14pt;
    }
    .sig-field-short { min-width: 40pt; }
    .sig-field-medium { min-width: 70pt; }
    .sig-field-year { min-width: 36pt; }
    .sig-row-4 {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr;
        gap: 12pt;
        margin-top: 14pt;
    }
    .sig-cell { text-align: center; }
    .sig-cell-line {
        border-bottom: 1px solid #333;
        min-height: 28pt;
    }
    .sig-cell-label {
        font-size: 9pt;
        padding-top: 2pt;
    }
    .sig-row-right {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr;
        gap: 12pt;
        margin-top: 10pt;
    }
    .sig-cell-empty { visibility: hidden; }
</style>

<div class="sig-section">
    <p class="corex-section-heading"><strong>Signatures</strong></p>

    @foreach($parties as $i => $party)
        @php
            $partyKey = strtolower($party);
            $partyName = $party_names[$i] ?? '';
        @endphp
        <div class="sig-party-block">
            <p class="sig-text">
                Thus done and signed by the {{ $party }}{{ $partyName ? ' (' . $partyName . ')' : '' }} at
                <span class="sig-field" data-marker-party="{{ $partyKey }}" data-marker-type="location"></span>
                on this
                <span class="sig-field sig-field-short" data-marker-party="{{ $partyKey }}" data-marker-type="day"></span>
                day of
                <span class="sig-field sig-field-medium" data-marker-party="{{ $partyKey }}" data-marker-type="month"></span>
                20<span class="sig-field sig-field-year" data-marker-party="{{ $partyKey }}" data-marker-type="year"></span>
                at
                <span class="sig-field sig-field-short" data-marker-party="{{ $partyKey }}" data-marker-type="time"></span>
                am / pm.
            </p>

            {{-- Signature row: Party, Party, Witness, Witness --}}
            <div class="sig-row-4">
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="signature" data-marker-index="{{ $i }}" data-name="{{ $partyName }}"></div>
                    <div class="sig-cell-label">{{ $partyName ?: $party }}</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="signature" data-marker-index="{{ $i }}-2" data-name="{{ $partyName }}"></div>
                    <div class="sig-cell-label">{{ $partyName ?: $party }}</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="witness" data-marker-index="{{ $i }}-w1"></div>
                    <div class="sig-cell-label">As Witness</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="witness" data-marker-index="{{ $i }}-w2"></div>
                    <div class="sig-cell-label">As Witness</div>
                </div>
            </div>

            {{-- Witness name row: empty, empty, Name of Witness, Name of Witness --}}
            <div class="sig-row-right">
                <div class="sig-cell sig-cell-empty">
                    <div class="sig-cell-line"></div>
                    <div class="sig-cell-label">&nbsp;</div>
                </div>
                <div class="sig-cell sig-cell-empty">
                    <div class="sig-cell-line"></div>
                    <div class="sig-cell-label">&nbsp;</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="witness-name" data-marker-index="{{ $i }}-wn1"></div>
                    <div class="sig-cell-label">Name of Witness</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $partyKey }}" data-marker-type="witness-name" data-marker-index="{{ $i }}-wn2"></div>
                    <div class="sig-cell-label">Name of Witness</div>
                </div>
            </div>
        </div>
    @endforeach
</div>
