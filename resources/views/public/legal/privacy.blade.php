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

    <h2>2. Information we collect</h2>
    <ul>
        <li><strong>Account information</strong> — your name, email address, agency, role and login credentials.</li>
        <li><strong>Property and deal data</strong> — listings, contacts, documents and transactions you create or manage in CoreX.</li>
        <li><strong>Social-media connection data</strong> — when you connect a Facebook or Instagram account (see section 3).</li>
        <li><strong>Usage data</strong> — log records, device and browser information used to keep the service secure and reliable.</li>
    </ul>

    <h2>3. Facebook &amp; Instagram (Meta) integration</h2>
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

    <h2>4. How we use your information</h2>
    <ul>
        <li>To provide and operate the CoreX platform and its features.</li>
        <li>To publish the marketing content you create to the social accounts you connect.</li>
        <li>To show you analytics on the content you have published.</li>
        <li>To secure the service, prevent abuse and meet our legal obligations.</li>
    </ul>

    <h2>5. How we share information</h2>
    <p>
        We do not sell your personal information. We share it only with:
    </p>
    <ul>
        <li><strong>Meta Platforms</strong>, when you publish to or read insights from Facebook/Instagram (governed by Meta's own terms and policies).</li>
        <li><strong>Service providers</strong> who host and operate CoreX on our behalf, under confidentiality obligations.</li>
        <li><strong>Authorities</strong>, where we are required to by law.</li>
    </ul>

    <h2>6. How long we keep it</h2>
    <p>
        We keep your information for as long as your account is active and as long as needed to
        provide the service or to meet legal and regulatory requirements. Social-media access
        tokens are kept only while the connection is active and are removed when you disconnect
        the account or request deletion.
    </p>

    <h2>7. Your rights</h2>
    <p>
        Under POPIA you have the right to access, correct, or request deletion of your personal
        information, and to object to certain processing. To exercise these rights — or to
        request deletion of data CoreX holds about you, including any data obtained via the
        Facebook/Instagram integration — see our
        <a href="{{ route('public.data-deletion') }}">Data Deletion</a> page or contact us at
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <h2>8. Security</h2>
    <p>
        We use appropriate technical and organisational measures to protect your information,
        including encrypted transport (HTTPS) and restricted access to stored credentials.
    </p>

    <h2>9. Changes to this policy</h2>
    <p>
        We may update this policy from time to time. The "last updated" date at the top of this
        page reflects the most recent change.
    </p>

    <h2>10. Contact us</h2>
    <p>
        For any privacy question or request, email
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>
@endsection
