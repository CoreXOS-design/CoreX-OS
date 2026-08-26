{{--
    The single pre-webinar reminder. Spec: .ai/specs/webinar-registration.md §6.4

    NO ACCESS CODE. By the time this sends, the plaintext no longer exists anywhere in
    CoreX — the database holds bcrypt(code) alone. It points back at the confirmation
    email instead of promising a credential it cannot produce.

    Same table-based, image-free construction as the confirmation email, for the same
    reason: it has to render in Outlook and on a phone with nothing loading remotely.
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

                <tr>
                    <td bgcolor="#0b1220" style="background: #0b1220; padding: 24px 32px;">
                        <span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #ffffff;">corex</span><span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #33c4e0;">&nbsp;os</span>
                        <div style="margin-top: 4px; font-size: 12px; letter-spacing: 1.4px;
                                    text-transform: uppercase; color: #7c8798;">
                            Webinar reminder
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px;">

                        <p style="font-size: 16px; margin: 0 0 16px; line-height: 1.6;">
                            Hi{{ $contactName ? ' ' . $contactName : '' }},
                        </p>

                        <p style="font-size: 15px; margin: 0 0 24px; line-height: 1.65; color: #374151;">
                            A quick reminder that <strong>{{ $webinar->title }}</strong> is coming up.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                               style="background: #f7f9fc; border: 1px solid #e4e8ef;
                                      border-radius: 8px; margin: 0 0 24px;">
                            <tr>
                                <td style="padding: 22px 24px;">
                                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                        When
                                    </div>
                                    <div style="font-size: 15px; color: #111827; margin: 0;">
                                        {{ $webinar->starts_at->format('l, j F Y') }}<br>
                                        {{ $webinar->starts_at->format('H:i') }} SAST @if($webinar->duration_minutes) &middot; {{ $webinar->duration_minutes }} minutes @endif
                                    </div>
                                </td>
                            </tr>
                        </table>

                        @if($joinUrl)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                   style="margin: 0 0 24px;">
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

                            <p style="font-size: 13px; margin: -12px 0 24px; line-height: 1.6; color: #6b7280;
                                      word-break: break-all;">
                                Or paste this into your browser: {{ $joinUrl }}
                            </p>
                        @endif

                        <p style="font-size: 14px; margin: 0 0 8px; line-height: 1.65; color: #374151;">
                            Want a look around the demo beforehand? Use the email address and access code
                            in your original confirmation email — your access runs until
                            <strong>{{ $accessEndsAt->format('j F Y') }}</strong>, end of day.
                        </p>

                        <p style="font-size: 13px; margin: 0; line-height: 1.65; color: #6b7280;">
                            Can't find that email? Register again on the webinar page and we'll issue a
                            fresh code — for security we can't look up or re-send the original.
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
