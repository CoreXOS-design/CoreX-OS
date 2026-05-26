<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Important: Verifying Your Agent's Credentials</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px; color: #1e293b; line-height: 1.75; background: #f8fafc; }
        .wrapper { max-width: 640px; margin: 0 auto; background: #ffffff; }
        .header { background: #7c2d12; padding: 28px 32px; }
        .header h1 { color: #ffffff; font-size: 20px; font-weight: 700; margin-bottom: 6px; }
        .header p { color: #fdba74; font-size: 13px; }
        .body { padding: 32px; }
        .agent-msg { background: #f0fdf4; border-left: 4px solid #22c55e; padding: 16px 20px; margin-bottom: 28px; font-size: 14px; color: #15803d; border-radius: 0 6px 6px 0; }
        h2 { font-size: 17px; font-weight: 700; color: #0f172a; margin: 28px 0 12px; padding-bottom: 8px; border-bottom: 2px solid #dc2626; }
        h3 { font-size: 15px; font-weight: 700; color: #0f172a; margin: 20px 0 8px; }
        p { margin-bottom: 14px; }
        .callout { background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 16px 20px; margin: 16px 0; }
        .callout-danger { background: #fef2f2; border: 1px solid #fecaca; }
        .callout h4 { color: #92400e; font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        .callout-danger h4 { color: #991b1b; }
        .callout p, .callout li { font-size: 14px; }
        ul { margin: 8px 0 14px 20px; }
        li { margin-bottom: 6px; }
        .case-ref { font-style: italic; color: #64748b; font-size: 13px; }
        .section-divider { border: none; border-top: 1px solid #e2e8f0; margin: 28px 0; }
        .warning-banner { background: #7c2d12; color: #ffffff; border-radius: 6px; padding: 16px 20px; margin: 16px 0; font-weight: 600; }
        .cta-box { background: #0f172a; color: #ffffff; border-radius: 6px; padding: 24px; margin: 28px 0; }
        .cta-box h3 { color: #22c55e; margin-top: 0; }
        .cta-box p { color: #cbd5e1; }
        .cta-box .highlight { color: #ffffff; font-weight: 600; }
        .sources { background: #f1f5f9; border-radius: 6px; padding: 20px; margin-top: 28px; font-size: 12px; color: #475569; }
        .sources h4 { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .disclaimer { font-size: 11px; color: #94a3b8; font-style: italic; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; line-height: 1.6; }
        .footer { background: #f1f5f9; padding: 20px 32px; text-align: center; font-size: 12px; color: #64748b; }
        .footer a { color: #0d9488; }
        strong { color: #0f172a; }
    </style>
</head>
<body>
<div class="wrapper">

<div class="header">
    <h1>Important: Verifying Your Agent's Credentials</h1>
    <p>Urgent information for property sellers &mdash; {{ $agency->trading_name ?? $agency->name }}</p>
</div>

<div class="body">

<p>Dear {{ $sellerName ?? 'Valued Seller' }},</p>

<div class="warning-banner">
    NOTE: This information is being shared because a property practitioner associated with your property could not be verified on the PPRA register. Operating as a property practitioner without a valid Fidelity Fund Certificate is a criminal offence under Section 49 of the Property Practitioners Act.
</div>

{{-- ═══════════ BLOCK C — How to check an agent ═══════════ --}}
<h2>How to Verify Any Agent You Work With</h2>

<h3>1. They Have a Valid FFC</h3>
<p>Every property practitioner in South Africa is required by law to hold a valid Fidelity Fund Certificate (FFC). This is issued by the Property Practitioners Regulatory Authority (PPRA) and is renewed every 3 years.</p>
<div class="callout">
    <h4>How to check</h4>
    <ul>
        <li>Ask to see their FFC. It is a physical certificate they must display at every place of business.</li>
        <li>The PPA requires the prescribed sentence about FFC validity on every letterhead and marketing material.</li>
        <li>Verify on PPRA's "Find a Property Practitioner" register at <strong>www.theppra.org.za</strong></li>
    </ul>
</div>

<h3>2. They Are Properly Registered</h3>
<p>Section 47 of the Property Practitioners Act requires every property practitioner to be registered with the PPRA. This includes estate agents, auctioneers, property managers, bond originators, homeowners associations, and property developers.</p>
<p>If their name does not appear on the PPRA register, they may be operating illegally. <strong>Section 49 of the Act makes operating as a property practitioner without an FFC a criminal offence &mdash; punishable by fine or imprisonment up to 10 years.</strong></p>

<h3>3. They Are a Real Agency</h3>
<p>Verify the agency name with PPRA. Some operators use confusing names that resemble legitimate agencies. If you are unsure, contact PPRA directly at <strong>info@theppra.org.za</strong></p>

<div class="callout-danger callout">
    <h4>What to Do RIGHT NOW</h4>
    <ul>
        <li>Do not sign anything</li>
        <li>Do not pay any deposit, listing fee, or commission</li>
        <li>Contact a registered conveyancer or attorney for a quick check</li>
        <li>Report your concerns to PPRA at <strong>complaints@theppra.org.za</strong></li>
    </ul>
</div>

<hr class="section-divider">

{{-- ═══════════ BLOCK B excerpts — risks ═══════════ --}}
<h2>Risks of Working With an Unregistered Agent</h2>

<h3>Validity of Your Deed of Sale</h3>
<p>If your agent does not hold a valid Fidelity Fund Certificate (FFC), Section 48(4) of the Property Practitioners Act states they must REPAY any commission they receive. Worse, if there are downstream issues with the sale, the absence of compliance documentation can be raised as evidence of an irregular transaction.</p>

<h3>Penalty Exposure &mdash; and Risk Transfer to YOU</h3>
<p>The PPRA can fine non-compliant property practitioners up to R10,000&ndash;R25,000 per breach, and prosecute serious offences with fines or imprisonment up to 10 years (Section 71 of the Act). If the agent disappears or is closed down by the regulator during your sale, YOU are left mid-transaction with no agent and no recourse.</p>

<h3>Asset Freeze Under FICA</h3>
<p>South Africa was grey-listed by FATF in February 2023. <strong>ABSA Bank Ltd v Financial Intelligence Centre</strong> (2013) established that the courts will uphold FIC penalties. The PPRA works with the FIC and may freeze suspicious transactions while investigating.</p>

<hr class="section-divider">

{{-- ═══════════ BLOCK D ═══════════ --}}
<h2>The Right Way Forward</h2>

<p>Selling your home is one of the biggest transactions of your life. Doing it correctly costs you nothing extra &mdash; and protects you from claims that can run into hundreds of thousands of rands years after the sale.</p>

<h3>The Choice Is Yours</h3>
<p>You do not have to sell through us. But if you choose to work with another agency, please insist on proper paperwork. Your financial wellbeing depends on it.</p>
<p>If you change your mind about how you want to sell, or have questions about the legal requirements, please reply to this email or call us. There is no obligation. We are here to inform, not to pressure.</p>

<div class="sources">
    <h4>Sources and Legal References</h4>
    <p><strong>Property Practitioners Act 22 of 2019</strong> &mdash; Sections 47-49 (FFC/registration/penalties), 67 (MDF), 71 (offences). theppra.org.za</p>
    <p><strong>Financial Intelligence Centre Act 38 of 2001</strong> &mdash; Section 21A (CDD), 45C (penalties). fic.gov.za</p>
    <p><strong>Court cases:</strong> ABSA Bank v FIC (2013) &bull; Wakefields Real Estate v Attree (2011) ZASCA 160</p>
    <p>PPRA Register: <a href="https://theppra.org.za">theppra.org.za</a></p>
</div>

<p class="disclaimer">This communication is for general information only and does not constitute legal advice. Property law is complex and fact-specific. For advice on your particular situation please consult a qualified conveyancer or attorney.</p>

</div>

<div class="footer">
    {{ $agency->trading_name ?? $agency->name }}
    @if($agency->phone) &bull; {{ $agency->phone }} @endif
    @if($agency->email) &bull; {{ $agency->email }} @endif
    <br>Powered by CoreX OS Compliance Module
</div>

</div>
</body>
</html>
