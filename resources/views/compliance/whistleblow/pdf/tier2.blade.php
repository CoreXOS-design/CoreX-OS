<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PPRA Complaint — HFC-WB-{{ $complaint->id }}</title>
    <style>
        @page { size: A4; margin: 20mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', 'Segoe UI', 'Helvetica Neue', Arial, sans-serif; font-size: 10.5pt; color: #1e293b; line-height: 1.55; }

        .cover { page-break-after: always; position: relative; padding-top: 30mm; }
        .cover-header { text-align: center; margin-bottom: 20mm; }
        .cover-header h1 { font-size: 18pt; color: #0f172a; font-weight: 800; letter-spacing: -0.3pt; margin-bottom: 6pt; }
        .cover-header h2 { font-size: 13pt; color: #475569; font-weight: 500; }
        .severity-badge { position: absolute; top: 20mm; right: 0; background: #f59e0b; color: #fff; font-size: 10pt; font-weight: 700; padding: 6pt 14pt; border-radius: 3px; letter-spacing: 1pt; }
        .cover-meta { margin-top: 12mm; border: 1px solid #e2e8f0; border-radius: 3px; padding: 14pt 16pt; }
        .cover-meta-row { display: flex; justify-content: space-between; padding: 5pt 0; border-bottom: 1px solid #f1f5f9; }
        .cover-meta-row:last-child { border-bottom: none; }
        .cover-meta-label { color: #64748b; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5pt; flex: 0 0 160pt; }
        .cover-meta-value { font-weight: 600; color: #0f172a; flex: 1; text-align: right; }
        .tier-subtitle { text-align: center; margin-top: 10mm; font-size: 11pt; color: #f59e0b; font-weight: 600; padding: 8pt 16pt; border: 2px solid #f59e0b; border-radius: 3px; display: inline-block; }
        .tier-subtitle-wrap { text-align: center; margin-top: 10mm; }

        .section { margin-bottom: 14pt; }
        .section-title { font-size: 12pt; font-weight: 700; color: #0f172a; border-bottom: 2px solid #0d9488; padding-bottom: 4pt; margin-bottom: 10pt; text-transform: uppercase; letter-spacing: 0.5pt; }
        .field-grid { display: grid; grid-template-columns: 150pt 1fr; gap: 4pt 10pt; margin-bottom: 10pt; }
        .field-label { color: #64748b; font-size: 9.5pt; }
        .field-value { font-weight: 500; color: #0f172a; }
        .narrative { background: #f8fafc; border-left: 3px solid #0d9488; padding: 10pt 14pt; margin: 10pt 0; font-size: 10.5pt; line-height: 1.7; }
        .legal-cite { background: #fffbeb; border: 1px solid #fde68a; border-radius: 3px; padding: 10pt 14pt; margin: 8pt 0; }
        .legal-cite h4 { color: #92400e; font-size: 10pt; margin-bottom: 4pt; }
        .legal-cite ul { margin-left: 16pt; font-size: 9.5pt; color: #374151; }
        .legal-cite li { margin-bottom: 3pt; }

        .evidence-item { border: 1px solid #e2e8f0; border-radius: 3px; padding: 10pt 12pt; margin-bottom: 8pt; }
        .evidence-header { display: flex; justify-content: space-between; margin-bottom: 4pt; }
        .evidence-type { font-weight: 600; color: #0d9488; font-size: 9.5pt; text-transform: uppercase; letter-spacing: 0.5pt; }
        .evidence-date { color: #94a3b8; font-size: 9pt; }
        .evidence-desc { font-size: 10pt; color: #374151; }
        .evidence-filename { font-size: 9pt; color: #64748b; font-style: italic; margin-top: 3pt; }

        .audit-row { display: flex; gap: 10pt; padding: 5pt 0; border-bottom: 1px solid #f1f5f9; font-size: 9.5pt; }
        .audit-time { flex: 0 0 120pt; color: #64748b; }
        .audit-user { flex: 0 0 120pt; font-weight: 500; }
        .audit-action { flex: 1; color: #374151; }

        .footer { margin-top: 16pt; padding-top: 8pt; border-top: 1px solid #e2e8f0; font-size: 8pt; color: #94a3b8; }
        .disclaimer { font-size: 8pt; color: #94a3b8; margin-top: 10pt; font-style: italic; line-height: 1.5; }
    </style>
</head>
<body>

{{-- ═══════════ COVER PAGE ═══════════ --}}
<div class="cover">
    <div class="severity-badge">MEDIUM</div>

    <div class="cover-header">
        <h1>Property Practitioners Regulatory Authority</h1>
        <h2>Formal Complaint Submission</h2>
    </div>

    <div class="cover-meta">
        <div class="cover-meta-row">
            <span class="cover-meta-label">Complaint Reference</span>
            <span class="cover-meta-value">HFC-WB-{{ $complaint->id }}</span>
        </div>
        <div class="cover-meta-row">
            <span class="cover-meta-label">Lodging Agency</span>
            <span class="cover-meta-value">{{ $agency->trading_name ?? $agency->name }}</span>
        </div>
        <div class="cover-meta-row">
            <span class="cover-meta-label">Reporter</span>
            <span class="cover-meta-value">{{ $reporter->name }}{{ $reporter->designation ? ', ' . $reporter->designation : '' }}</span>
        </div>
        @if($reporter->ffc_number ?? null)
        <div class="cover-meta-row">
            <span class="cover-meta-label">Reporter FFC</span>
            <span class="cover-meta-value">{{ $reporter->ffc_number }}</span>
        </div>
        @endif
        <div class="cover-meta-row">
            <span class="cover-meta-label">Date of Complaint</span>
            <span class="cover-meta-value">{{ $complaint->created_at->format('d F Y') }}</span>
        </div>
        <div class="cover-meta-row">
            <span class="cover-meta-label">Complaint Tier</span>
            <span class="cover-meta-value">Tier 2</span>
        </div>
    </div>

    <div class="tier-subtitle-wrap">
        <span class="tier-subtitle">Tier 2 — Public-Data Breach (No FFC Displayed)</span>
    </div>
</div>

{{-- ═══════════ SECTION 1: SUBJECT OF COMPLAINT ═══════════ --}}
<div class="section">
    <h3 class="section-title">1. Subject of Complaint</h3>
    <div class="field-grid">
        <span class="field-label">Subject Agency</span>
        <span class="field-value">{{ $complaint->subject_agency_name }}</span>

        @if($complaint->subject_practitioner_name)
        <span class="field-label">Subject Practitioner</span>
        <span class="field-value">{{ $complaint->subject_practitioner_name }}</span>
        @endif

        @if($complaint->subject_ffc_number)
        <span class="field-label">FFC Number</span>
        <span class="field-value">{{ $complaint->subject_ffc_number }}</span>
        @endif

        @if($complaint->subject_practitioner_email)
        <span class="field-label">Email</span>
        <span class="field-value">{{ $complaint->subject_practitioner_email }}</span>
        @endif

        @if($complaint->subject_practitioner_phone)
        <span class="field-label">Phone</span>
        <span class="field-value">{{ $complaint->subject_practitioner_phone }}</span>
        @endif
    </div>
</div>

{{-- ═══════════ SECTION 2: PROPERTY DETAILS ═══════════ --}}
<div class="section">
    <h3 class="section-title">2. Property Details</h3>
    <div class="field-grid">
        <span class="field-label">Property Address</span>
        <span class="field-value">{{ $complaint->property_address }}</span>

        @if($complaint->property_portal_url)
        <span class="field-label">Portal URL</span>
        <span class="field-value"><a href="{{ $complaint->property_portal_url }}">{{ $complaint->property_portal_url }}</a></span>
        @endif

        @if($complaint->portal_source)
        <span class="field-label">Portal Source</span>
        <span class="field-value">{{ strtoupper($complaint->portal_source) }}</span>
        @endif

        @if($complaint->portal_listing_ref)
        <span class="field-label">Listing Reference</span>
        <span class="field-value">{{ $complaint->portal_listing_ref }}</span>
        @endif
    </div>
</div>

{{-- ═══════════ SECTION 3: TIER 2 NARRATIVE ═══════════ --}}
<div class="section">
    <h3 class="section-title">3. Complaint Narrative</h3>

    <div class="narrative">
        On {{ $complaint->created_at->format('d F Y') }}, the lodging agency identified an advertisement for the subject property on {{ $complaint->portal_source ? strtoupper($complaint->portal_source) : 'a property portal' }} which does not display a valid Fidelity Fund Certificate (FFC) number in contravention of the Property Practitioners Act 22 of 2019.
    </div>

    @if($complaint->agent_notes)
    <div style="margin-top: 8pt;">
        <strong style="font-size: 9.5pt; color: #64748b;">Agent Notes:</strong>
        <p style="margin-top: 4pt;">{{ $complaint->agent_notes }}</p>
    </div>
    @endif

    <div class="legal-cite">
        <h4>Section of the Property Practitioners Act 22 of 2019 Cited</h4>
        <ul>
            <li><strong>Section 61</strong> — FFC Display Requirement: A property practitioner must display their fidelity fund certificate number in all marketing material, advertising, and correspondence relating to property transactions.</li>
        </ul>
    </div>
</div>

{{-- ═══════════ SECTION 4: EVIDENCE APPENDIX ═══════════ --}}
<div class="section">
    <h3 class="section-title">4. Evidence Appendix</h3>

    @forelse($evidence as $item)
    <div class="evidence-item">
        <div class="evidence-header">
            <span class="evidence-type">{{ str_replace('_', ' ', $item->evidence_type) }}</span>
            <span class="evidence-date">{{ $item->created_at->format('d M Y H:i') }}</span>
        </div>
        @if($item->description)
        <p class="evidence-desc">{{ $item->description }}</p>
        @endif
        @if($item->original_filename)
        <p class="evidence-filename">File: {{ $item->original_filename }}{{ $item->size_bytes ? ' (' . number_format($item->size_bytes / 1024, 1) . ' KB)' : '' }}</p>
        @endif
    </div>
    @empty
    <p style="color: #94a3b8; font-style: italic;">No evidence attachments.</p>
    @endforelse
</div>

{{-- ═══════════ SECTION 5: AUDIT TIMELINE ═══════════ --}}
<div class="section">
    <h3 class="section-title">5. Audit Timeline</h3>

    @foreach($auditLog as $entry)
    <div class="audit-row">
        <span class="audit-time">{{ $entry->created_at->format('d M Y H:i') }}</span>
        <span class="audit-user">{{ $entry->user ? $entry->user->name : 'System' }}</span>
        <span class="audit-action">{{ str_replace('_', ' ', ucfirst($entry->action)) }}</span>
    </div>
    @endforeach
</div>

{{-- ═══════════ FOOTER ═══════════ --}}
<div class="footer">
    <p>{{ $agency->trading_name ?? $agency->name }} | {{ $agency->email }} | {{ $agency->phone }}</p>
    <p class="disclaimer">
        This complaint is submitted in good faith based on information available to the lodging agency.
        Submitted via CoreX OS compliance reporting module.
    </p>
</div>

</body>
</html>
