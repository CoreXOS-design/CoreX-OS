<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Buyer sign-up, login, password reset and "my account" for the public auction site (.ai/specs/auctions.md §8.2).
 * Sign-up and reset are two steps: the email gets a one-time code, then the buyer finishes with the code.
 */
class BuyerAuthController extends Controller
{
    public function __construct(private PublicAuctionSite $site, private BuyerAccountService $buyers) {}

    // ── sign up ────────────────────────────────────────────────────────────

    public function signupForm(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        if ($this->buyers->current()) {
            return redirect()->route('public.auctions.account', $agencySlug);
        }
        $this->rememberIntended($request, $agencySlug);

        return view('public.auctions.signup', SiteController::layoutData($agency, null) + ['step' => 'email']);
    }

    public function signupSend(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $data = $request->validate(['email' => 'required|email|max:190']);

        if ($error = $this->buyers->sendCode($agency, $data['email'], BuyerAccountService::PURPOSE_SIGNUP, $request)) {
            return back()->withErrors(['email' => $error])->withInput();
        }
        $request->session()->put($this->key($agencySlug, 'signup'), strtolower(trim($data['email'])));

        return redirect()->route('public.auctions.signup.verify', $agencySlug);
    }

    public function signupVerifyForm(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $email = $request->session()->get($this->key($agencySlug, 'signup'));
        if (! $email) {
            return redirect()->route('public.auctions.signup', $agencySlug);
        }

        return view('public.auctions.signup', SiteController::layoutData($agency, null) + ['step' => 'verify', 'email' => $email]);
    }

    public function signupComplete(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $email = $request->session()->get($this->key($agencySlug, 'signup'));
        if (! $email) {
            return redirect()->route('public.auctions.signup', $agencySlug);
        }

        $data = $request->validate([
            'code' => 'required|digits:6',
            'name' => 'required|string|max:190',
            'phone' => 'nullable|string|max:40',
            'password' => ['required', 'confirmed', Password::min(8)],
            'popia_consent' => 'accepted',
        ], ['popia_consent.accepted' => 'Please accept the privacy notice to create your account.']);

        $user = $this->buyers->register($agency, $email, $data['code'], $data['name'], $data['phone'] ?? null, $data['password'], $request);
        if (! $user) {
            return back()->withErrors(['code' => 'That code is wrong or has expired. Check it, or go back and ask for a new one.'])->withInput($request->except('password', 'password_confirmation'));
        }

        $request->session()->forget($this->key($agencySlug, 'signup'));

        return $this->afterSignIn($request, $agencySlug, 'Welcome. Your account is ready.');
    }

    // ── log in / out ───────────────────────────────────────────────────────

    public function loginForm(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        if ($this->buyers->current()) {
            return redirect()->route('public.auctions.account', $agencySlug);
        }
        $this->rememberIntended($request, $agencySlug);

        return view('public.auctions.login', SiteController::layoutData($agency, null));
    }

    public function login(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $data = $request->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);

        $result = $this->buyers->attempt($agency, $data['email'], $data['password'], $request);
        if (is_string($result)) {
            return back()->withErrors(['email' => $result])->withInput($request->only('email'));
        }

        return $this->afterSignIn($request, $agencySlug, 'You are signed in.');
    }

    public function logout(Request $request, string $agencySlug)
    {
        $this->site->agency($agencySlug);
        $this->buyers->logout($request);

        return redirect()->route('public.auctions.home', $agencySlug)->with('status', 'You have been signed out.');
    }

    // ── forgot password ────────────────────────────────────────────────────

    public function forgotForm(string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);

        return view('public.auctions.forgot', SiteController::layoutData($agency, null) + ['step' => 'email']);
    }

    public function forgotSend(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $data = $request->validate(['email' => 'required|email|max:190']);

        if ($error = $this->buyers->sendCode($agency, $data['email'], BuyerAccountService::PURPOSE_RESET, $request)) {
            return back()->withErrors(['email' => $error])->withInput();
        }
        $request->session()->put($this->key($agencySlug, 'reset'), strtolower(trim($data['email'])));

        return redirect()->route('public.auctions.forgot.reset', $agencySlug)
            ->with('status', 'If that email has an account, a code is on its way.');
    }

    public function resetForm(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $email = $request->session()->get($this->key($agencySlug, 'reset'));
        if (! $email) {
            return redirect()->route('public.auctions.forgot', $agencySlug);
        }

        return view('public.auctions.forgot', SiteController::layoutData($agency, null) + ['step' => 'reset', 'email' => $email]);
    }

    public function resetComplete(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $email = $request->session()->get($this->key($agencySlug, 'reset'));
        if (! $email) {
            return redirect()->route('public.auctions.forgot', $agencySlug);
        }

        $data = $request->validate(['code' => 'required|digits:6', 'password' => ['required', 'confirmed', Password::min(8)]]);

        $user = $this->buyers->resetPassword($agency, $email, $data['code'], $data['password'], $request);
        if (! $user) {
            return back()->withErrors(['code' => 'That code is wrong or has expired.'])->withInput($request->only('code'));
        }
        $request->session()->forget($this->key($agencySlug, 'reset'));

        return $this->afterSignIn($request, $agencySlug, 'Your password was changed and you are signed in.');
    }

    // ── my account ─────────────────────────────────────────────────────────

    public function account(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $buyer = $this->buyers->current();
        if (! $buyer) {
            return redirect()->route('public.auctions.login', $agencySlug);
        }

        return view('public.auctions.account', SiteController::layoutData($agency, $buyer) + [
            'contact' => $this->buyers->contactFor($buyer, $agency),
            'registrations' => \App\Models\AuctionRegistration::withoutGlobalScopes()->whereNull('deleted_at')->with('auction')
                ->where('agency_id', $agency->id)->where('client_user_id', $buyer->id)->latest('id')->get(),
            'won' => \App\Models\AuctionLot::withoutGlobalScopes()->whereNull('deleted_at')->with('auction')
                ->whereIn('status', ['sold', 'sold_subject_to_confirmation'])
                ->whereIn('winning_bid_id', \App\Models\AuctionBid::withoutGlobalScopes()->select('id')->whereIn('registration_id',
                    \App\Models\AuctionRegistration::withoutGlobalScopes()->select('id')->where('agency_id', $agency->id)->where('client_user_id', $buyer->id)))
                ->latest('result_at')->get(),
            'watching' => \App\Models\AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->with('lot')
                ->where('agency_id', $agency->id)->where('client_user_id', $buyer->id)->latest('id')->get()->filter(fn ($w) => $w->lot),
        ]);
    }

    public function accountUpdate(Request $request, string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $buyer = $this->buyers->current();
        abort_unless($buyer, 403);

        $data = $request->validate(['name' => 'required|string|max:190', 'phone' => 'nullable|string|max:40']);
        $contact = $this->buyers->contactFor($buyer, $agency);
        [$first, $last] = app(\App\Services\Auctions\AuctionContactResolver::class)->splitName($data['name']);
        $contact->forceFill(['first_name' => $first, 'last_name' => $last, 'phone' => $data['phone'] ?? null])->saveQuietly();

        return back()->with('status', 'Your details were saved.');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function afterSignIn(Request $request, string $agencySlug, string $message)
    {
        $to = $request->session()->pull($this->key($agencySlug, 'intended'));
        $url = is_string($to) && str_starts_with($to, url('/auctions/' . $agencySlug)) ? $to : route('public.auctions.home', $agencySlug);

        return redirect()->to($url)->with('status', $message);
    }

    /** Remember where the buyer was heading (a lot page, usually) — only ever a page on this same auction site. */
    private function rememberIntended(Request $request, string $agencySlug): void
    {
        $back = (string) $request->query('back', '');
        if ($back !== '' && str_starts_with($back, url('/auctions/' . $agencySlug))) {
            $request->session()->put($this->key($agencySlug, 'intended'), $back);
        }
    }

    private function key(string $agencySlug, string $what): string
    {
        return "auction_site.{$agencySlug}.{$what}";
    }
}
