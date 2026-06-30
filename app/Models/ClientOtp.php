<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backwards-compatible alias for the canonical {@see Otp} model (physical
 * table `client_otps`). Retained for the ClientUser->otps() relation and the
 * existing client-auth fixtures/tests.
 *
 * New consumers use App\Models\Otp + App\Services\Otp\OtpService directly.
 * The client-portal login flow now issues/verifies through OtpService too —
 * this class no longer owns any OTP logic (it inherits generation-free
 * persistence + isValid from Otp).
 */
class ClientOtp extends Otp
{
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
