<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\ClientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

/**
 * Mobile Client Portal — passwordless activation + password sign-in.
 *
 * Spec: .ai/specs/client-auth.md
 */
class ClientAuthController extends Controller
{
    public function __construct(private readonly ClientAuthService $service) {}

    /**
     * POST /api/v1/client-auth/lookup
     * Returns whether the email exists, what auth path is required, and
     * the matching agencies (without revealing the actual contact data).
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);

        $key = 'clientauth.lookup:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Too many requests. Please slow down.'], 429);
        }
        RateLimiter::hit($key, 60);

        $email   = strtolower(trim($data['email']));
        $matches = $this->service->findContactsByIdentifierAcrossAgencies($email);

        $clientUser = ClientUser::where('email', $email)->first();

        $hasAnyMatch = $clientUser !== null || count($matches['agencies']) > 0;

        if (!$hasAnyMatch) {
            $this->service->recordAttempt($email, false, 0, $request);

            return response()->json([
                'exists'           => false,
                'requires_otp'     => false,
                'requires_password'=> false,
                'message'          => 'You are not on any agency contact list. Ask your agent to add you.',
                'agencies'         => [],
            ]);
        }

        $this->service->recordAttempt($email, true, count($matches['agencies']), $request);

        $requiresPassword = $clientUser && $clientUser->hasPassword();

        return response()->json([
            'exists'             => true,
            'requires_password'  => $requiresPassword,
            'requires_otp'       => !$requiresPassword,
            'must_change_password' => (bool) ($clientUser?->password_must_change),
            'agencies'           => $matches['agencies'],
        ]);
    }

    /**
     * POST /api/v1/client-auth/otp/send
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'   => 'required|email|max:255',
            'purpose' => 'sometimes|in:activation,recovery',
        ]);

        $email   = strtolower(trim($data['email']));
        $purpose = $data['purpose'] ?? 'activation';

        $cooldownKey = 'clientauth.otp.cooldown:' . $email;
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }
        RateLimiter::hit($cooldownKey, (int) config('clientauth.otp.resend_cooldown_secs', 60));

        $hourlyKey = 'clientauth.otp.hourly:' . $email;
        if (RateLimiter::tooManyAttempts($hourlyKey, (int) config('clientauth.otp.hourly_limit_per_email', 5))) {
            return response()->json(['message' => 'Too many codes requested. Try again later.'], 429);
        }
        RateLimiter::hit($hourlyKey, 3600);

        // Don't issue OTP if there's no matching contact AND no client user — but
        // also don't reveal that fact (consistent timing).
        $matches    = $this->service->findContactsByIdentifierAcrossAgencies($email);
        $clientUser = ClientUser::where('email', $email)->first();
        $hasMatch   = $clientUser !== null || count($matches['agencies']) > 0;

        if ($hasMatch) {
            // Forgot-password on a fake-domain email → no OTP, friendly error.
            $fakeDomain = config('clientauth.fake_email_domain', 'corexclient.co.za');
            if ($purpose === 'recovery' && str_ends_with($email, '@' . $fakeDomain)) {
                return response()->json([
                    'sent' => false,
                    'message' => 'This account uses an agent-managed login. Please ask your agent to reset your password.',
                ], 422);
            }

            $this->service->issueOtp($email, $purpose, $request);
        }

        return response()->json([
            'sent'           => true,
            'expires_in_min' => (int) config('clientauth.otp.expires_minutes', 10),
        ]);
    }

    /**
     * POST /api/v1/client-auth/otp/verify
     * Returns a short-lived activation token used to call /password/set.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'   => 'required|email|max:255',
            'code'    => 'required|digits:6',
            'purpose' => 'sometimes|in:activation,recovery',
        ]);

        $email   = strtolower(trim($data['email']));
        $purpose = $data['purpose'] ?? 'activation';

        $otp = $this->service->verifyOtp($email, $data['code'], $purpose, $request);

        if (!$otp) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $clientUser = $this->service->findOrCreateClientUser($email);
        if (!$clientUser->activated_at) {
            $clientUser->forceFill(['activated_at' => now()])->save();
        }

        $this->service->log($clientUser, null, null, 'otp_verified', $request, ['purpose' => $purpose]);

        return response()->json([
            'activation_token' => $this->service->issueActivationToken($clientUser),
            'email'            => $clientUser->email,
            'expires_in_min'   => (int) config('clientauth.activation_token_minutes', 15),
        ]);
    }

    /**
     * POST /api/v1/client-auth/password/set
     * Auth: activation token (ability 'client-activation') OR an authenticated
     * client whose password_must_change=true.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password'              => ['required', 'confirmed', Password::min(8)],
            'device_name'           => 'sometimes|string|max:120',
        ]);

        $tokenable = $request->user();
        if (!$tokenable instanceof ClientUser) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $token = $tokenable->currentAccessToken();
        $isActivation = $token && method_exists($token, 'can') && $token->can('client-activation');
        $isMustChange = $token && method_exists($token, 'can') && $token->can('client') && $tokenable->password_must_change;

        if (!$isActivation && !$isMustChange) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tokenable->forceFill([
            'password'             => Hash::make($data['password']),
            'password_set_at'      => now(),
            'password_must_change' => false,
        ])->save();

        $event = $isActivation ? 'password_set' : 'password_changed';
        $this->service->log($tokenable, null, null, $event, $request);

        // Revoke the activation token (single-use).
        if ($isActivation && $token) {
            $token->delete();
        }

        // Auto-issue a long-lived sanctum token so client lands signed-in.
        $deviceName = $data['device_name'] ?? config('clientauth.token.name_default', 'CoreX Client App');
        $sessionToken = $this->service->issueSanctumToken($tokenable, $deviceName);

        if (!$tokenable->first_login_at) {
            $tokenable->forceFill(['first_login_at' => now()])->save();
        }
        $tokenable->forceFill(['last_login_at' => now(), 'last_ip' => $request->ip()])->save();

        return response()->json([
            'token'    => $sessionToken,
            'agencies' => $this->service->agenciesFor($tokenable),
            'client'   => $this->summarise($tokenable),
        ]);
    }

    /**
     * POST /api/v1/client-auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => 'required|email|max:255',
            'password'    => 'required|string',
            'device_name' => 'sometimes|string|max:120',
            'agency_id'   => 'sometimes|integer',
        ]);

        $key = 'clientauth.login:' . $request->ip() . ':' . strtolower($data['email']);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Too many attempts. Please try again later.'], 429);
        }

        $email      = strtolower(trim($data['email']));
        $clientUser = ClientUser::where('email', $email)->first();

        if (!$clientUser || !$clientUser->password || !Hash::check($data['password'], $clientUser->password)) {
            RateLimiter::hit($key, 300);
            if ($clientUser) {
                $this->service->log($clientUser, null, null, 'password_login_failed', $request);
            }
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        RateLimiter::clear($key);

        // Lazy-link any new contacts that have appeared since.
        $this->service->findOrCreateClientUser($email);

        $deviceName = $data['device_name'] ?? config('clientauth.token.name_default', 'CoreX Client App');
        $token = $this->service->issueSanctumToken($clientUser, $deviceName);

        $agencies = $this->service->agenciesFor($clientUser);

        // Pick agency: locked > requested > only-one > null (let app show picker)
        $agencyId = $clientUser->locked_to_agency_id;
        if (!$agencyId && !empty($data['agency_id'])) {
            $valid = collect($agencies)->pluck('id')->all();
            if (in_array((int) $data['agency_id'], $valid, true)) {
                $agencyId = (int) $data['agency_id'];
            }
        }
        if (!$agencyId && count($agencies) === 1) {
            $agencyId = $agencies[0]['id'];
        }

        if ($agencyId) {
            $clientUser->forceFill(['current_agency_id' => $agencyId])->save();
        }

        if (!$clientUser->first_login_at) {
            $clientUser->forceFill(['first_login_at' => now()])->save();
        }
        $clientUser->forceFill(['last_login_at' => now(), 'last_ip' => $request->ip()])->save();

        $this->service->log($clientUser, $agencyId, null, 'password_login_success', $request, [], $deviceName);

        return response()->json([
            'token'    => $token,
            'agencies' => $agencies,
            'client'   => $this->summarise($clientUser),
            'must_change_password' => $clientUser->password_must_change,
        ]);
    }

    /**
     * POST /api/v1/client-auth/agency/select
     */
    public function selectAgency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => 'required|integer',
            'lock'      => 'sometimes|boolean',
            'favourite' => 'sometimes|boolean',
        ]);

        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        $valid = collect($this->service->agenciesFor($clientUser))->pluck('id')->all();
        if (!in_array((int) $data['agency_id'], $valid, true)) {
            return response()->json(['message' => 'Agency not available for this client.'], 422);
        }

        $patch = ['current_agency_id' => (int) $data['agency_id']];
        if (!empty($data['favourite'])) {
            $patch['preferred_agency_id'] = (int) $data['agency_id'];
        }
        if (!empty($data['lock'])) {
            $patch['locked_to_agency_id'] = (int) $data['agency_id'];
        }
        $clientUser->forceFill($patch)->save();

        $event = !empty($data['lock']) ? 'agency_locked' : 'agency_selected';
        $this->service->log($clientUser, (int) $data['agency_id'], null, $event, $request, [
            'favourite' => (bool) ($data['favourite'] ?? false),
        ]);

        return response()->json([
            'client'   => $this->summarise($clientUser),
            'agencies' => $this->service->agenciesFor($clientUser),
        ]);
    }

    /**
     * POST /api/v1/client-auth/password/change (auth)
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required_unless:must_change,1|string',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        if (!$clientUser->password_must_change) {
            if (!Hash::check($data['current_password'] ?? '', $clientUser->password ?? '')) {
                return response()->json(['message' => 'Current password is incorrect.'], 422);
            }
        }

        $clientUser->forceFill([
            'password'             => Hash::make($data['password']),
            'password_set_at'      => now(),
            'password_must_change' => false,
        ])->save();

        $this->service->log($clientUser, $clientUser->current_agency_id, null, 'password_changed', $request);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/client-auth/password/forgot (no auth)
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($data['email']));

        $key = 'clientauth.forgot:' . $email;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);

        $fakeDomain = config('clientauth.fake_email_domain', 'corexclient.co.za');
        if (str_ends_with($email, '@' . $fakeDomain)) {
            return response()->json([
                'sent'    => false,
                'message' => 'This account uses an agent-managed login. Please ask your agent to reset your password.',
            ], 422);
        }

        $clientUser = ClientUser::where('email', $email)->first();
        if ($clientUser) {
            $this->service->issueOtp($email, 'recovery', $request);
        }

        return response()->json([
            'sent'           => true,
            'expires_in_min' => (int) config('clientauth.otp.expires_minutes', 10),
        ]);
    }

    /**
     * POST /api/v1/client-auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $token = $clientUser->currentAccessToken();

        $this->service->log($clientUser, $clientUser->current_agency_id, null, 'logout', $request, [
            'token_id' => $token?->id ?? null,
        ]);

        // Delete by id where possible (currentAccessToken may return a TransientToken under
        // stateful Sanctum, which has no real DB row and a no-op delete()).
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        } else {
            // Defensive fallback: nuke every token for this client user.
            $clientUser->tokens()->delete();
        }

        // Stateful Sanctum may have established a session cookie; clear it so a
        // follow-up request with the same cookie can't authenticate as this user.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    private function summarise(ClientUser $clientUser): array
    {
        return [
            'id'                    => $clientUser->id,
            'email'                 => $clientUser->email,
            'has_password'          => $clientUser->hasPassword(),
            'password_must_change'  => (bool) $clientUser->password_must_change,
            'preferred_agency_id'   => $clientUser->preferred_agency_id,
            'locked_to_agency_id'   => $clientUser->locked_to_agency_id,
            'current_agency_id'     => $clientUser->current_agency_id,
        ];
    }
}
