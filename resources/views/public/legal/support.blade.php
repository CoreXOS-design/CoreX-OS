@extends('public.legal.layout')

@section('legal-title', 'CoreX OS Support')

@section('legal-body')
    <p>
        Welcome to support for <strong>CoreX OS</strong>, the real-estate operating system
        operated by <strong>Home Finders Coastal</strong>. This page is for agents, agencies
        and their clients using the CoreX OS mobile app or web dashboard.
    </p>

    <h2>Contact us</h2>
    <p>
        The fastest way to reach us is by email. We aim to reply within one business day
        (Monday to Friday, 08:00–17:00 SAST).
    </p>
    <ul>
        <li><strong>Email</strong> — <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></li>
        <li><strong>Postal address</strong> — Home Finders Coastal, KwaZulu-Natal South Coast, South Africa</li>
    </ul>
    <p>
        When reporting a problem, please include your account email, the device you are using
        (for example "iPad Air, iPadOS 26"), and the app version shown at the bottom of the
        Settings screen. That lets us reproduce the issue much faster.
    </p>

    <h2>Getting started</h2>
    <h3>Signing in</h3>
    <p>
        CoreX OS requires a valid account — it is not a self-signup product. Your agency
        administrator creates your account and you receive an invitation by email. If you have
        not received one, contact your agency administrator or email us at the address above.
    </p>
    <p>
        Once signed in you can enable fingerprint sign-in from the Settings screen for faster
        access on subsequent launches.
    </p>

    <h3>Resetting your password</h3>
    <p>
        Use the "Forgot password" link on the sign-in screen. A reset link is sent to your
        registered email address. If the email does not arrive within a few minutes, check your
        spam folder before contacting us.
    </p>

    <h2>Permissions the app asks for</h2>
    <p>
        CoreX OS only requests the permissions it needs, and only at the point you use the
        related feature. You can change any of these at any time in your device settings under
        <strong>Settings → CoreX OS</strong>.
    </p>
    <ul>
        <li>
            <strong>Microphone</strong> — used by Ellie, the in-app voice assistant, so you can
            hold the microphone button and speak a command such as booking a viewing. Tap the
            microphone button once to grant access, then press and hold to talk. If you
            previously declined, enable <strong>Microphone</strong> for CoreX OS in your device
            settings and return to the app.
        </li>
        <li>
            <strong>Camera</strong> — used to photograph properties and to scan QR codes.
            Requested the first time you open the camera or scanner.
        </li>
        <li>
            <strong>Photo library</strong> — used to attach existing images to a listing and to
            save your agent QR code to your photos.
        </li>
        <li>
            <strong>Notifications</strong> — used for reminders about appointments, tasks and
            new leads. Optional; the app works without them.
        </li>
    </ul>

    <h2>Common questions</h2>
    <h3>Ellie does not hear me</h3>
    <p>
        Make sure microphone access is enabled for CoreX OS in your device settings, that no
        other app is currently using the microphone, and that you press and <em>hold</em> the
        microphone button while speaking — releasing immediately discards the clip as an
        accidental tap.
    </p>

    <h3>My data is not up to date</h3>
    <p>
        Most screens support pull-to-refresh. If data still looks stale, check your internet
        connection and sign out and back in. Contact us if the problem persists.
    </p>

    <h3>Deleting your account or data</h3>
    <p>
        You can request deletion of your account and associated personal information at any
        time. See our <a href="{{ route('public.data-deletion') }}">Data Deletion</a> page, or
        email <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>

    <h2>Privacy</h2>
    <p>
        For details on what we collect and how it is protected under the South African
        Protection of Personal Information Act (POPIA), see our
        <a href="{{ route('public.platform-privacy') }}">Privacy Policy</a>.
    </p>
@endsection
