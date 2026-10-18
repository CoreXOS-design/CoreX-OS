@extends('public.legal.layout')

@section('legal-title', 'CoreX Chrome Extension — Privacy Policy')

@section('legal-body')
    <p>
        This page describes what the <strong>CoreX</strong> Chrome extension ("the extension") does,
        what data it reads and sends, and why. It supplements CoreX OS's main
        <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a> and is provided for the
        Chrome Web Store listing.
    </p>

    <h2>1. What the extension does</h2>
    <p>
        The extension is used by CoreX OS agents to bring property listing data into CoreX from
        Property24 and Private Property while browsing those sites in their own logged-in browser
        tab. It reads the page currently open in the active tab — it does not browse or scrape on
        its own, and it never runs in the background against pages you have not opened.
    </p>
    <p>Specifically, the extension can:</p>
    <ul>
        <li>Read a Property24 or Private Property search-results page to capture listings for
            prospecting.</li>
        <li>Read a single Property24 or Private Property listing page to pull it into CoreX as
            your agency's own property, or import it as "Other Agency Stock" (another agency's
            listing, shared with your buyers, never re-advertised — see
            <code>.ai/specs/other-agency-stock.md</code>).</li>
        <li>Read a CMA Info deeds-search result, or a Virtual Agent person/company lookup page,
            to capture contact and property information for compliance and prospecting use.</li>
    </ul>

    <h2>2. Data the extension sends to CoreX</h2>
    <p>
        The extension sends the page data described above — listing details, photos, addresses,
        and (for CMA Info / Virtual Agent) contact details — to your own agency's CoreX OS account
        over an authenticated HTTPS connection. It does not send this data anywhere else, and does
        not share it with any third party.
    </p>

    <h2>3. What the extension does NOT do</h2>
    <ul>
        <li>It does not read or transmit anything from sites other than the small, explicit list
            declared in its permissions (Property24, Private Property, CMA Info, The Virtual
            Agent).</li>
        <li>It never clicks a portal's "reveal phone number" or similar action on your behalf —
            that would register a lead with the portal, which the extension must never do
            silently.</li>
        <li>It does not collect browsing history, passwords, or any data unrelated to the specific
            capture/import action you trigger.</li>
        <li>It does not display third-party advertising and does not sell or transfer user data to
            third parties.</li>
    </ul>

    <h2>4. Authentication</h2>
    <p>
        The extension identifies you to CoreX using your own CoreX session or a personal API token
        you generate and paste into the extension's Settings screen yourself. That token is stored
        only in Chrome's local extension storage on your own device.
    </p>

    <h2>5. Contact</h2>
    <p>
        Questions about this extension's data handling can be sent to
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <p><em>Last updated: {{ $lastUpdated }}</em></p>
@endsection
