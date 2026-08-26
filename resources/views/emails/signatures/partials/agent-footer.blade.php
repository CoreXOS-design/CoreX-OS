{{-- Professional email signature — table layout for email client compatibility --}}
<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #1a365d;">

    {{-- SECTION 1: Agent details + Company logo --}}
    <table cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #333; width: 100%; max-width: 560px;">
        <tr>
            {{-- Left: Agent photo + details --}}
            <td style="vertical-align: top; padding-right: 20px;">
                <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        @if(!empty($agentFooter['agent_photo_url']))
                        <td style="vertical-align: top; padding-right: 12px;">
                            <img src="{{ $agentFooter['agent_photo_url'] }}" alt="{{ $agentFooter['name'] }}"
                                 width="80" height="80"
                                 style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; display: block;">
                        </td>
                        @endif
                        <td style="vertical-align: top;">
                            <p style="margin: 0 0 2px; font-weight: bold; font-size: 14px; color: #1a365d;">{{ $agentFooter['name'] }}</p>
                            @if(!empty($agentFooter['designation']))
                                <p style="margin: 0 0 6px; font-size: 12px; color: #666; font-style: italic;">{{ $agentFooter['designation'] }}</p>
                            @endif
                            {{-- AT-296 — show the SENDER's own email (outward_email), not the agency admin@ inbox. --}}
                            @if(!empty($agentFooter['email']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">Email: <a href="mailto:{{ $agentFooter['email'] }}" style="color: #1a365d; text-decoration: none;">{{ $agentFooter['email'] }}</a></p>
                            @endif
                            @if(!empty($agentFooter['phone']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">Landline: {{ $agentFooter['phone'] }}</p>
                            @endif
                            @if(!empty($agentFooter['fax']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">Fax: {{ $agentFooter['fax'] }}</p>
                            @endif
                            @if(!empty($agentFooter['cell']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">Cell: {{ $agentFooter['cell'] }}</p>
                            @endif
                            @if(!empty($agentFooter['ffc_number']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">FFC: {{ $agentFooter['ffc_number'] }}</p>
                            @endif
                            @if(!empty($agentFooter['agency_ppra_number']))
                                <p style="margin: 0 0 1px; font-size: 12px; color: #555;">PPRA: {{ $agentFooter['agency_ppra_number'] }}</p>
                            @endif
                            @if(!empty($agentFooter['website']))
                                <p style="margin: 0; font-size: 12px;">
                                    <a href="{{ str_starts_with($agentFooter['website'], 'http') ? $agentFooter['website'] : 'https://' . $agentFooter['website'] }}"
                                       style="color: #1a365d; text-decoration: none;">{{ $agentFooter['website'] }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>

            {{-- Right: Company logo --}}
            @if(!empty($agentFooter['logo_url']))
            <td style="vertical-align: top; text-align: right; width: 140px;">
                <img src="{{ $agentFooter['logo_url'] }}" alt="{{ $agentFooter['agency_name'] ?? 'Home Finders Coastal' }}"
                     width="130"
                     style="width: 130px; height: auto; display: block; margin-left: auto;">
            </td>
            @endif
        </tr>
    </table>

    {{-- SECTION 2: Disclaimer --}}
    @if(!empty($agentFooter['email_disclaimer']))
    <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e0e0e0;">
        <p style="margin: 0 0 4px; font-size: 11px; font-weight: bold; text-decoration: underline; color: #666;">
            {{ $agentFooter['agency_name'] ?? 'Home Finders Coastal' }} email disclaimer.
        </p>
        <p style="margin: 0; font-size: 10px; color: #999; line-height: 1.4;">
            {{ $agentFooter['email_disclaimer'] }}
        </p>
        @if(!empty($agentFooter['popi_url']))
            <p style="margin: 4px 0 0; font-size: 10px; color: #999;">
                Click <a href="{{ $agentFooter['popi_url'] }}" style="color: #1a365d; font-weight: bold; text-decoration: underline;">HERE</a> to read our POPI policy.
            </p>
        @endif
    </div>
    @endif

</div>
