{{--
    "Your demo access has been extended." Spec: .ai/specs/demo-access-control.md §9.1

    Deliberately carries NO access code and no credential of any kind - the database
    holds bcrypt(code) alone, so there is nothing to send. The prospect signs in with
    the code from their original invitation.

    Same table-based, inline-styled, image-free layout as demo-access-grant.blade.php
    (Outlook / phone safe). Product wording only - no agency name or branding.
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
                            Demo access
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px;">

                        <p style="font-size: 16px; margin: 0 0 16px; line-height: 1.6;">
                            Hi{{ $contactName ? ' ' . $contactName : '' }},
                        </p>

                        <p style="font-size: 15px; margin: 0 0 20px; line-height: 1.65; color: #374151;">
                            Good news - your access to the CoreX OS demo has been extended.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                               style="background: #f7f9fc; border: 1px solid #e4e8ef; border-radius: 8px; margin: 0 0 24px;">
                            <tr>
                                <td style="padding: 18px 24px;">
                                    @if ($endsAt)
                                        <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                    text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                            Your access now runs until
                                        </div>
                                        <div style="font-size: 18px; font-weight: 700; color: #0b1220; margin: 0;">
                                            {{ $endsAt }}
                                        </div>
                                    @else
                                        <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                                                    text-transform: uppercase; color: #8a94a6; margin: 0 0 6px;">
                                            Your trial is now
                                        </div>
                                        <div style="font-size: 18px; font-weight: 700; color: #0b1220; margin: 0 0 6px;">
                                            {{ $trialLength }}
                                        </div>
                                        <div style="font-size: 13px; color: #6b7280; margin: 0;">
                                            counted from the first time you sign in.
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        </table>

                        <p style="font-size: 14px; margin: 0 0 20px; color: #374151; line-height: 1.6;">
                            Sign in with <strong>{{ $loginEmail }}</strong> and the same access code you were
                            originally sent. We do not send the code again, so please use your original invitation email.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px;">
                            <tr>
                                <td bgcolor="#0ea5e9" align="center" style="background: #0ea5e9; border-radius: 6px;">
                                    <a href="{{ $gateUrl }}"
                                       style="display: inline-block; padding: 14px 30px; font-size: 15px;
                                              font-weight: 600; color: #ffffff; text-decoration: none;">
                                        Sign in to the demo
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="font-size: 13px; margin: 0; color: #8a94a6; line-height: 1.5;">
                            Or paste this into your browser:<br>
                            <a href="{{ $gateUrl }}" style="color: #0ea5e9; word-break: break-all;">{{ $gateUrl }}</a>
                        </p>

                    </td>
                </tr>

                <tr>
                    <td style="padding: 20px 32px; background: #f7f9fc; border-top: 1px solid #e9edf3;">
                        <p style="font-size: 13px; margin: 0; color: #8a94a6;">
                            - The CoreX OS team
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
