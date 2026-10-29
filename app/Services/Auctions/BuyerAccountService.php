<?php

namespace App\Services\Auctions;

use App\Models\Agency;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Services\ClientAuthService;
use App\Services\Otp\OtpService;
use App\Support\StaffSafeSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Buyer accounts for the public auction site (.ai/specs/auctions.md §8.2).
 *
 * A buyer IS the existing client login (`client_users`, the same identity the mobile app and rental portal use) with
 * the web session guard `client-web`. Sign-up and password reset prove control of the email with a one-time code that
 * goes out as the AGENCY (its logo, its name), never as CoreX. Each agency the buyer uses gets its own Contact.
 */
class BuyerAccountService
{
    public const GUARD = 'client-web';
    public const PURPOSE_SIGNUP = 'activation';
    public const PURPOSE_RESET = 'recovery';

    public function __construct(
        private ClientAuthService $client,
        private AuctionContactResolver $contacts,
    ) {}

    /**
     * Send a sign-up or reset code. Returns an error message only for rate limits — an unknown address, or one that
     * cannot be reset, looks exactly like success so the form never reveals who has an account.
     */
    public function sendCode(Agency $agency, string $email, string $purpose, Request $request): ?string
    {
        $email = $this->email($email);

        $throttle = app(OtpService::class)->throttle('client_login', $email);
        if ($throttle === 'cooldown') {
            return 'Please wait a minute before asking for another code.';
        }
        if ($throttle === 'hourly') {
            return 'Too many codes requested. Please try again later.';
        }

        $existing = ClientUser::where('email', $email)->first();
        if ($purpose === self::PURPOSE_RESET) {
            $fake = str_ends_with($email, '@' . config('clientauth.fake_email_domain', 'corexclient.co.za'));
            if (! $existing || ! $existing->hasPassword() || $fake) {
                return null;
            }
        }

        $this->client->issueOtp($email, $purpose, $request, $agency);

        return null;
    }

    /**
     * Finish sign-up: code + profile + password. Null when the code is wrong or expired.
     */
    public function register(Agency $agency, string $email, string $code, string $name, ?string $phone, string $password, Request $request): ?ClientUser
    {
        $email = $this->email($email);
        if (! $this->client->verifyOtp($email, $code, self::PURPOSE_SIGNUP, $request)) {
            return null;
        }

        $user = $this->client->findOrCreateClientUser($email);
        $user->forceFill([
            'password' => $password,
            'password_set_at' => now(),
            'password_must_change' => false,
            'activated_at' => $user->activated_at ?? now(),
            'created_by_agency_id' => $user->created_by_agency_id ?: $agency->id,
            'current_agency_id' => $agency->id,
        ])->save();

        $contact = $this->contactFor($user, $agency, $name, $phone);
        $this->client->log($user, (int) $agency->id, (int) $contact->id, 'auction_signup', $request, ['consent_popia' => true]);

        $this->signIn($request, $user);

        return $user;
    }

    /** Reset the password with a recovery code. Null when the code is wrong or expired. */
    public function resetPassword(Agency $agency, string $email, string $code, string $password, Request $request): ?ClientUser
    {
        $email = $this->email($email);
        if (! $this->client->verifyOtp($email, $code, self::PURPOSE_RESET, $request)) {
            return null;
        }

        $user = ClientUser::where('email', $email)->first();
        if (! $user) {
            return null;
        }
        $user->forceFill(['password' => $password, 'password_set_at' => now(), 'password_must_change' => false])->save();
        // a new password ends every phone-app / portal token of this person
        $user->tokens()->delete();
        $this->client->log($user, (int) $agency->id, null, 'password_reset', $request);

        $this->contactFor($user, $agency);
        $this->signIn($request, $user);

        return $user;
    }

    /**
     * @return ClientUser|string the signed-in buyer, or the message to show
     */
    public function attempt(Agency $agency, string $email, string $password, Request $request): ClientUser|string
    {
        $email = $this->email($email);
        $key = 'auction-buyer-login:' . $request->ip() . ':' . $email;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return 'Too many attempts. Please try again in a few minutes.';
        }

        $user = ClientUser::where('email', $email)->first();
        if (! $user || ! $user->hasPassword() || ! Hash::check($password, (string) $user->password)) {
            RateLimiter::hit($key, 300);

            return 'That email and password do not match.';
        }
        if ($user->password_must_change) {
            return 'Your agent set a temporary password. Use "Forgot your password?" to choose your own.';
        }
        RateLimiter::clear($key);

        $this->contactFor($user, $agency);
        $user->forceFill(['current_agency_id' => $agency->id, 'last_login_at' => now(), 'last_ip' => $request->ip(), 'first_login_at' => $user->first_login_at ?? now()])->save();
        $this->client->log($user, (int) $agency->id, null, 'auction_login', $request);
        $this->signIn($request, $user);

        return $user;
    }

    public function logout(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }
        // Forget the portal login directly rather than SessionGuard::logout(): its Logout event reaches staff-only
        // listeners typed for App\Models\User, which 500 on a ClientUser (same rule as the portal logout).
        $guard = Auth::guard(self::GUARD);
        $request->session()->forget($guard->getName());
        $guard->forgetUser();
        if (! Auth::guard('web')->check()) {
            $request->session()->invalidate();
        }
        StaffSafeSession::rotateToken($request);
    }

    public function current(): ?ClientUser
    {
        $u = Auth::guard(self::GUARD)->user();

        return $u instanceof ClientUser ? $u : null;
    }

    /** This buyer's Contact in the agency — created (match-or-create) the first time they use this agency's site. */
    public function contactFor(ClientUser $user, Agency $agency, ?string $name = null, ?string $phone = null): Contact
    {
        $contact = $this->client->contactForAgency($user, (int) $agency->id);
        if ($contact) {
            return $contact;
        }

        $display = trim((string) $name) !== '' ? (string) $name : (string) strstr($user->email, '@', true);
        [$contact] = $this->contacts->resolve((int) $agency->id, $display, $user->email, $phone, null, $user->id);
        if (! $contact->client_user_id) {
            $contact->forceFill(['client_user_id' => $user->id])->saveQuietly();
        }

        return $contact;
    }

    private function signIn(Request $request, ClientUser $user): void
    {
        // SessionGuard::login() already migrates the session id (no fixation); loginPortal keeps a staff page's CSRF token valid.
        StaffSafeSession::loginPortal($request, Auth::guard(self::GUARD), $user);
    }

    private function email(string $email): string
    {
        return strtolower(trim($email));
    }
}
