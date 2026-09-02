{{--
    The ONE webinar email. Spec: .ai/specs/webinar-registration.md §6.3

    Carries the only copy of the plaintext access code that will ever exist — the
    database holds bcrypt(code) alone. Also carries the join link and a .ics
    attachment (built in WebinarConfirmationMail::attachments()).

    Sent from PRIMARY over the `corex` mailer. Never from the demo host, whose mailer
    is Mailpit and would swallow it silently.

    Table-based layout with inline styles, no external CSS and no images: this must
    render in Outlook and on a phone without a single request leaving the client.
    Outlook ignores max-width on divs and drops background-image, so the frame is a
    table with bgcolor and every "button" is a padded table cell, not a styled <a>.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="background: #f4f6fa; margin: 0; padding: 24px 12px;">
    <tr>
        <td align="center">

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560"
                   style="width: 560px; max-width: 100%; background: #ffffff; border-radius: 10px;
                          border: 1px solid #e4e8ef; overflow: hidden;
                          font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
                          color: #111827;">

                {{-- Wordmark is text, never an image: images are blocked by default in
                     Outlook and Gmail, and a broken logo is worse than none. --}}
                <tr>
                    <td bgcolor="#0b1220" style="background: #0b1220; padding: 24px 32px;">
                        <span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #ffffff;">corex</span><span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #33c4e0;">&nbsp;os</span>
                        <div style="margin-top: 4px; font-size: 12px; letter-spacing: 1.4px;
                                    text-transform: uppercase; color: #7c8798;">
                            Webinar confirmed
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px;">

                        <p style="font-size: 16px; margin: 0 0 16px; line-height: 1.6;">
                            Hi{{ $contactName ? ' ' . $contactName : '' }},
                        </p>

                        <p style="font-size: 15px; margin: 0 0 24px; line-height: 1.65; color: #374151;">
                            You're registered for <strong>{{ $webinar->title }}</strong>. We've attached a
                            calendar invite so it lands in your diary — and your access to the CoreX OS
                            demo is below, so you can click through the system yourself before we meet.
                        </p>

                        {{-- Webinar details. Label/value on separate rows so a narrow phone
                             client can't reflow a value up alongside the wrong label. --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                               style="background: #f7f9fc; border: 1px solid #e4e8ef;
                                      border-radius: 8px; margin: 0 0 24px;">
                            <tr>
                                <td style="padding: 22px 24px;">

                                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                        When
                                    </div>
                                    <div style="font-size: 15px; color: #111827; margin: 0 0 20px;">
                                        {{ $webinar->starts_at->format('l, j F Y') }}<br>
                                        {{ $webinar->starts_at->format('H:i') }} SAST @if($webinar->duration_minutes) &middot; {{ $webinar->duration_minutes }} minutes @endif
                                    </div>

                                    @if($webinar->description)
                                        <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                    text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                            What we'll cover
                                        </div>
                                        <div style="font-size: 15px; color: #374151; margin: 0; line-height: 1.6;">
                                            {!! nl2br(e($webinar->description)) !!}
                                        </div>
                                    @endif

                                </td>
                            </tr>
                        </table>

                        @if($joinUrl)
                            {{-- Padded table cell, not a styled <a>: Outlook drops padding and
                                 background on inline anchors. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                   style="margin: 0 0 28px;">
                                <tr>
                                    <td bgcolor="#0ea5e9" style="background: #0ea5e9; border-radius: 6px;">
                                        <a href="{{ $joinUrl }}"
                                           style="display: inline-block; padding: 13px 26px; font-size: 15px;
                                                  font-weight: 600; color: #ffffff; text-decoration: none;">
                                            Join the webinar
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 13px; margin: -16px 0 28px; line-height: 1.6; color: #6b7280;
                                      word-break: break-all;">
                                Or paste this into your browser on the day: {{ $joinUrl }}
                            </p>
                        @endif

{{-- Outside the @if($joinUrl) on purpose: the Meeting ID and passcode stand on
                             their own, and a webinar can have them before its link exists. --}}
                        @include('emails.webinars._join-details')

                        <div style="border-top: 1px solid #e4e8ef; margin: 0 0 28px;"></div>

                        <p style="font-size: 15px; margin: 0 0 8px; line-height: 1.6; font-weight: 600;">
                            Your CoreX OS demo access
                        </p>
                        <p style="font-size: 15px; margin: 0 0 20px; line-height: 1.65; color: #374151;">
                            A full working system — properties, deals, contacts, documents, compliance —
                            loaded with sample data so you can click through it exactly as an agent would.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                               style="background: #f7f9fc; border: 1px solid #e4e8ef;
                                      border-radius: 8px; margin: 0 0 24px;">
                            <tr>
                                <td style="padding: 22px 24px;">

                                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                        Email
                                    </div>
                                    <div style="font-size: 15px; color: #111827; margin: 0 0 20px;
                                                word-break: break-all;">
                                        {{ $loginEmail }}
                                    </div>

                                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                        Access code
                                    </div>
                                    <div style="font-size: 20px; font-weight: 700; letter-spacing: 2px;
                                                font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                                                color: #0b1220; margin: 0;">
                                        {{ $accessCode }}
                                    </div>

                                </td>
                            </tr>
                        </table>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                               style="margin: 0 0 24px;">
                            <tr>
                                <td bgcolor="#0b1220" style="background: #0b1220; border-radius: 6px;">
                                    <a href="{{ $gateUrl }}"
                                       style="display: inline-block; padding: 13px 26px; font-size: 15px;
                                              font-weight: 600; color: #ffffff; text-decoration: none;">
                                        Open the demo
                                    </a>
                                </td>
                            </tr>
                        </table>

                        {{-- The deadline, stated plainly. It is absolute and shared by everyone
                             who registered through this link — NOT a clock that starts when you
                             first sign in. Saying so here is the difference between someone
                             planning around it and someone finding out by being locked out. --}}
                        <p style="font-size: 14px; margin: 0 0 8px; line-height: 1.65; color: #374151;">
                            Your demo access runs until
                            <strong>{{ $accessEndsAt->format('j F Y') }}</strong>, end of day.
                            That date is fixed — it applies whether or not you use the login, so
                            there's no rush to sign in and no way to lose time by waiting.
                        </p>

                        <p style="font-size: 13px; margin: 0; line-height: 1.65; color: #6b7280;">
                            Keep this email — the access code can't be looked up or re-sent, so it's the
                            only copy. If you lose it, register again and we'll issue a fresh one.
                        </p>

                    </td>
                </tr>

                <tr>
                    <td bgcolor="#f7f9fc" style="background: #f7f9fc; padding: 20px 32px;
                               border-top: 1px solid #e4e8ef;">
                        <p style="font-size: 12px; margin: 0; line-height: 1.6; color: #8a94a6;">
                            CoreX OS &middot; the operating system for real estate.<br>
                            You're receiving this because you registered for this webinar at corexos.co.za.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
