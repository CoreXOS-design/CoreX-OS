<div class="audit-page">
    <div class="audit-header">
        <h1>ELECTRONIC SIGNATURE CERTIFICATE</h1>
        <p>Home Finders Coastal &mdash; Document Signing System</p>
    </div>

    {{-- Document Information --}}
    <div class="audit-section">
        <div class="audit-section-title">Document Information</div>
        <div class="audit-doc-info">
            <table>
                <tr>
                    <td>Document:</td>
                    <td>{{ $document->name }}</td>
                </tr>
                <tr>
                    <td>Document ID:</td>
                    <td>DOC-{{ str_pad($document->id, 4, '0', STR_PAD_LEFT) }}</td>
                </tr>
                @if($documentHash)
                <tr>
                    <td>Document Hash (SHA-256):</td>
                    <td class="hash-display">{{ $documentHash }}</td>
                </tr>
                @endif
                <tr>
                    <td>Created by:</td>
                    <td>{{ $template->creator?->name ?? 'Unknown' }}</td>
                </tr>
                <tr>
                    <td>Created on:</td>
                    <td>{{ $template->created_at->format('d F Y, H:i') }} SAST</td>
                </tr>
                @if($template->completed_at)
                <tr>
                    <td>Completed on:</td>
                    <td>{{ $template->completed_at->format('d F Y, H:i') }} SAST</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    {{-- Signing Parties --}}
    <div class="audit-section">
        <div class="audit-section-title">Signing Parties</div>

        @foreach($progress as $role => $party)
            <div class="party-row">
                <div class="party-role">{{ strtoupper(str_replace('_', ' ', $role)) }}</div>
                <div class="party-name">{{ $party['name'] }} ({{ $party['email'] }})</div>

                @php
                    $method = $party['signing_method'] === 'wet_ink' ? 'Wet ink (uploaded and verified)' : 'Electronic signature';
                @endphp
                <div class="party-detail">Method: {{ $method }}</div>

                @if($party['completed_at'])
                    <div class="party-detail">
                        {{ $party['signing_method'] === 'wet_ink' ? 'Verified' : 'Signed' }}:
                        {{ $party['completed_at']->format('d F Y, H:i') }} SAST
                    </div>
                @endif

                @if($party['ip_address'])
                    <div class="party-detail">IP: {{ $party['ip_address'] }}</div>
                @endif

                <div class="party-detail">
                    Markers: {{ $party['signature_count'] }} signature{{ $party['signature_count'] !== 1 ? 's' : '' }},
                    {{ $party['initial_count'] }} initial{{ $party['initial_count'] !== 1 ? 's' : '' }}
                    ({{ $party['total_markers'] }} total)
                </div>

                @if($party['signing_method'] === 'wet_ink' && $party['reviewed_by'])
                    <div class="party-detail">
                        Verified by: {{ \App\Models\User::find($party['reviewed_by'])?->name ?? 'Unknown' }}
                        @if($party['reviewed_at'])
                            on {{ $party['reviewed_at']->format('d F Y, H:i') }} SAST
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Audit Trail --}}
    <div class="audit-section">
        <div class="audit-section-title">Audit Trail</div>

        @foreach($auditLogs as $log)
            <div class="audit-trail-item">
                <span class="audit-trail-time">{{ $log->created_at->format('d M Y, H:i') }}</span>
                <span class="audit-trail-desc">{{ \App\Services\Docuperfect\SignaturePdfService::auditActionDescription($log) }}</span>
            </div>
        @endforeach
    </div>

    {{-- Legal Footer --}}
    <div class="audit-footer">
        <p>This document was signed electronically in accordance with the<br>
        <strong>Electronic Communications and Transactions Act 25 of 2002 (ECT Act)</strong>,<br>
        Republic of South Africa.</p>

        @if($documentHash)
            <p style="margin-top: 10px;">
                Document integrity: <span class="verified-badge">Verified</span><br>
                <span class="hash-display">SHA-256: {{ $documentHash }}</span>
            </p>
        @endif

        <p style="margin-top: 15px; font-size: 8px; color: #999;">
            Generated on {{ now()->format('d F Y, H:i:s') }} SAST by Home Finders Coastal Document Signing System
        </p>
    </div>
</div>
