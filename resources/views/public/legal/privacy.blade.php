@extends('public.legal.layout')

@section('legal-title', 'Privacy Policy')

@section('legal-body')
    <p>
        This Privacy Policy explains how <strong>CoreX OS</strong> ("CoreX", "we", "us"),
        the real-estate operating system operated by <strong>Home Finders Coastal</strong>,
        collects, uses, stores and protects your information. It is provided in line with
        the South African <strong>Protection of Personal Information Act (POPIA)</strong> and
        applies to all users of CoreX, including estate agents and agencies who connect their
        social-media accounts to CoreX.
    </p>

    <h2>1. Who we are</h2>
    <p>
        CoreX OS is software used by real-estate agencies to manage properties, contacts,
        deals and marketing. The responsible party (data controller) for personal information
        processed through CoreX is Home Finders Coastal. For any privacy query you can reach us
        at <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>
    <p>
        <strong>Registered company details:</strong> [TODO — company registration number,
        registered address and Information Officer name to be inserted here. Contact
        {{ $contactEmail }} in the meantime.]
    </p>

    <h2>2. Information we collect, and how</h2>
    <p>We collect the following categories of information:</p>
    <ul>
        <li><strong>Account &amp; profile information</strong> — your name, email address, phone
            number, role and agency. This is created for you by your agency administrator when
            your account is set up (CoreX is invite-only) and can be updated by you or your
            administrator afterwards.</li>
        <li><strong>Property, deal and contact data</strong> — property listings and their
            photographs, buyer/seller/tenant contact records, buyer requirement profiles, tasks,
            calendar events and deal/transaction records that you or your agency create and
            manage in CoreX as part of normal use.</li>
        <li><strong>FICA and compliance documents</strong> — identity, proof-of-address and other
            documents uploaded to verify clients under South Africa's Financial Intelligence
            Centre Act (FICA) and PPRA record-keeping requirements.</li>
        <li><strong>Push notification tokens</strong> — a device-specific token registered
            automatically when you enable notifications, used only to deliver notifications to
            that device (see section 6).</li>
        <li><strong>Voice recordings made through Ellie</strong> — audio captured only while you
            are actively pressing and holding the microphone button to speak an instruction to
            Ellie, our in-app voice assistant (see section 4).</li>
        <li><strong>Social-media connection data</strong> — when you connect a Facebook or
            Instagram account (see section 5).</li>
        <li><strong>Usage data</strong> — log records, device and browser information used to
            keep the service secure and reliable.</li>
    </ul>

    <h2>3. How we use your information</h2>
    <ul>
        <li>To provide and operate the CoreX platform and its features.</li>
        <li>To let your agency manage properties, contacts, deals, documents and communications.</li>
        <li>To verify client identity where required by FICA/PPRA.</li>
        <li>To deliver push notifications you have enabled.</li>
        <li>To turn a spoken instruction to Ellie into a draft calendar entry or task (section 4).</li>
        <li>To publish the marketing content you create to the social accounts you connect, and to show you analytics on it.</li>
        <li>To secure the service, prevent abuse and meet our legal obligations.</li>
    </ul>

    <h2>4. Ellie voice assistant — speech-to-text and Anthropic (Claude)</h2>
    <p>
        Ellie lets you speak an instruction (for example, "book a viewing with the Springfields
        for Friday at 10") instead of typing it. Here is exactly what happens to that recording,
        step by step:
    </p>
    <ul>
        <li><strong>Recording</strong> — audio is captured only while you press and hold the
            microphone button, and only after you have granted the app microphone permission and
            given explicit in-app consent to use Ellie's voice feature before the first time it
            is used.</li>
        <li><strong>Speech-to-text (self-hosted, no third party)</strong> — the audio is sent to
            our own self-hosted transcription service, running on our own infrastructure. It is
            converted to text there and the audio is then discarded — it is <strong>not</strong>
            written to any long-term storage, and it never leaves our infrastructure or goes to
            any third party.</li>
        <li><strong>Anthropic (Claude API)</strong> — only the resulting <strong>text</strong> of
            your instruction is sent to Anthropic's Claude API, which interprets it and returns a
            draft calendar entry or task. <strong>Nothing else is sent</strong>: not your contact
            list, not client records, not photographs, not your location, not your credentials,
            and no device or advertising identifiers. Anthropic processes this text under
            commercial API terms that provide protection equivalent to this policy, and Anthropic
            does not use it to train its models.</li>
        <li><strong>What we store afterwards</strong> — the transcript text (not the audio) is
            saved as part of the calendar event or task it created, so you can see what Ellie
            heard. It is retained for as long as that calendar event or task exists, and is
            removed when the event or task is deleted.</li>
    </ul>

    <h2>5. Facebook &amp; Instagram (Meta) integration</h2>
    <p>
        CoreX lets you connect your Facebook Page and linked Instagram Business account so you
        can publish property marketing posts directly from CoreX. When you choose to connect,
        Meta asks you to authorise CoreX. With your permission we access only what is needed to
        publish and measure your posts:
    </p>
    <ul>
        <li><strong>The list of Facebook Pages you manage</strong>, so you can pick which Page to post to.</li>
        <li><strong>Permission to publish posts</strong> to the Page you select, on your behalf.</li>
        <li><strong>Post performance insights</strong> (reach, impressions, likes, comments, shares) so you can see how your listings performed.</li>
        <li><strong>Your linked Instagram Business account</strong> details, where you choose to publish to Instagram.</li>
    </ul>
    <p>
        To make publishing work we store a <strong>Page access token</strong> issued by Meta,
        the Page (or Instagram account) ID and name, and the token's expiry date. We do
        <strong>not</strong> collect your Facebook password, your private messages, your friends
        list, or any personal content beyond what is described above. CoreX never posts without
        an explicit action by you.
    </p>
    <p>
        You can disconnect a connected account at any time from within CoreX. Disconnecting
        removes the stored token so CoreX can no longer access that account.
    </p>

    <h2>6. Property24, and push notifications (Firebase)</h2>
    <p>
        <strong>Property24</strong> — reference property data flows into CoreX from Property24 to
        support our workflows. When your agency syndicates one of its own listings to Property24,
        CoreX also sends the listing details (address, description, price, photographs) and, so
        that enquiries can reach the right person, the listing agent's <strong>name and profile
        photo</strong> to Property24. No client, buyer or contact data is sent to Property24 —
        only the listing and its listing agent.
    </p>
    <p>
        <strong>Google Firebase Cloud Messaging (FCM)</strong> — we use Firebase solely to deliver
        push notifications (new leads, messages, deal and task updates) to your device using its
        push token. Firebase is not used for analytics, advertising or attribution in CoreX.
    </p>

    <h2>7. No analytics, advertising or tracking</h2>
    <p>
        CoreX OS contains <strong>no analytics SDK, no advertising SDK, and no cross-app or
        cross-site tracking</strong> of any kind. We do not use your data for advertising, and we
        do not sell or rent your personal information to anyone.
    </p>

    <h2>8. How we share information</h2>
    <p>
        We do not sell your personal information. We share it only as described above, with:
    </p>
    <ul>
        <li><strong>Anthropic</strong>, the transcribed text of an Ellie voice instruction only (section 4).</li>
        <li><strong>Meta Platforms</strong>, when you publish to or read insights from Facebook/Instagram (section 5).</li>
        <li><strong>Property24</strong>, listing data and the listing agent's name/photo when your agency syndicates a listing (section 6).</li>
        <li><strong>Google Firebase</strong>, for push notification delivery only (section 6).</li>
        <li><strong>Service providers</strong> who host and operate CoreX on our behalf, under confidentiality obligations.</li>
        <li><strong>Authorities</strong>, where we are required to by law.</li>
    </ul>

    <h2>9. Deleting your account</h2>
    <p>
        You can permanently delete your CoreX account yourself, in the app, at
        <strong>Settings → Delete account</strong>. No email or phone call is needed. Deletion is
        <strong>immediate</strong>: your login credentials are removed, every active session and
        device token for your account is revoked, and you cannot sign back in to that account —
        it cannot be undone by you.
    </p>
    <p>
        Deleting your login does <strong>not</strong> delete your agency's business records.
        Property, deal and FICA/compliance data tied to your account is retained by the agency as
        a business record, as required under the Financial Intelligence Centre Act (FICA) and
        PPRA record-keeping rules — deletion removes your personal login, not the agency's
        transaction history.
    </p>
    <p>
        If you have connected a Facebook or Instagram account, see our
        <a href="{{ route('public.data-deletion') }}">Data Deletion</a> page for how to remove
        that connection's data specifically.
    </p>

    <h2>10. How long we keep information</h2>
    <ul>
        <li><strong>Account &amp; profile data</strong> — for as long as your account is active, and removed on account deletion (section 9).</li>
        <li><strong>Contact, consent and access-log records</strong> — a minimum of 5 years, as configured by your agency, in line with FICA/POPIA record-keeping obligations.</li>
        <li><strong>Communications archive</strong> (email/WhatsApp sent through CoreX) — 5 years.</li>
        <li><strong>Signed documents and e-signature audit logs</strong> — 7 years, per Property Practitioners Act record-keeping requirements.</li>
        <li><strong>FICA verification</strong> — revalidated on a rolling basis; verification records are kept as required by FICA.</li>
        <li><strong>Ellie voice transcripts</strong> — tied to the calendar event or task they created; removed when that event or task is deleted (section 4). The audio itself is never stored (section 4).</li>
        <li><strong>Push notification tokens</strong> — for as long as needed to deliver notifications to your device, and removed when a token becomes invalid or you sign out.</li>
        <li><strong>Social-media access tokens</strong> — kept only while the connection is active and removed when you disconnect the account or request deletion.</li>
    </ul>

    <h2>11. Your rights under POPIA</h2>
    <p>
        Personal information is processed under the South African Protection of Personal
        Information Act (POPIA), on the basis that processing is necessary to perform our
        services to you and your agency, and to meet our legal obligations (including FICA and
        PPRA). You have the right to:
    </p>
    <ul>
        <li>Access the personal information we hold about you.</li>
        <li>Request correction of inaccurate or incomplete information.</li>
        <li>Request deletion of your personal information, subject to section 9 above.</li>
        <li>Object to certain processing, and withdraw consent where processing is based on consent.</li>
        <li>Lodge a complaint with our Information Officer (below) or with the South African
            Information Regulator (<a href="https://inforegulator.org.za" rel="noopener">inforegulator.org.za</a>)
            if you believe your information has been mishandled.</li>
    </ul>
    <p>
        <strong>Information Officer:</strong> [TODO — Information Officer name and dedicated
        contact email to be inserted here. Until then, direct requests to
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.]
    </p>

    <h2>12. Security</h2>
    <p>
        We use appropriate technical and organisational measures to protect your information,
        including encrypted transport (HTTPS) and restricted access to stored credentials.
    </p>

    <h2>13. Changes to this policy</h2>
    <p>
        We may update this policy from time to time. The "last updated" date at the top of this
        page reflects the most recent change.
    </p>

    <h2>14. Contact us</h2>
    <p>
        For any privacy question or request, email
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>
@endsection
