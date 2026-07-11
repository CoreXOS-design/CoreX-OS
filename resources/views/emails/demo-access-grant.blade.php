{{--
    The demo invitation. Spec: .ai/specs/demo-access-control.md §6.1

    Carries the ONLY copy of the plaintext access code that will ever exist outside
    the one-time confirmation screen — the database holds bcrypt(code) alone.

    Sent from PRIMARY's mailer. Never from the demo host, whose mailer is Mailpit.

    Plain inline styles, no external CSS, no images: this must render in Outlook and
    on a phone without a single request leaving the client.
--}}
<div style="font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            max-width: 560px; margin: 0 auto; padding: 24px;
            color: #111827; line-height: 1.6;">

    <p style="font-size: 15px; margin: 0 0 16px;">
        Hi{{ $contactName ? ' ' . $contactName : '' }},
    </p>

    <p style="font-size: 15px; margin: 0 0 16px;">
        Here is your access to the CoreX OS demo. It's a full working system — properties,
        deals, contacts, documents, compliance — loaded with sample data so you can click
        through it exactly as an agent would.
    </p>

    <div style="margin: 24px 0; padding: 20px; border-radius: 6px;
                background: #f0f2f8; border: 1px solid #e5e7eb;">
        <p style="margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #6b7280;">
            SIGN IN AT
        </p>
        <p style="margin: 0 0 16px; font-size: 15px;">
            <a href="{{ $demoUrl }}" style="color: #0ea5e9; font-weight: 600;">{{ $demoUrl }}</a>
        </p>

        <p style="margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #6b7280;">
            EMAIL
        </p>
        <p style="margin: 0 0 16px; font-size: 15px;">{{ $loginEmail }}</p>

        <p style="margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #6b7280;">
            ACCESS CODE
        </p>
        <p style="margin: 0; font-family: 'Courier New', monospace; font-size: 20px;
                  font-weight: bold; letter-spacing: 2px;">{{ $accessCode }}</p>
    </div>

    {{-- The clock starts at first sign-in, not now. Say so — otherwise a prospect who
         opens this on Friday assumes they have already burned the weekend. --}}
    <p style="font-size: 14px; margin: 0 0 16px; color: #374151;">
        Your access runs for <strong>{{ $expiryHours }} hours</strong>, counted from the first
        time you sign in — so there's no rush to start.
    </p>

    <p style="font-size: 14px; margin: 0 0 16px; color: #374151;">
        A couple of things worth knowing: the demo is a shared sandbox, so you may see changes
        other people are making, and the data is wiped and rebuilt every three days. Anything
        you enter there is temporary by design — please don't put real client information into it.
    </p>

    <p style="font-size: 14px; margin: 0 0 24px; color: #374151;">
        If the code doesn't work or your access has run out, just reply to this email and
        we'll send you a new one.
    </p>

    <p style="font-size: 14px; margin: 0; color: #6b7280;">
        — The CoreX OS team
    </p>
</div>
