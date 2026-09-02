{{--
    Meeting ID + passcode — the block shared by the joining-link, confirmation and
    reminder emails. Spec: .ai/specs/webinar-registration.md §4.4

    ONE PARTIAL, THREE MAILS, ON PURPOSE. These values are the fallback route into a
    webinar for anyone whose browser link misbehaves on the morning, so the three mails
    that carry a joining link must present them identically. Triplicated markup drifts —
    and drift here is not cosmetic, it is one of the three mails quietly losing the line.

    RENDERED EXACTLY AS STORED.
      - {{ }} escapes, it does not transform: the passcode keeps its case ("0ABcMc" is
        not "0abcmc" and is not "0ABCMC") and the Meeting ID keeps its internal spaces.
      - No <a> around either value. The passcode is not a link, and wrapping the Meeting
        ID would invite a client to treat 11 digits as a phone number.
      - Monospace, because a human retypes these into the Zoom app under time pressure
        and a proportional font makes 0/O and l/I the same shape.

    Omitted entirely when neither is set — never an empty label. Each line is guarded
    separately, so a webinar with an ID and no passcode shows one line, not a blank.

    Expects: $joinMeetingId, $joinPasscode (either may be null).
--}}
@php
    $joinMeetingId = trim((string) ($joinMeetingId ?? ''));
    $joinPasscode  = trim((string) ($joinPasscode ?? ''));
@endphp

@if($joinMeetingId !== '' || $joinPasscode !== '')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="background: #f7f9fc; border: 1px solid #e4e8ef;
                  border-radius: 8px; margin: 0 0 24px;">
        <tr>
            <td style="padding: 18px 24px;">

                <div style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
                            text-transform: uppercase; color: #8a94a6; margin: 0 0 10px;">
                    Joining by Meeting ID
                </div>

                @if($joinMeetingId !== '')
                    <div style="font-size: 13px; color: #6b7280; margin: 0 0 2px;">Meeting ID</div>
                    <div style="font-size: 16px; font-weight: 600; color: #111827;
                                font-family: 'SFMono-Regular', Consolas, 'Courier New', monospace;
                                margin: 0 0 {{ $joinPasscode !== '' ? '12px' : '0' }};">{{ $joinMeetingId }}</div>
                @endif

                @if($joinPasscode !== '')
                    <div style="font-size: 13px; color: #6b7280; margin: 0 0 2px;">Passcode</div>
                    <div style="font-size: 16px; font-weight: 600; color: #111827;
                                font-family: 'SFMono-Regular', Consolas, 'Courier New', monospace;
                                margin: 0;">{{ $joinPasscode }}</div>
                @endif

            </td>
        </tr>
    </table>
@endif
