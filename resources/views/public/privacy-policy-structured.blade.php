{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    Phase 9c (AT-16) — canonical public privacy policy (POPIA s18).
    Generated from structured agency fields; per-agency aware (no hardcoded
    agency values). Standalone public page — no auth, no sidebar.
--}}
<!--
    PRIVACY POLICY — DRAFT BASELINE.
    This content is system-generated from the agency's structured compliance
    fields as a POPIA-aligned baseline. It is PENDING LEGAL REVIEW. Once a
    lawyer has signed it off, set COREX_PRIVACY_POLICY_DRAFT_BANNER=false to
    remove the public draft banner.
-->
@php
    $responsibleParty = $agency->trading_name ?: $agency->name;
    $ioName  = $ioAppointment?->full_name;
    $ioTitle = $ioAppointment?->title ?: 'Information Officer';
    $ioEmail = $ioAppointment?->email ?: $agency->email;
    $ioCell  = $ioAppointment?->cell;
    $ioRegistered = $ioAppointment?->regulator_registered_on;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Privacy Policy — {{ $responsibleParty }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: {{ $agency->default_color ?: '#0b2a4a' }};
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --surface: #ffffff;
            --surface-2: #f4f6fb;
            --ds-amber: #f59e0b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary, #111827);
            background: var(--surface-2, #f4f6fb);
            line-height: 1.6;
        }
        header.brand-bar {
            background: var(--brand, #0b2a4a); color: #fff;
            padding: 18px 24px; display: flex; align-items: center; gap: 16px;
        }
        header.brand-bar img { height: 44px; width: auto; background: #fff; padding: 4px; border-radius: 4px; }
        header.brand-bar .agency-name { font-size: 1.05rem; font-weight: 600; }
        .draft-banner {
            max-width: 820px; margin: 24px auto 0; padding: 12px 18px;
            background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, #fff);
            border: 1px solid var(--ds-amber, #f59e0b); border-radius: 6px;
            color: #7c4a03; font-size: 0.85rem; font-weight: 600;
        }
        main.legal-content {
            max-width: 820px; margin: 24px auto; padding: 36px;
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 6px;
        }
        main.legal-content h1 {
            font-size: 1.6rem; margin: 0 0 4px; color: var(--text-primary, #111827);
            border-bottom: 2px solid var(--brand, #0b2a4a); padding-bottom: 10px;
        }
        main.legal-content h2 { font-size: 1.15rem; margin: 28px 0 8px; color: var(--text-primary, #111827); }
        main.legal-content p  { margin: 0 0 12px; color: var(--text-primary, #111827); }
        main.legal-content ul { margin: 0 0 12px; padding-left: 22px; }
        main.legal-content li { margin-bottom: 4px; }
        main.legal-content a  { color: var(--brand, #0b2a4a); }
        .intro-meta { color: var(--text-secondary, #4b5563); font-size: 0.85rem; margin-bottom: 8px; }
        .contact-card {
            background: var(--surface-2, #f4f6fb); border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px; padding: 14px 18px; margin: 8px 0 16px;
        }
        .contact-card strong { color: var(--text-primary, #111827); }
        .agency-authored { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border, #e5e7eb); }
        footer.doc-footer {
            max-width: 820px; margin: 0 auto 40px; padding: 0 36px;
            font-size: 0.75rem; color: var(--text-muted, #6b7280); text-align: center;
        }
    </style>
</head>
<body>
    <header class="brand-bar">
        @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $responsibleParty }}">
        @endif
        <span class="agency-name">{{ $responsibleParty }}</span>
    </header>

    @if($draftBanner)
        <div class="draft-banner">Draft — pending legal review. This privacy notice is a generated baseline and has not yet been approved by a legal practitioner.</div>
    @endif

    <main class="legal-content">
        <h1>Privacy Policy</h1>
        <p class="intro-meta">
            Responsible party: <strong>{{ $responsibleParty }}</strong>
            @if($agency->ppra_number)
                · PPRA registration: {{ $agency->ppra_number }}@if($agency->ppra_registered_at) (registered {{ $agency->ppra_registered_at->format('j F Y') }})@endif
            @endif
        </p>

        <p>
            This privacy policy explains how {{ $responsibleParty }} ("we", "us") collects, uses, stores and
            protects your personal information in accordance with the Protection of Personal Information Act 4 of 2013
            (POPIA). It applies to all personal information we process about sellers, buyers, tenants, landlords and
            other data subjects who interact with us.
        </p>

        <h2>1. Purposes for which we collect your information</h2>
        <p>We collect and process personal information (such as your name, contact details and, where required, identity and financial information) for the following purposes:</p>
        <ul>
            <li>To provide property practitioner services — listing, marketing, valuing, letting and selling property.</li>
            <li>To communicate with you about properties, presentations, offers, mandates and related transactions.</li>
            <li>To meet our regulatory obligations under the Property Practitioners Act 22 of 2019, the Financial Intelligence Centre Act 38 of 2001 (FICA) and related legislation — including identity verification and record-keeping.</li>
            <li>To prepare, sign and retain agreements and mandatory disclosures.</li>
            <li>To improve our services and respond to your enquiries.</li>
        </ul>

        <h2>2. Consequences of not providing your information</h2>
        <p>
            Where information is required by law (for example FICA identity verification) or is necessary to perform a
            service you have requested, we cannot proceed without it. If you choose not to provide such information, we
            may be unable to act on your behalf, conclude a transaction, or comply with our legal obligations. Where
            information is optional, declining to provide it will not affect the core service.
        </p>

        <h2>3. Information Officer</h2>
        <p>In line with POPIA section 55, our designated Information Officer is responsible for ensuring our compliance with POPIA and for handling data-subject queries.</p>
        <div class="contact-card">
            @if($ioName)
                <strong>{{ $ioName }}</strong> — {{ $ioTitle }}<br>
                @if($ioEmail)Email: <a href="mailto:{{ $ioEmail }}">{{ $ioEmail }}</a><br>@endif
                @if($ioCell)Telephone: {{ $ioCell }}<br>@endif
                @if($ioRegistered)Registered with the Information Regulator on {{ $ioRegistered->format('j F Y') }}.@endif
            @else
                Our Information Officer is in the process of being designated. In the interim, please direct privacy
                queries to
                @if($agency->email)<a href="mailto:{{ $agency->email }}">{{ $agency->email }}</a>@else our office @endif.
            @endif
        </div>

        <h2>4. Your rights as a data subject</h2>
        <p>Under POPIA you have the right to:</p>
        <ul>
            <li><strong>Access</strong> the personal information we hold about you.</li>
            <li><strong>Correct</strong> personal information that is inaccurate, irrelevant, excessive, outdated or incomplete.</li>
            <li><strong>Deletion</strong> — request that we delete or destroy personal information we are no longer authorised to retain.</li>
            <li><strong>Object</strong> to the processing of your personal information on reasonable grounds, and to opt out of direct marketing.</li>
        </ul>
        <p>
            To exercise any of these rights, contact our Information Officer using the details above.
            You also have the right to lodge a complaint with the Information Regulator (see section 7).
        </p>

        <h2>5. How long we keep your information</h2>
        <p>
            We retain personal information only for as long as necessary to fulfil the purposes above and to meet our
            legal record-keeping obligations. Our standard retention period for contact records is
            <strong>{{ $retentionYears }} {{ \Illuminate\Support\Str::plural('year', $retentionYears) }}</strong>
            after our last interaction with you, unless a longer period is required by law (for example, FICA records
            are retained for at least five years). After the retention period, records are securely destroyed or
            de-identified.
        </p>

        <h2>6. Cross-border processing</h2>
        <p>
            Our systems are hosted in South Africa. Some drafting and analysis features are assisted by the Claude AI
            service operated by Anthropic, PBC (United States). Where personal information is processed by this service
            it constitutes a cross-border transfer. This processing is governed by a Data Processing Agreement with
            appropriate safeguards, and data submitted via the Anthropic API is not used to train its models.
        </p>

        <h2>7. The Information Regulator</h2>
        <p>If you are not satisfied with how we have handled your personal information, you may contact the Information Regulator (South Africa):</p>
        <div class="contact-card">
            <strong>The Information Regulator (South Africa)</strong><br>
            JD House, 27 Stiemens Street, Braamfontein, Johannesburg, 2001<br>
            P.O. Box 31533, Braamfontein, Johannesburg, 2017<br>
            Complaints: <a href="mailto:POPIAComplaints@inforegulator.org.za">POPIAComplaints@inforegulator.org.za</a><br>
            Enquiries: <a href="mailto:enquiries@inforegulator.org.za">enquiries@inforegulator.org.za</a><br>
            Website: <a href="https://inforegulator.org.za" target="_blank" rel="noopener">https://inforegulator.org.za</a>
        </div>

        <h2>8. Contacting us</h2>
        <p>
            {{ $responsibleParty }}@if($agency->address) — {{ $agency->address }}@endif.
            @if($agency->email) Email: <a href="mailto:{{ $agency->email }}">{{ $agency->email }}</a>.@endif
            @if($agency->phone) Telephone: {{ $agency->phone }}.@endif
        </p>

        @if($agencyMarkdownHtml)
            <div class="agency-authored">
                <h2>Additional agency-specific terms</h2>
                {{-- Operator-authored markdown, trusted at write time in Company Settings. --}}
                {!! $agencyMarkdownHtml !!}
            </div>
        @endif
    </main>

    <footer class="doc-footer">
        @if($agency->email)
            For privacy queries contact <a href="mailto:{{ $agency->email }}" style="color: var(--text-muted, #6b7280);">{{ $agency->email }}</a>
        @endif
    </footer>
</body>
</html>
