{{--
    Signature Block — legal standard layout (SA property documents)

    Usage: @include('docuperfect.web-templates.components.signature-block', [
        'parties' => ['Lessor', 'Lessee', 'Agent'],
    ])

    When $recipients_by_role is available (from e-sign wizard), the grid adapts
    to render one signature cell per actual recipient of each role.
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

    // Determine if witness columns should render
    $showWitness = $show_witness ?? false;
    if (!$showWitness && isset($signing_parties) && is_array($signing_parties)) {
        $showWitness = in_array('witness', $signing_parties);
    }

    // Role alias map for matching recipients_by_role keys
    $roleAliases = [
        'lessor' => 'landlord', 'lessee' => 'tenant',
        'seller' => 'seller', 'buyer' => 'buyer', 'agent' => 'agent',
        'landlord' => 'landlord', 'tenant' => 'tenant',
    ];

    // Build expanded party entries: each person gets their own sig cell
    // Structure: [ ['display' => 'Seller', 'name' => 'James', 'markerKey' => 'seller', 'roleLabel' => 'Seller'], ... ]
    $expandedParties = [];
    $partyNameIdx = 0;
    foreach ($parties as $party) {
        $baseKey = strtolower($party);
        $roleKey = $roleAliases[$baseKey] ?? $baseKey;
        $recipientsForRole = (isset($recipients_by_role) && is_array($recipients_by_role))
            ? ($recipients_by_role[$roleKey] ?? $recipients_by_role[$baseKey] ?? [])
            : [];

        if (count($recipientsForRole) > 0) {
            foreach ($recipientsForRole as $idx => $r) {
                $markerKey = $idx === 0 ? $roleKey : $roleKey . '_' . ($idx + 1);
                $expandedParties[] = [
                    'display' => $party,
                    'name' => $r['name'] ?? ($party_names[$partyNameIdx] ?? ''),
                    'markerKey' => $markerKey,
                    'roleLabel' => $party,
                    'roleBase' => $roleKey,
                ];
                $partyNameIdx++;
            }
        } else {
            // No recipient data — fall back to party_names
            $expandedParties[] = [
                'display' => $party,
                'name' => $party_names[$partyNameIdx] ?? '',
                'markerKey' => $baseKey,
                'roleLabel' => $party,
                'roleBase' => $baseKey,
            ];
            $partyNameIdx++;
        }
    }

    // Group expanded parties by role base for rendering blocks
    $groupedByRole = [];
    foreach ($expandedParties as $ep) {
        $groupedByRole[$ep['roleBase']][] = $ep;
    }
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
    .sig-row-adaptive {
        display: grid;
        gap: 12pt;
        margin-top: 14pt;
    }
    .sig-row-adaptive.cols-1 { grid-template-columns: 1fr; }
    .sig-row-adaptive.cols-2 { grid-template-columns: 1fr 1fr; }
    .sig-row-adaptive.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    .sig-row-adaptive.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
    .sig-row-adaptive.with-witness-2 { grid-template-columns: 1fr 1fr 1fr 1fr; }
    .sig-row-adaptive.with-witness-1 { grid-template-columns: 1fr 1fr 1fr; }
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
        gap: 12pt;
        margin-top: 10pt;
    }
    .sig-row-right.with-witness { grid-template-columns: 1fr 1fr 1fr 1fr; }
    .sig-row-right.no-witness { display: none; }
    .sig-cell-empty { visibility: hidden; }
</style>

<div class="sig-section">
    <p class="corex-section-heading"><strong>Signatures</strong></p>

    @foreach($groupedByRole as $roleBase => $members)
        @php
            $roleDisplay = $members[0]['display'] ?? ucfirst($roleBase);
            $memberCount = count($members);
            $firstMarkerKey = $members[0]['markerKey'];
            // Determine grid columns: members + witnesses (2 if enabled)
            $witnessCount = $showWitness ? 2 : 0;
            $totalCols = min($memberCount + $witnessCount, 4);
            $colClass = $showWitness
                ? 'with-witness-' . $memberCount
                : 'cols-' . min($memberCount, 4);
        @endphp
        <div class="sig-party-block">
            <p class="sig-text">
                Thus done and signed by the {{ $roleDisplay }}@if($memberCount === 1 && $members[0]['name'])({{ $members[0]['name'] }})@elseif($memberCount > 1)/s @foreach($members as $mi => $m)({{ $m['name'] ?: $roleDisplay }})@if($mi < $memberCount - 1), @endif @endforeach @endif at
                <span class="sig-field" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="location"></span>
                on this
                <span class="sig-field sig-field-short" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="day"></span>
                day of
                <span class="sig-field sig-field-medium" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="month"></span>
                20<span class="sig-field sig-field-year" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="year"></span>
                at
                <span class="sig-field sig-field-short" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="time"></span>
                am / pm.
            </p>

            {{-- Signature row: one cell per person + witness cells --}}
            <div class="sig-row-adaptive {{ $colClass }}">
                @foreach($members as $mi => $member)
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $member['markerKey'] }}" data-marker-type="signature" data-marker-index="{{ $loop->parent->index }}-{{ $mi }}" data-name="{{ $member['name'] }}"></div>
                    <div class="sig-cell-label">{{ $member['name'] ?: $member['display'] }}</div>
                </div>
                @endforeach
                @if($showWitness)
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="witness" data-marker-index="{{ $loop->index }}-w1"></div>
                    <div class="sig-cell-label">As Witness</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="witness" data-marker-index="{{ $loop->index }}-w2"></div>
                    <div class="sig-cell-label">As Witness</div>
                </div>
                @endif
            </div>

            {{-- Witness name row — only if witness is enabled --}}
            @if($showWitness)
            <div class="sig-row-right with-witness">
                @foreach($members as $mi => $member)
                <div class="sig-cell sig-cell-empty">
                    <div class="sig-cell-line"></div>
                    <div class="sig-cell-label">&nbsp;</div>
                </div>
                @endforeach
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="witness-name" data-marker-index="{{ $loop->index }}-wn1"></div>
                    <div class="sig-cell-label">Name of Witness</div>
                </div>
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="{{ $firstMarkerKey }}" data-marker-type="witness-name" data-marker-index="{{ $loop->index }}-wn2"></div>
                    <div class="sig-cell-label">Name of Witness</div>
                </div>
            </div>
            @endif
        </div>
    @endforeach

    {{-- Supervisor Signature Zone — only renders for candidate practitioner flows --}}
    @if(!empty($is_candidate_flow))
        @php $supervisorDisplayName = $supervisor_name ?? 'Supervisor'; @endphp
        <div class="sig-party-block">
            <p class="sig-text">
                Thus authorised and signed by the Supervising Practitioner ({{ $supervisorDisplayName }}) at
                <span class="sig-field" data-marker-party="supervisor" data-marker-type="location"></span>
                on this
                <span class="sig-field sig-field-short" data-marker-party="supervisor" data-marker-type="day"></span>
                day of
                <span class="sig-field sig-field-medium" data-marker-party="supervisor" data-marker-type="month"></span>
                20<span class="sig-field sig-field-year" data-marker-party="supervisor" data-marker-type="year"></span>
                at
                <span class="sig-field sig-field-short" data-marker-party="supervisor" data-marker-type="time"></span>
                am / pm.
            </p>

            <div class="sig-row-adaptive cols-1">
                <div class="sig-cell">
                    <div class="sig-cell-line" data-marker-party="supervisor" data-marker-type="signature" data-marker-index="supervisor-0" data-name="{{ $supervisorDisplayName }}"></div>
                    <div class="sig-cell-label">{{ $supervisorDisplayName }}<br><em style="font-size:8pt;">Supervising Practitioner</em></div>
                </div>
            </div>
        </div>
    @endif
</div>
