<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    @php
        $headerColor = match(true) {
            $daysRemaining <= 0  => '#9b2c2c',
            $daysRemaining <= 30 => '#c05621',
            $daysRemaining <= 60 => '#d69e2e',
            default              => '#1a365d',
        };
        $urgency = match(true) {
            $daysRemaining <= 0  => 'EXPIRED',
            $daysRemaining <= 30 => 'URGENT',
            $daysRemaining <= 60 => 'WARNING',
            default              => 'NOTICE',
        };
    @endphp

    <div style="background-color: {{ $headerColor }}; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Lease {{ $urgency }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $agentName }},</p>

        @if($daysRemaining <= 0)
            <p>The lease for the property below has <strong>expired</strong>.</p>
        @else
            <p>The following lease expires in <strong>{{ $daysRemaining }} days</strong>:</p>
        @endif

        <div style="background-color: #f7fafc; border-left: 4px solid {{ $headerColor }}; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Property:</strong> {{ $propertyAddress }}</p>
            <p style="margin: 5px 0 0;"><strong>Tenant:</strong> {{ $tenantName }}</p>
            <p style="margin: 5px 0 0;"><strong>Lease ends:</strong> {{ $leaseEndDate->format('d M Y') }}</p>
        </div>

        <p>Please contact the tenant and landlord to arrange renewal or handover.</p>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <p style="margin: 0; color: #999; font-size: 12px;">Home Finders Coastal — Lease Management</p>
        </div>
    </div>

</body>
</html>
