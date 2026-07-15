{{--
    Agency plan auto-switch notification → andre@ + johan@corexos.co.za
    Spec: .ai/specs/agency-billing.md §7.5 (AT-11)

    Inline styles are correct here: this is an EMAIL, not a CoreX page. Mail
    clients do not load our stylesheet and most cannot resolve CSS custom
    properties, so the design-token rule (STANDARDS "Design System Compliance")
    does not apply to mail templates. Structure and palette mirror
    emails/agency-onboarding-setup.blade.php so CoreX mail looks like CoreX mail.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $agencyName }} changed plan</title>
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">

                {{-- Branding --}}
                <tr>
                    <td align="center" style="padding-bottom:32px;">
                        <div style="font-size:1.75rem; font-weight:800; letter-spacing:-0.04em; color:#0b2a4a; line-height:1;">
                            CoreX <span style="color:#00b4d8;">Os</span>
                        </div>
                    </td>
                </tr>

                {{-- Card --}}
                <tr>
                    <td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">

                        <div style="display:inline-block; margin:0 0 16px; padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; background:{{ $isUpgrade ? '#ecfdf5' : '#fff7ed' }}; color:{{ $isUpgrade ? '#047857' : '#c2410c' }};">
                            {{ $isUpgrade ? 'Plan upgrade' : 'Plan downgrade' }}
                        </div>

                        <h1 style="margin:0 0 12px; font-size:1.375rem; font-weight:700; color:#111827;">
                            {{ $agencyName }} moved to the {{ $toPlanLabel }} plan
                        </h1>

                        <p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#4b5563;">
                            They now have <strong>{{ $seats }} active {{ \Illuminate\Support\Str::plural('user', $seats) }}</strong>,
                            which moves them {{ $isUpgrade ? 'up' : 'down' }} from <strong>{{ $fromPlanLabel }}</strong>
                            to <strong>{{ $toPlanLabel }}</strong>. This happened automatically — nobody changed it by hand.
                        </p>

                        {{-- Before / after --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px;">
                            <tr>
                                <td style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                                    <div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">Was</div>
                                    <div style="font-size:1rem; color:#111827;">
                                        {{ $fromPlanLabel }} — <strong>{{ $previousMonthly }}</strong>/month
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 20px;">
                                    <div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">Now</div>
                                    <div style="font-size:1rem; color:#111827;">
                                        {{ $toPlanLabel }} — <strong>{{ $newMonthly }}</strong>/month
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 28px; font-size:0.8125rem; line-height:1.6; color:#6b7280;">
                            These are <strong>list prices</strong> for the new headcount. If this agency is on a custom
                            amount or a discount, what they actually pay is unchanged by the plan switch — open the
                            billing page to see the real figure.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:8px; background:#0b2a4a;">
                                    <a href="{{ $billingUrl }}"
                                       style="display:inline-block; padding:12px 24px; font-size:0.9375rem; font-weight:600; color:#ffffff; text-decoration:none;">
                                        Open Agency Billing
                                    </a>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:24px 8px 0; font-size:0.75rem; line-height:1.6; color:#9ca3af;">
                        Switched {{ $switchedAt->format('D j M Y, H:i') }} · Agency #{{ $agencyId }}<br>
                        You are receiving this because you are listed in
                        <code style="color:#6b7280;">corex-billing.notify.plan_change_recipients</code>.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
