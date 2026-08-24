@extends('public.legal.layout')

@section('legal-title', 'Terms of Service')

@section('legal-body')
    <p>
        These Terms of Service ("Terms") govern access to and use of <strong>CoreX OS</strong>
        ("CoreX", "we", "us"), the real-estate operating system owned and operated by
        <strong>R R Technologies (Pty) Ltd</strong>. By signing in to or otherwise using CoreX,
        you agree to these Terms. If you do not agree, do not use CoreX.
    </p>

    <h2>1. Who can use CoreX</h2>
    <p>
        CoreX is <strong>invite-only</strong> business software for real-estate agencies and
        their practitioners. There is no public self-signup. An account is created for you by
        your agency's administrator, who is responsible for the accuracy of the information used
        to invite you and for authorising your access. You must be a current employee,
        contracted practitioner, or otherwise authorised representative of the inviting agency to
        hold a CoreX account.
    </p>

    <h2>2. Your account</h2>
    <p>
        You are responsible for keeping your login credentials confidential and for all activity
        that occurs under your account. Notify us immediately at
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> if you suspect unauthorised
        access. Your agency administrator may suspend or remove your access at any time, for
        example when your employment or mandate with the agency ends.
    </p>

    <h2>3. Acceptable use</h2>
    <p>You agree not to:</p>
    <ul>
        <li>Use CoreX for any unlawful purpose, or in a way that breaches the Property
            Practitioners Act, FICA, POPIA, the CPA, or any other applicable law.</li>
        <li>Attempt to access data, properties, deals, or accounts belonging to another agency
            without authorisation.</li>
        <li>Upload content you do not have the right to upload, or that infringes a third
            party's intellectual property or privacy rights.</li>
        <li>Probe, scan, or attempt to bypass the security or rate limits of CoreX or any
            connected third-party service (Meta, Property24, Firebase, or otherwise).</li>
        <li>Use CoreX to send unsolicited bulk communications (spam) to buyers, sellers, tenants,
            or any other contact.</li>
    </ul>

    <h2>4. Your content and data</h2>
    <p>
        Property listings, contact records, documents, marketing content, and other data you or
        your agency enter into CoreX ("Content") remain the property of you and your agency.
        You grant us a licence to host, process, and transmit that Content solely to provide
        CoreX's features to you — including, where you choose to use them, publishing marketing
        content to Facebook/Instagram, syndicating listings to Property24, and sending voice
        instructions to Ellie, our in-app assistant. Full detail on what is collected, how it is
        used, and how long it is kept is in our
        <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a>.
    </p>
    <p>
        You are responsible for the accuracy and lawfulness of Content you upload, including
        FICA/compliance documents, property descriptions, and photographs.
    </p>

    <h2>5. Third-party integrations</h2>
    <p>
        Where you choose to connect or use a third-party integration — Facebook/Instagram (Meta),
        Property24, Google Firebase, or Anthropic (via Ellie) — that provider's own terms and
        policies also apply to your use of it. We are not responsible for the availability,
        content, or conduct of those third-party services, including any interruption to
        publishing or syndication caused by a change on the third party's side.
    </p>

    <h2>6. Availability and support</h2>
    <p>
        We aim to keep CoreX available and reliable, but we do not guarantee uninterrupted or
        error-free operation. Planned maintenance and third-party outages (including the
        integrations in section 5) may affect availability from time to time. See our
        <a href="{{ route('public.support') }}">Support</a> page for how to reach us.
    </p>

    <h2>7. Intellectual property</h2>
    <p>
        CoreX OS — its software, design, and branding — is owned by R R Technologies (Pty) Ltd
        and its licensors. Nothing in these Terms transfers any of that intellectual property to
        you. Your right to use CoreX is limited to the access granted by your agency for the
        purpose of conducting agency business.
    </p>

    <h2>8. Suspension and termination</h2>
    <p>
        We may suspend or terminate access to CoreX where we reasonably believe these Terms have
        been breached, where required by law, or where the agreement between us and your agency
        ends. Your agency administrator may also remove your individual access at any time (see
        section 2). Sections that by their nature should survive termination — including
        intellectual property, liability, and governing law — continue to apply after
        termination.
    </p>

    <h2>9. Liability</h2>
    <p>
        CoreX is provided to support your agency's business processes; it does not replace your
        own professional judgement, legal advice, or regulatory compliance obligations as a
        property practitioner. To the extent permitted by law, we are not liable for indirect or
        consequential loss arising from use of CoreX, including loss of profit, data, or business
        opportunity, except where that loss arises from our gross negligence or wilful
        misconduct.
    </p>

    <h2>10. Changes to these Terms</h2>
    <p>
        We may update these Terms from time to time to reflect changes to CoreX or to legal or
        regulatory requirements. The "last updated" date at the top of this page reflects the
        most recent change. Continued use of CoreX after a change takes effect constitutes
        acceptance of the updated Terms.
    </p>

    <h2>11. Governing law</h2>
    <p>
        These Terms are governed by the laws of the Republic of South Africa. Any dispute arising
        from these Terms or use of CoreX will be subject to the jurisdiction of the South African
        courts.
    </p>

    <h2>12. Contact us</h2>
    <p>
        For any question about these Terms, email
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. See also our
        <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a> and
        <a href="{{ route('public.data-deletion') }}">Data Deletion</a> pages.
    </p>
@endsection
