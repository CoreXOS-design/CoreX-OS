@extends('public.legal.layout')

@section('legal-title', 'Data Deletion')

@section('legal-body')
    <p>
        This page explains how to request deletion of data that <strong>CoreX OS</strong>
        (operated by <strong>Home Finders Coastal</strong>) holds about you, including any data
        obtained when you connect a Facebook or Instagram account to CoreX. We provide these
        instructions in line with Meta's Platform requirements and the South African
        <strong>Protection of Personal Information Act (POPIA)</strong>.
    </p>

    <h2>What data the Facebook/Instagram connection stores</h2>
    <p>
        When you connect a Facebook Page or linked Instagram Business account, CoreX stores only:
    </p>
    <ul>
        <li>A Meta-issued <strong>Page access token</strong> (used to publish on your behalf) and its expiry date.</li>
        <li>The connected <strong>Page or Instagram account ID and name</strong>.</li>
        <li>Records of posts you published through CoreX and their performance insights.</li>
    </ul>
    <p>
        CoreX never stores your Facebook password, private messages, or friends list.
    </p>

    <h2>Option 1 — Disconnect inside CoreX (instant)</h2>
    <p>
        The fastest way to remove your social-account data is to disconnect it yourself:
    </p>
    <ol>
        <li>Log in to CoreX.</li>
        <li>Go to <strong>Marketing</strong> and open your connected social accounts.</li>
        <li>Click <strong>Disconnect</strong> next to the Facebook or Instagram account.</li>
    </ol>
    <p>
        Disconnecting immediately removes the stored access token so CoreX can no longer access
        that account. You can also revoke CoreX's access at any time from your Facebook settings
        under <strong>Settings &amp; Privacy → Settings → Business Integrations</strong>.
    </p>

    <h2>Option 2 — Request full deletion by email</h2>
    <p>
        To request deletion of all personal data CoreX holds about you — including account data
        and any data obtained through the Facebook/Instagram integration — email us at
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> with the subject line
        <strong>"Data Deletion Request"</strong> and include:
    </p>
    <ul>
        <li>The name and email address associated with your CoreX account.</li>
        <li>The Facebook Page or Instagram account name, if your request relates to that connection.</li>
    </ul>
    <p>
        We will verify your request, delete the relevant data, and confirm completion by email
        within <strong>30 days</strong>. Some records may be retained for a limited period only
        where the law requires us to keep them.
    </p>

    <h2>Questions</h2>
    <p>
        For any question about this process, contact
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. See also our
        <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a>.
    </p>
@endsection
