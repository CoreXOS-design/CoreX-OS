@extends('public.legal.layout')

@section('legal-title', 'Support')

@section('legal-body')
    <p>
        This page covers how to get help with the <strong>CoreX OS</strong> mobile app —
        contacting us, signing in, resetting your password, the device permissions the app
        requests, and fixes for common issues.
    </p>

    <h2>1. Contact us</h2>
    <p>
        For any support question, email
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. We aim to respond within
        <strong>1 business day</strong>.
    </p>
    <h2>2. Getting started &amp; signing in</h2>
    <p>
        CoreX OS is <strong>invite-only</strong>. There is no self-signup — an account is
        created for you by your agency's administrator, who sends you an invitation with your
        login details. If you believe you should have access but have not received an
        invitation, contact your agency administrator or email us at
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <h2>3. Resetting your password</h2>
    <p>
        On the sign-in screen, tap <strong>"Forgot password?"</strong> and enter the email
        address your account was created with. You'll receive an email with a link to set a new
        password. If the email doesn't arrive within a few minutes, check your spam/junk folder,
        confirm you used the same email your administrator invited you with, or contact us at
        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> for a manual reset.
    </p>

    <h2>4. Device permissions the app requests</h2>
    <p>
        CoreX OS asks for the following device permissions. Each is used only for the feature
        described, and each can be reviewed or changed at any time in your device's
        <strong>Settings → CoreX OS</strong> screen.
    </p>
    <ul>
        <li>
            <strong>Microphone</strong> — used by <strong>Ellie</strong>, the in-app voice
            assistant. Tap the microphone button once to grant access. To talk to Ellie, press
            and <strong>hold</strong> the mic button while you speak, then release. If you denied
            microphone access, re-enable it under
            <em>Settings → CoreX OS → Microphone</em> on iOS, or
            <em>Settings → Apps → CoreX OS → Permissions → Microphone</em> on Android.
        </li>
        <li>
            <strong>Camera</strong> — used to take property photos and to scan QR codes (for
            example, when checking in to a viewing). Re-enable under
            <em>Settings → CoreX OS → Camera</em> on iOS, or
            <em>Settings → Apps → CoreX OS → Permissions → Camera</em> on Android.
        </li>
        <li>
            <strong>Photo library</strong> — used to attach existing photos to a property listing
            or document instead of taking a new one. Re-enable under
            <em>Settings → CoreX OS → Photos</em> on iOS, or
            <em>Settings → Apps → CoreX OS → Permissions → Photos and videos</em> on Android.
        </li>
        <li>
            <strong>Notifications</strong> — used to alert you to new leads, messages, and
            deal/task updates. Re-enable under
            <em>Settings → CoreX OS → Notifications</em> on iOS, or
            <em>Settings → Apps → CoreX OS → Notifications</em> on Android.
        </li>
    </ul>

    <h2>5. Troubleshooting</h2>
    <h3>Ellie isn't hearing me</h3>
    <ul>
        <li>Confirm microphone access is granted (see section 4 above).</li>
        <li>Make sure you're pressing and <strong>holding</strong> the mic button while you
            speak — a single tap only opens Ellie, it does not start listening.</li>
        <li>Check your device isn't on silent/muted input and that no other app is currently
            using the microphone.</li>
    </ul>
    <h3>Data looks out of date</h3>
    <ul>
        <li>Pull down on the screen to refresh the current list or view.</li>
        <li>Confirm the device has an active internet connection.</li>
        <li>Sign out and sign back in if the data still doesn't update.</li>
    </ul>
    <h3>Connectivity issues</h3>
    <ul>
        <li>CoreX OS requires an internet connection (Wi-Fi or mobile data) to load and save
            data — it does not work fully offline.</li>
        <li>If the app can't connect, check your connection, then close and reopen the app.</li>
        <li>If the problem continues, email
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> with a description of
            what you were doing and, if possible, a screenshot.</li>
    </ul>

    <h2>6. Legal</h2>
    <p>
        See our <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a> and
        <a href="{{ route('public.data-deletion') }}">Data Deletion</a> pages for how we handle
        your information.
    </p>
@endsection
