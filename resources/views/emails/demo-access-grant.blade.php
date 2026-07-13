{{--
    The demo invitation. Spec: .ai/specs/demo-access-control.md §6.1

    Carries the ONLY copy of the plaintext access code that will ever exist outside
    the one-time confirmation screen — the database holds bcrypt(code) alone.

    Sent from PRIMARY's mailer. Never from the demo host, whose mailer is Mailpit.

    Table-based layout with inline styles, no external CSS and no images: this must
    render in Outlook and on a phone without a single request leaving the client.
    Outlook ignores max-width on divs and drops background-image, so the frame is a
    table with bgcolor and the "button" is a padded table cell, not a styled <a>.
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

                {{-- Header — the wordmark is text, never an image: images are blocked by
                     default in Outlook and Gmail, and a broken logo is worse than none. --}}
                <tr>
                    <td bgcolor="#0b1220" style="background: #0b1220; padding: 24px 32px;">
                        <span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #ffffff;">corex</span><span style="font-size: 20px; font-weight: 700; letter-spacing: -0.4px; color: #33c4e0;">&nbsp;os</span>
                        <div style="margin-top: 4px; font-size: 12px; letter-spacing: 1.4px;
                                    text-transform: uppercase; color: #7c8798;">
                            Demo access
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px;">

                        <p style="font-size: 16px; margin: 0 0 16px; line-height: 1.6;">
                            Hi{{ $contactName ? ' ' . $contactName : '' }},
                        </p>

                        <p style="font-size: 15px; margin: 0 0 24px; line-height: 1.65; color: #374151;">
                            Here is your access to the CoreX OS demo. It's a full working system —
                            properties, deals, contacts, documents, compliance — loaded with sample
                            data so you can click through it exactly as an agent would.
                        </p>

                        {{-- Credentials block. Each label/value is its own row so a narrow phone
                             client can't reflow a value up alongside the wrong label. --}}
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
                                    <div style="font-family: 'SF Mono', 'Courier New', Consolas, monospace;
                                                font-size: 22px; font-weight: 700; letter-spacing: 2px;
                                                color: #0b1220; background: #ffffff;
                                                border: 1px solid #dbe1ea; border-radius: 6px;
                                                padding: 12px 14px; margin: 0;">
                                        {{ $accessCode }}
                                    </div>

                                </td>
                            </tr>
                        </table>

                        {{-- Bulletproof-ish button: padding lives on the <td>, so Outlook renders
                             the full block even though it drops padding on inline <a>. --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                               style="margin: 0 0 20px;">
                            <tr>
                                <td bgcolor="#0ea5e9" align="center"
                                    style="background: #0ea5e9; border-radius: 6px;">
                                    <a href="{{ $gateUrl }}"
                                       style="display: inline-block; padding: 14px 30px;
                                              font-size: 15px; font-weight: 600; color: #ffffff;
                                              text-decoration: none;">
                                        Sign in to the demo
                                    </a>
                                </td>
                            </tr>
                        </table>

                        {{-- The URL in plain text too — some clients strip the button's href, and
                             the prospect must always be able to copy the address by hand. --}}
                        <p style="font-size: 13px; margin: 0 0 28px; color: #8a94a6; line-height: 1.5;">
                            Or paste this into your browser:<br>
                            <a href="{{ $gateUrl }}" style="color: #0ea5e9; word-break: break-all;">{{ $gateUrl }}</a>
                        </p>

                        <hr style="border: none; border-top: 1px solid #e9edf3; margin: 0 0 24px;">

                        {{-- The clock starts at first sign-in, not now. Say so — otherwise a prospect who
                             opens this on Friday assumes they have already burned the weekend. --}}
                        <p style="font-size: 14px; margin: 0 0 14px; color: #374151; line-height: 1.6;">
                            Your access runs for
                            <strong>{{ $expiryHours }} {{ \Illuminate\Support\Str::plural('hour', $expiryHours) }}</strong>,
                            counted from the first time you sign in — so there's no rush to start.
                        </p>

                        <p style="font-size: 14px; margin: 0; color: #374151; line-height: 1.6;">
                            A couple of things worth knowing: the demo is a shared sandbox, so you may
                            see changes other people are making, and the data is wiped and rebuilt every
                            three days. Anything you enter there is temporary by design — please don't
                            put real client information into it.
                        </p>

                    </td>
                </tr>

                <tr>
                    <td style="padding: 20px 32px; background: #f7f9fc; border-top: 1px solid #e9edf3;">
                        <p style="font-size: 13px; margin: 0; color: #8a94a6;">
                            — The CoreX OS team
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
