<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Services\ClientAuthService;
use App\Services\PortalTabSession;
use App\Support\PortalDemoSigninGate;
use App\Support\PortalLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private readonly ClientAuthService $service, private readonly PortalTabSession $tabs) {}

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

        // AT-130: issuance throttle now lives in the canonical OtpService
        // (resend cooldown + hourly cap). Called BEFORE the match check so the
        // counter ticks regardless of whether the email matches — same
        // enumeration-resistance as before. Bucket 'client_login' is shared
        // across activation + recovery (matches the prior per-email keying).
        $throttle = app(\App\Services\Otp\OtpService::class)->throttle('client_login', $email);
        if ($throttle === 'cooldown') {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }
        if ($throttle === 'hourly') {
            return response()->json(['message' => 'Too many codes requested. Try again later.'], 429);
        }

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
            'tab'                   => 'sometimes|boolean',
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

        // AT-445 — a stateful (same-origin browser) request establishes a
        // session instead of receiving a bearer token: the web portal never
        // sees a token value at all, so there is nothing for an XSS to
        // steal. Mobile (never stateful) is completely unchanged.
        $sessionToken = null;
        if ($this->wantsTabToken($request)) {
            // §28.5 - a web portal TAB signs in as a token of its own: the browser's shared session cookie is never touched, so another tab
            // (another person) is not signed out.
            $sessionToken = $this->tabs->issue($tokenable);
            $this->tabs->remember($request, $tokenable);
        } elseif ($this->isStatefulRequest($request)) {
            // a staff page open in this browser keeps its CSRF token (StaffSafeSession)
            \App\Support\StaffSafeSession::loginPortal($request, Auth::guard('client-web'), $tokenable);
        } else {
            $deviceName = $data['device_name'] ?? config('clientauth.token.name_default', 'CoreX Client App');
            $sessionToken = $this->service->issueSanctumToken($tokenable, $deviceName);
        }

        if (!$tokenable->first_login_at) {
            $tokenable->forceFill(['first_login_at' => now()])->save();
        }
        $tokenable->forceFill(['last_login_at' => now(), 'last_ip' => $request->ip()])->save();

        // Mirror login()'s agency auto-selection: unlike /login, this endpoint is the
        // ONLY step in Flow A (fresh OTP activation) that runs after the ClientUser
        // exists, so if it doesn't set current_agency_id here, a single-agency client
        // lands signed-in with NO agency selected — /client/me then returns contact:
        // null (gated on current_agency_id), which cascades into no agent shown and
        // every agency-scoped endpoint (matches, seller-listings) 409ing "Select an
        // agency first" until the app happens to call /agency/select on its own.
        // Bug found 2026-08-13: the mobile app's Screen 3 does not call
        // /agency/select for the single-agency case (see MOBILE-PROMPT), so this was
        // silently broken for every brand-new activation. Locked agency still wins.
        $agencies = $this->service->agenciesFor($tokenable);
        $agencyId = $tokenable->locked_to_agency_id;
        if (!$agencyId && count($agencies) === 1) {
            $agencyId = $agencies[0]['id'];
        }
        if ($agencyId && $tokenable->current_agency_id !== $agencyId) {
            $tokenable->forceFill(['current_agency_id' => $agencyId])->save();
        }

        $payload = ['agencies' => $agencies, 'client' => $this->summarise($tokenable)];
        if ($sessionToken !== null) {
            $payload['token'] = $sessionToken;
        }

        return response()->json($payload);
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
            'tab'         => 'sometimes|boolean',
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

        // AT-445 — same stateful/session branch as setPassword() above.
        $stateful = $this->isStatefulRequest($request);
        $token = null;
        $deviceName = 'Web portal (session)';
        if ($this->wantsTabToken($request)) {
            // §28.5 - this tab's own login (see setPassword)
            $deviceName = 'Web portal (tab)';
            $token = $this->tabs->issue($clientUser);
            $this->tabs->remember($request, $clientUser);
        } elseif ($stateful) {
            \App\Support\StaffSafeSession::loginPortal($request, Auth::guard('client-web'), $clientUser);
        } else {
            $deviceName = $data['device_name'] ?? config('clientauth.token.name_default', 'CoreX Client App');
            $token = $this->service->issueSanctumToken($clientUser, $deviceName);
        }

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

        $payload = [
            'agencies' => $agencies,
            'client'   => $this->summarise($clientUser),
            'must_change_password' => $clientUser->password_must_change,
        ];
        if ($token !== null) {
            $payload['token'] = $token;
        }

        return response()->json($payload);
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
     * DELETE /api/v1/client-auth/account
     *
     * Apple 5.1.1(v) account-deletion requirement — a self-service, in-app,
     * non-reversible-to-the-client path off the login. The client's Contact
     * record(s) are NEVER deleted (they are agency business/compliance
     * records — the pillar data). Only the LOGIN identity is removed: every
     * linked Contact is unlinked, every Sanctum token is revoked, the
     * password is wiped, and the ClientUser itself is soft-deleted (never a
     * hard delete — non-negotiable #1). Once this runs, /lookup and /login
     * behave exactly as if this email had never signed up — the client can
     * always start over from Screen 1.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => 'required_unless:must_change,1|string',
        ]);

        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        if ($clientUser->hasPassword() && !$clientUser->password_must_change) {
            if (!Hash::check($data['password'] ?? '', $clientUser->password)) {
                return response()->json(['message' => 'Password is incorrect.'], 422);
            }
        }

        $contacts = $this->service->allContactsFor($clientUser);

        if ($contacts->isEmpty()) {
            $this->service->log($clientUser, $clientUser->current_agency_id, null, 'account_deleted', $request);
        } else {
            foreach ($contacts as $contact) {
                $this->service->log($clientUser, $contact->agency_id, $contact->id, 'account_deleted', $request);
                $contact->forceFill(['client_user_id' => null])->saveQuietly();
            }
        }

        $clientUser->tokens()->delete();

        $clientUser->forceFill([
            'password'             => null,
            'password_must_change' => true,
            'preferred_agency_id'  => null,
            'locked_to_agency_id'  => null,
            'current_agency_id'    => null,
        ])->save();

        $clientUser->delete();

        $this->endPortalSession($request);

        return response()->json(['ok' => true, 'message' => 'Your account has been deleted.']);
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

        // §28.5 - a portal TAB signing out ends that tab's own login only: the token goes, this person is no longer remembered for NEW tabs on
        // this browser, and the browser's session cookie (which may hold somebody else, or a staff user) is left alone. Other tabs keep working.
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->name === PortalTabSession::tokenName()) {
            $token->delete();
            $this->tabs->forget($request, $clientUser);

            return response()->json(['ok' => true]);
        }

        // Delete by id where possible (currentAccessToken may return a TransientToken under
        // stateful Sanctum, which has no real DB row and a no-op delete()).
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        } else {
            // Defensive fallback: every token of this client user EXCEPT the ones other portal tabs are using right now.
            $clientUser->tokens()->where('name', '!=', PortalTabSession::tokenName())->delete();
        }

        // Stateful Sanctum may have established a session cookie; clear it so a
        // follow-up request with the same cookie can't authenticate as this user.
        $this->endPortalSession($request);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/client-auth/tab-token  (§28.5)
     * A portal tab with no token of its own asks for one. It gets one only for a person who has ALREADY signed in on this browser
     * (the encrypted "logins remembered here" cookie, or a portal session cookie from before per-tab sign-in): the link's person when the
     * link names one, otherwise the most recent. Anyone else must sign in.
     */
    public function tabToken(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'nullable|email|max:255', 'contact_id' => 'nullable|integer|min:1']);

        $pool = $this->tabs->rememberedIds($request);
        $legacy = $request->hasSession() ? Auth::guard('client-web')->user() : null;
        if ($legacy instanceof ClientUser && ! in_array((int) $legacy->id, $pool, true)) {
            $pool[] = (int) $legacy->id;
        }

        $named = ! empty($data['contact_id']) || ! empty($data['email']);
        $target = null;
        if (! empty($data['contact_id'])) {
            $target = Contact::withoutGlobalScopes()->whereNull('deleted_at')->whereKey((int) $data['contact_id'])->value('client_user_id');
        }
        if (! $target && ! empty($data['email'])) {
            $target = ClientUser::where('email', strtolower(trim($data['email'])))->value('id');
        }

        $id = $named ? ($target && in_array((int) $target, $pool, true) ? (int) $target : null) : (end($pool) ?: null);
        $login = $id ? ClientUser::find($id) : null;
        if (! $login) {
            return response()->json(['message' => 'Sign in to continue.', 'needs_signin' => true], 401);
        }

        $token = $this->tabs->issue($login);
        $this->tabs->remember($request, $login);

        return response()->json(['token' => $token, 'client' => $this->summarise($login)]);
    }

    /**
     * POST /api/v1/client-auth/qa-link-signin  (QA1 ONLY - the route exists only when PortalDemoSigninGate::open(); 404 everywhere else)
     * An emailed portal link signs its tab straight in as the person the link was written for: no emailed code, no password. The link's
     * signed recipient reference is the only thing trusted - an unsigned or edited link signs nobody in.
     */
    public function qaLinkSignin(Request $request): JsonResponse
    {
        abort_unless(PortalDemoSigninGate::open(), 404);

        $data = $request->validate(['r' => 'required|string|max:600']);
        $ref = PortalLink::parse($data['r']);
        if (! $ref) {
            return response()->json(['message' => 'This link could not be read.'], 422);
        }

        $contact = $ref['contact_id'] > 0 ? Contact::withoutGlobalScopes()->whereNull('deleted_at')->find($ref['contact_id']) : null;
        $login = $contact && $contact->client_user_id ? ClientUser::find($contact->client_user_id) : null;
        $login ??= $this->service->findOrCreateClientUser($ref['email']);

        if (! $login->current_agency_id) {
            $agencies = $this->service->agenciesFor($login);
            $agencyId = $login->locked_to_agency_id ?: ($contact?->agency_id ?: (count($agencies) === 1 ? $agencies[0]['id'] : null));
            if ($agencyId) {
                $login->forceFill(['current_agency_id' => $agencyId])->save();
            }
        }

        $this->service->log($login, (int) ($contact?->agency_id ?? $login->current_agency_id), $contact?->id, 'qa_link_signin', $request, ['email' => $ref['email']]);
        $token = $this->tabs->issue($login);
        $this->tabs->remember($request, $login);

        return response()->json(['token' => $token, 'client' => $this->summarise($login)]);
    }

    /** §28.5 - the web portal page asks for a token of its own (`tab`); only a same-origin browser request can have one (never a different caller). */
    private function wantsTabToken(Request $request): bool
    {
        return $request->boolean('tab') && $this->isStatefulRequest($request);
    }

    /**
     * AT-445 — true only for a same-origin browser request that
     * EnsureFrontendRequestsAreStateful has already promoted (set by its
     * own inline pipeline closure). Mobile bearer-token requests never set
     * this, on any route — they don't carry a matching Origin/Referer.
     */
    /**
     * End the PORTAL login in the browser session. A browser can hold a STAFF login in the same session (the `web` and
     * `client-web` guards keep separate keys), and invalidating the whole session used to sign that staff user out too.
     * Only the portal login leaves; the session itself is destroyed only when no staff user is in it.
     * Spec: .ai/specs/rental-portal-access.md §17.
     */
    private function endPortalSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        // Forget the portal login in the session directly rather than SessionGuard::logout(): that fires the global
        // Logout event, whose staff-only listeners (e.g. RevokeCommsGrantsOnLogout) are typed for App\Models\User and
        // 500 on a ClientUser — the same trap the Login listener already had to be scoped around.
        $guard = Auth::guard('client-web');
        $request->session()->forget($guard->getName());
        $guard->forgetUser();
        if (! Auth::guard('web')->check()) {
            $request->session()->invalidate();
        }
        // A staff page open in this browser keeps its CSRF token (StaffSafeSession).
        \App\Support\StaffSafeSession::rotateToken($request);
    }

    private function isStatefulRequest(Request $request): bool
    {
        return (bool) $request->attributes->get('sanctum', false);
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
