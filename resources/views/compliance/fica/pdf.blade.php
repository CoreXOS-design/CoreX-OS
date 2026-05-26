<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FICA Compliance Certificate</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.5; padding: 40px; max-width: 210mm; margin: 0 auto; }
        @media print { body { padding: 20mm; } @page { size: A4; margin: 15mm; } }
        .header { text-align: center; border-bottom: 3px solid #0d9488; padding-bottom: 15px; margin-bottom: 25px; }
        .header h1 { font-size: 20px; font-weight: 700; color: var(--text-primary); margin: 10px 0 2px; }
        .header .subtitle { font-size: 11px; color: #64748b; }
        .header img { max-height: 50px; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: 700; color: var(--text-primary); border-bottom: 2px solid #0d9488; padding-bottom: 4px; margin-bottom: 10px; }
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; }
        .field { margin-bottom: 4px; }
        .field-label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
        .field-value { font-size: 11px; color: var(--text-primary); }
        .field-value.alert { color: #dc2626; font-weight: 600; }
        .full-width { grid-column: 1 / -1; }
        .signature-block { display: flex; align-items: flex-end; gap: 30px; margin-top: 15px; }
        .signature-block img { max-height: 80px; border: 1px solid #e2e8f0; padding: 5px; background: #fff; }
        .approval-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; margin-top: 10px; }
        .status-badge { display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: 700; }
        .status-approved { background: #dcfce7; color: #166534; }
        .risk-low { color: #059669; } .risk-medium { color: #d97706; } .risk-high { color: #dc2626; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
        .no-print { display: flex; gap: 10px; margin-bottom: 20px; }
        .no-print button { padding: 8px 20px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .no-print .print-btn { background: var(--text-primary); color: #fff; }
        .no-print .back-btn { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    @php
        $data = $submission->form_data ?? [];
        $personal = $data['personal'] ?? [];
        $entity = $data['entity'] ?? [];
        $service = $data['service'] ?? [];
        $pepData = $data['pep'] ?? [];
        $agency = $submission->agency;
    @endphp

    <div class="no-print">
        <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
        <button class="back-btn" onclick="history.back()">Back</button>
    </div>

    <div class="header">
        @if($agency && $agency->logo_path)
            <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}">
        @endif
        <h1>FICA Compliance Certificate</h1>
        <div class="subtitle">Financial Intelligence Centre Act — Verification Record</div>
    </div>

    <div class="section">
        <div class="section-title">Client Details</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Full Name</div><div class="field-value">{{ $personal['full_name'] ?? '—' }}</div></div>
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

    <div class="section">
        <div class="section-title">Service & Payment</div>
        <div class="field-grid">
            <div class="field"><div class="field-label">Purpose</div><div class="field-value">{{ $service['transaction_purpose'] ?? '—' }}</div></div>
            <div class="field"><div class="field-label">Cash > R50,000</div><div class="field-value {{ ($service['cash_over_50k'] ?? '') === 'yes' ? 'alert' : '' }}">{{ ucfirst($service['cash_over_50k'] ?? 'No') }}</div></div>
            <div class="field full-width"><div class="field-label">Payment Method</div><div class="field-value">{{ $service['payment_method'] ?? '—' }}</div></div>
        </div>
    </div>

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

    {{-- Recipient signature --}}
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
    @endif

    {{-- Agent verification --}}
    @if($submission->agent_verified_by)
    <div class="section">
        <div class="section-title">Agent Verification</div>
        <div class="approval-box">
            <div class="field-grid">
                <div class="field"><div class="field-label">Agent</div><div class="field-value">{{ $submission->agentVerifiedBy->name ?? '—' }}</div></div>
                <div class="field"><div class="field-label">Date</div><div class="field-value">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</div></div>
                <div class="field"><div class="field-label">Risk Rating</div><div class="field-value {{ [1 => 'risk-low', 2 => 'risk-medium', 3 => 'risk-high'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</div></div>
                @if($submission->verification_method)
                <div class="field"><div class="field-label">Method</div><div class="field-value">{{ implode(', ', array_map(fn($m) => str_replace('_', ' ', ucfirst($m)), $submission->verification_method)) }}</div></div>
                @endif
            </div>
            @if($submission->agent_notes)<p style="margin-top: 6px; font-size: 10px; color: #475569;">Notes: {{ $submission->agent_notes }}</p>@endif
        </div>
    </div>
    @endif

    {{-- CO verification --}}
    @if($submission->co_verified_by)
    <div class="section">
        <div class="section-title">Compliance Officer Approval</div>
        <div class="approval-box">
            <div class="field-grid">
                <div class="field"><div class="field-label">Compliance Officer</div><div class="field-value">{{ $submission->coVerifiedBy->name ?? '—' }}</div></div>
                <div class="field"><div class="field-label">Date</div><div class="field-value">{{ $submission->co_verified_at?->format('d M Y H:i') }}</div></div>
                <div class="field"><div class="field-label">Final Risk Rating</div><div class="field-value {{ [1 => 'risk-low', 2 => 'risk-medium', 3 => 'risk-high'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</div></div>
                <div class="field"><div class="field-label">Status</div><div class="field-value"><span class="status-badge status-approved">APPROVED</span></div></div>
            </div>
            @if($submission->co_notes)<p style="margin-top: 6px; font-size: 10px; color: #475569;">Notes: {{ $submission->co_notes }}</p>@endif
            @if($submission->co_signature_data)
            <div class="signature-block" style="margin-top: 10px;">
                <img src="{{ $submission->co_signature_data }}" alt="CO Signature">
                <div><div class="field-label">Compliance Officer Signature</div></div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <div class="footer">
        <p>This document is generated by CoreX OS and constitutes a record of FICA compliance verification for {{ $personal['full_name'] ?? $submission->contact?->full_name ?? 'the client' }}.</p>
        <p style="margin-top: 3px;">{{ $agency->name ?? 'Home Finders Coastal' }} — Generated {{ now()->format('d M Y H:i') }}</p>
    </div>
</body>
</html>
