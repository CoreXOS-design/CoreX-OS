<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FICA Completion Report</title>
    @php
        $data = $submission->form_data ?? [];
        $personal = $data['personal'] ?? [];
        $entity = $data['entity'] ?? [];
        $service = $data['service'] ?? [];
        $pepData = $data['pep'] ?? [];
        $agency = $submission->agency;
        // Multi-agency: the agency's own theme colour, with the same platform
        // default every other branded surface falls back to when unset —
        // never a value hardcoded to one agency's palette.
        $brandColor = ($agency && $agency->default_color) ? $agency->default_color : '#0b2a4a';
    @endphp
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.5; padding: 40px; max-width: 210mm; margin: 0 auto; }
        @page { size: A4; margin: 15mm; }
        .header { text-align: center; border-bottom: 3px solid {{ $brandColor }}; padding-bottom: 15px; margin-bottom: 25px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #1e293b; margin: 10px 0 2px; }
        .header .subtitle { font-size: 11px; color: #64748b; }
        .header img { max-height: 50px; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: 700; color: #1e293b; border-bottom: 2px solid {{ $brandColor }}; padding-bottom: 4px; margin-bottom: 10px; }
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; }
        .field { margin-bottom: 4px; }
        .field-label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
        .field-value { font-size: 11px; color: #1e293b; }
        .field-value.alert { color: #dc2626; font-weight: 600; }
        .full-width { grid-column: 1 / -1; }
        .signature-block { display: flex; align-items: flex-end; gap: 30px; margin-top: 15px; }
        .signature-block img { max-height: 80px; border: 1px solid #e2e8f0; padding: 5px; background: #fff; }
        .approval-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; margin-top: 10px; }
        .status-badge { display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: 700; }
        .status-approved { background: #dcfce7; color: #166534; }
        .risk-low { color: #059669; } .risk-medium { color: #d97706; } .risk-high { color: #dc2626; }
        .wet-ink-note { background: #f8fafc; border: 1px dashed #94a3b8; padding: 10px; font-size: 10px; color: #475569; }
        .wet-ink-scan img { max-width: 100%; max-height: 400px; border: 1px solid #e2e8f0; margin-top: 8px; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        @if($agency && $agency->logo_path)
            <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}">
        @endif
        <h1>FICA Completion Report</h1>
        <div class="subtitle">Financial Intelligence Centre Act — Verification Record</div>
    </div>

    <div class="section">
        <div class="section-title">Client Details</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Full Name</div><div class="field-value">{{ $personal['full_name'] ?? trim(($personal['first_name'] ?? '') . ' ' . ($personal['last_name'] ?? '')) ?: '—' }}</div></div>
            <div class="field"><div class="field-label">ID / Passport</div><div class="field-value">{{ $personal['id_number'] ?? '—' }}</div></div>
            <div class="field"><div class="field-label">SA Citizen/Resident</div><div class="field-value">{{ ucfirst($personal['sa_citizen'] ?? '—') }}</div></div>
            <div class="field"><div class="field-label">Entity Type</div><div class="field-value">{{ ucfirst($submission->entity_type) }}</div></div>
            <div class="field"><div class="field-label">Phone</div><div class="field-value">{{ $personal['phone'] ?? '—' }}</div></div>
            <div class="field"><div class="field-label">Email</div><div class="field-value">{{ $personal['email'] ?? '—' }}</div></div>
            <div class="field full-width"><div class="field-label">Residential Address</div><div class="field-value">{{ $personal['residential_address'] ?? '—' }}</div></div>
        </div>
    </div>

    @if($submission->entity_type === 'company' && !empty($entity['company_name']))
    <div class="section">
        <div class="section-title">Company / CC</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Company Name</div><div class="field-value">{{ $entity['company_name'] }}</div></div>
            <div class="field"><div class="field-label">Registration No</div><div class="field-value">{{ $entity['company_reg_number'] ?? '' }}</div></div>
            <div class="field full-width"><div class="field-label">Business</div><div class="field-value">{{ $entity['company_business_description'] ?? '' }}</div></div>
        </div>
    </div>
    @endif

    @if($submission->entity_type === 'trust' && !empty($entity['trust_name']))
    <div class="section">
        <div class="section-title">Trust</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Trust Name</div><div class="field-value">{{ $entity['trust_name'] }}</div></div>
            <div class="field"><div class="field-label">Master's Ref</div><div class="field-value">{{ $entity['trust_master_ref'] ?? '' }}</div></div>
        </div>
    </div>
    @endif

    @if($submission->entity_type === 'partnership' && !empty($entity['partnership_name']))
    <div class="section">
        <div class="section-title">Partnership</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Partnership Name</div><div class="field-value">{{ $entity['partnership_name'] }}</div></div>
        </div>
    </div>
    @endif

    @if(!empty($service))
    <div class="section">
        <div class="section-title">Service & Payment</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Purpose</div><div class="field-value">{{ $service['transaction_purpose'] ?? '—' }}</div></div>
            <div class="field"><div class="field-label">Cash &gt; R50,000</div><div class="field-value {{ ($service['cash_over_50k'] ?? '') === 'yes' ? 'alert' : '' }}">{{ ucfirst($service['cash_over_50k'] ?? 'No') }}</div></div>
            <div class="field full-width"><div class="field-label">Payment Method</div><div class="field-value">{{ $service['payment_method'] ?? '—' }}</div></div>
        </div>
    </div>
    @endif

    @if(!empty($pepData))
    <div class="section">
        <div class="section-title">PEP Status</div>
        @php
            $foreignPep = $pepData['foreign_pep'] ?? [];
            $domesticPep = $pepData['domestic_pep'] ?? [];
            $hasPep = !empty($foreignPep) || !empty($domesticPep) || ($pepData['is_family_associate'] ?? '') === 'yes';
        @endphp
        @if($hasPep)
            <p class="field-value alert">PEP indicators present — see details below.</p>
            @if(!empty($foreignPep))<p style="font-size: 10px; margin-top: 3px;">Foreign: {{ implode(', ', array_map(fn($p) => str_replace('_', ' ', ucfirst($p)), $foreignPep)) }}</p>@endif
            @if(!empty($domesticPep))<p style="font-size: 10px;">Domestic: {{ implode(', ', array_map(fn($p) => str_replace('_', ' ', ucfirst($p)), $domesticPep)) }}</p>@endif
            @if(!empty($pepData['source_of_wealth']))<p style="font-size: 10px; margin-top: 3px;">Source of Wealth: {{ $pepData['source_of_wealth'] }}</p>@endif
        @else
            <p class="field-value" style="color: #059669;">No PEP indicators.</p>
        @endif
    </div>
    @endif

    {{-- Recipient declaration & signature — online intake captures this digitally; --}}
    {{-- wet-ink intake has no digital signature/answers, only the scanned paper form. --}}
    @if($submission->signature_data)
    <div class="section">
        <div class="section-title">Client Declaration & Signature</div>
        <div class="signature-block">
            <img src="{{ $submission->signature_data }}" alt="Client Signature">
            <div>
                <div class="field-label">Signed at</div>
                <div class="field-value">{{ $data['declaration']['signed_at_location'] ?? '' }} — {{ $submission->signed_at?->format('d M Y H:i') }}</div>
            </div>
        </div>
    </div>
    @elseif($wetInkFormEmbed)
    <div class="section">
        <div class="section-title">Client Declaration & Signature (Wet-Ink)</div>
        @if($wetInkFormEmbed['type'] === 'image')
            <p class="wet-ink-note">Signed on the physical FICA form below — received {{ $submission->wet_ink_received_date?->format('d M Y') }}.</p>
            <div class="wet-ink-scan"><img src="{{ $wetInkFormEmbed['data'] }}" alt="Signed FICA form"></div>
        @else
            <p class="wet-ink-note">
                Signed on a physical FICA form (received {{ $submission->wet_ink_received_date?->format('d M Y') }}),
                filed separately as <strong>{{ $wetInkFormEmbed['name'] }}</strong> — see the submission's document list to view it.
            </p>
        @endif
    </div>
    @endif

    {{-- Agent verification. 2026-08-25, Johan: "the FICA report ready for
         all FICA, with agents" — the agent must show. $agentVerifiedByUser
         is resolved outside the agency-tenancy scope (see
         FicaCompletionReportService::resolveVerifyingUser()) specifically
         so a super-admin platform account (agency_id NULL by design) still
         renders on their own compliance document. Always render this
         section — a genuine absence is stated honestly, never omitted
         silently and never a bare dash. --}}
    <div class="section">
        <div class="section-title">Agent Verification</div>
        @if(!$submission->agent_verified_by)
        <div class="approval-box">
            <p style="font-size: 10px; color: #475569; margin: 0;">No agent verification is recorded for this submission.</p>
        </div>
        @else
        <div class="approval-box">
            <div class="field-grid">
                <div class="field"><div class="field-label">Agent</div><div class="field-value">{{ $agentVerifiedByUser->name ?? 'Agent record unavailable' }}</div></div>
                <div class="field"><div class="field-label">Date</div><div class="field-value">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</div></div>
                <div class="field"><div class="field-label">Risk Rating</div><div class="field-value {{ [1 => 'risk-low', 2 => 'risk-medium', 3 => 'risk-high'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</div></div>
                @if($submission->verification_method)
                <div class="field"><div class="field-label">Method</div><div class="field-value">{{ implode(', ', array_map(fn($m) => str_replace('_', ' ', ucfirst($m)), $submission->verification_method)) }}</div></div>
                @endif
            </div>
            @if(!empty($submission->agent_verification_data))
            @php
                // Real question wording, taken verbatim from the agent's own
                // verification screen (compliance/fica/show.blade.php,
                // "Verification Checklist" card) — never re-worded here, so
                // this can never drift from what the agent actually saw.
                // Johan, 2026-08-25: the raw array key ("is_vip", "tfs_screening")
                // is a database column name, never something written for a
                // human to read, and previously rendered as-is with no answer
                // shown at all — on a compliance document a "no" must appear
                // as "no", not vanish or read as a bare, unanswered label.
                $agentChecklistLabels = [
                    'identity_docs' => 'Identity document(s) proving IDENTITY provided?',
                    'address_docs'  => 'Document(s) proving ADDRESS provided?',
                    'authority_docs' => 'Document proving AUTHORITY provided?',
                    'is_vip'        => 'Is the client a VIP / PEP?',
                    'suspicious'    => 'Anything suspicious or unusual?',
                    'consistent'    => 'Transaction consistent with knowledge of client?',
                ];
                $yna = fn ($v) => match ((string) $v) { 'yes' => 'Yes', 'no' => 'No', 'na' => 'N/A', default => (string) $v };
            @endphp
            <div style="margin-top: 8px;">
                <div class="field-label">Checklist</div>
                <ul style="margin: 4px 0 0 16px; font-size: 10px; list-style: none; padding-left: 0;">
                    @foreach($agentChecklistLabels as $item => $label)
                        @if(array_key_exists($item, $submission->agent_verification_data) && $submission->agent_verification_data[$item] !== null && $submission->agent_verification_data[$item] !== '')
                        <li style="display:flex; justify-content:space-between; gap:8px; padding:2px 0;">
                            <span>{{ $label }}</span>
                            <strong>{{ $yna($submission->agent_verification_data[$item]) }}</strong>
                        </li>
                        @endif
                    @endforeach
                    @if(($submission->agent_verification_data['suspicious'] ?? null) === 'yes' && !empty($submission->agent_verification_data['suspicious_details']))
                    <li style="padding:2px 0;">Suspicious activity — details: {{ $submission->agent_verification_data['suspicious_details'] }}</li>
                    @endif
                </ul>
            </div>
            @endif
            @if($submission->agent_notes)<p style="margin-top: 6px; font-size: 10px; color: #475569;">Notes: {{ $submission->agent_notes }}</p>@endif
        </div>
        @endif
    </div>

    {{-- RO/CO approval --}}
    @if($submission->co_verified_by)
    <div class="section">
        <div class="section-title">Responsible/Compliance Officer Approval</div>
        <div class="approval-box">
            <div class="field-grid">
                <div class="field"><div class="field-label">Officer</div><div class="field-value">{{ $submission->coVerifiedBy->name ?? '—' }}</div></div>
                <div class="field"><div class="field-label">Date</div><div class="field-value">{{ $submission->co_verified_at?->format('d M Y H:i') }}</div></div>
                <div class="field"><div class="field-label">Final Risk Rating</div><div class="field-value {{ [1 => 'risk-low', 2 => 'risk-medium', 3 => 'risk-high'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</div></div>
                <div class="field"><div class="field-label">Status</div><div class="field-value"><span class="status-badge status-approved">APPROVED</span></div></div>
            </div>
            @if(!empty($submission->co_verification_data))
            @php
                // Real question wording, taken verbatim from the Compliance
                // Officer's own verification screen (compliance/fica/
                // compliance-review.blade.php's "Compliance Checklist" array,
                // plus tfs-panel.blade.php's "TFS Screening Completed?") — a
                // genuinely different question set from the agent's own
                // checklist above (delegating_docs and tfs_screening only
                // exist here), by design, not a filtered copy of it.
                // Johan, 2026-08-25: this list previously showed ONLY items
                // literally equal to 'yes' — a "no" or "N/A" the officer
                // recorded (e.g. authority_docs: N/A, is_vip: no) vanished
                // from the compliance record entirely rather than showing as
                // a "no"/"N/A" answer. Fixed to show every answered
                // question with its real answer, same as the agent block.
                $coChecklistLabels = [
                    'identity_docs'   => 'Identity document verified?',
                    'address_docs'    => 'Address proof verified (< 2 months)?',
                    'authority_docs'  => 'Authority document verified?',
                    'delegating_docs' => 'Delegating authority verified?',
                    'is_vip'          => 'Client is VIP/PEP?',
                    'suspicious'      => 'Suspicious or unusual activity?',
                    'consistent'      => 'Transaction consistent with knowledge of client?',
                    'tfs_screening'   => 'TFS Screening Completed?',
                ];
                $coYna = fn ($v) => match ((string) $v) { 'yes' => 'Yes', 'no' => 'No', 'na' => 'N/A', default => (string) $v };
            @endphp
            <div style="margin-top: 8px;">
                <div class="field-label">Checklist</div>
                <ul style="margin: 4px 0 0 16px; font-size: 10px; list-style: none; padding-left: 0;">
                    @foreach($coChecklistLabels as $item => $label)
                        @if(array_key_exists($item, $submission->co_verification_data) && $submission->co_verification_data[$item] !== null && $submission->co_verification_data[$item] !== '')
                        <li style="display:flex; justify-content:space-between; gap:8px; padding:2px 0;">
                            <span>{{ $label }}</span>
                            <strong>{{ $coYna($submission->co_verification_data[$item]) }}</strong>
                        </li>
                        @endif
                    @endforeach
                    @if(($submission->co_verification_data['suspicious'] ?? null) === 'yes' && !empty($submission->co_verification_data['suspicious_details']))
                    <li style="padding:2px 0;">Suspicious activity — details: {{ $submission->co_verification_data['suspicious_details'] }}</li>
                    @endif
                </ul>
            </div>
            @endif
            @if($submission->co_notes)<p style="margin-top: 6px; font-size: 10px; color: #475569;">Notes: {{ $submission->co_notes }}</p>@endif
            @if($submission->co_signature_data)
            <div class="signature-block" style="margin-top: 10px;">
                <img src="{{ $submission->co_signature_data }}" alt="Officer Signature">
                <div><div class="field-label">Officer Signature</div></div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <div class="footer">
        <p>This document is a completion record of FICA compliance verification for {{ $personal['full_name'] ?? $submission->contact?->full_name ?? 'the client' }}, generated at approval.</p>
        <p style="margin-top: 3px;">FICA valid until {{ $submission->fica_expires_at?->format('d M Y') ?? '—' }}.</p>
        <p style="margin-top: 3px;">{{ $agency->name ?? 'CoreX OS' }} — Generated {{ now()->format('d M Y H:i') }}</p>
    </div>
</body>
</html>
