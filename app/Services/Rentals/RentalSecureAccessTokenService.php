<?php

namespace App\Services\Rentals;

use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\RentalPortalSetting;
use App\Models\User;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. Mint/revoke the
 * contractor's no-login secure link. Only a hash is ever persisted or
 * returned from here as a "stored" value — the raw token is generated
 * here and handed straight back to the caller (the agent's own request),
 * never logged, never re-derivable from the database.
 */
class RentalSecureAccessTokenService
{
    /** Revokes any existing live token for this work order first — at most one active link at a time. */
    public function issueFor(RentalWorkOrder $workOrder, User $createdBy): array
    {
        $this->revokeAllFor($workOrder);

        $rawToken = RentalSecureAccessToken::generateRawToken();
        $expiryDays = RentalPortalSetting::contractorSecureLinkExpiryDaysFor($workOrder->agency_id);

        $token = RentalSecureAccessToken::create([
            'agency_id' => $workOrder->agency_id,
            'rental_work_order_id' => $workOrder->id,
            'agency_service_provider_id' => $workOrder->agency_service_provider_id,
            'token_hash' => RentalSecureAccessToken::hashToken($rawToken),
            'expires_at' => now()->addDays($expiryDays),
            'created_by_user_id' => $createdBy->id,
        ]);

        return ['token' => $token, 'raw_token' => $rawToken];
    }

    public function revokeAllFor(RentalWorkOrder $workOrder): void
    {
        RentalSecureAccessToken::withoutGlobalScopes()
            ->where('rental_work_order_id', $workOrder->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revoke(RentalSecureAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
