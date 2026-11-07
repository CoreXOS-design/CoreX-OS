<?php

namespace App\Http\Controllers\Api\V1\Website\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBuyerToken;
use App\Models\AuctionWatch;
use App\Models\ClientUser;
use App\Services\Auctions\AuctionContactResolver;
use App\Services\Auctions\AuctionFeeCalculator;
use App\Services\Auctions\AuctionInvoiceService;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\AuctionWebsitePresenter;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Buyer accounts for an external auction website (scope auctions:buyers). The SAME account as the CoreX-hosted auction page - one email, one
 * password - so a buyer who signs up on the website can log in on the page and the other way round; a sign-up creates the same Contact
 * (assigned to the admin, matched not duplicated). Instead of a cookie the website is handed a buyer token (X-Buyer-Token).
 */
class BuyerController extends AuctionApiController
{
    public function __construct(PublicAuctionSite $site, AuctionWebsitePresenter $present, private BuyerAccountService $buyers, private AuctionRegistrationService $registrations)
    {
        parent::__construct($site, $present);
    }

    // ── sign up / log in / reset (no buyer token yet) ──────────────────────

    public function signupSend(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate(['email' => 'required|email|max:190']);

        if ($error = $this->buyers->sendCode($agency, $data['email'], BuyerAccountService::PURPOSE_SIGNUP, $request)) {
            return response()->json(['message' => $error, 'code' => 'rate_limited'], 429);
        }

        return response()->json(['ok' => true, 'message' => 'A code was emailed to the buyer.']);
    }

    public function signupVerify(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'code' => 'required|digits:6',
            'name' => 'required|string|max:190',
            'phone' => 'nullable|string|max:40',
            'password' => ['required', 'confirmed', Password::min(8)],
            'popia_consent' => 'accepted',
        ], ['popia_consent.accepted' => 'Please accept the privacy notice to create your account.']);

        $user = $this->buyers->register($agency, $data['email'], $data['code'], $data['name'], $data['phone'] ?? null, $data['password'], $request, false);
        if (! $user) {
            return response()->json(['message' => 'That code is wrong or has expired. Check it, or ask for a new one.', 'errors' => ['code' => ['That code is wrong or has expired.']]], 422);
        }

        return $this->signedIn($request, $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);

        $result = $this->buyers->attempt($agency, $data['email'], $data['password'], $request, false);
        if (is_string($result)) {
            return response()->json(['message' => $result, 'errors' => ['email' => [$result]]], 422);
        }

        return $this->signedIn($request, $result);
    }

    public function forgotSend(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate(['email' => 'required|email|max:190']);

        if ($error = $this->buyers->sendCode($agency, $data['email'], BuyerAccountService::PURPOSE_RESET, $request)) {
            return response()->json(['message' => $error, 'code' => 'rate_limited'], 429);
        }

        // always the same answer: it never reveals who has an account
        return response()->json(['ok' => true, 'message' => 'If that email has an account, a code is on its way.']);
    }

    public function forgotReset(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate(['email' => 'required|email|max:190', 'code' => 'required|digits:6', 'password' => ['required', 'confirmed', Password::min(8)]]);

        $user = $this->buyers->resetPassword($agency, $data['email'], $data['code'], $data['password'], $request, false);
        if (! $user) {
            return response()->json(['message' => 'That code is wrong or has expired.', 'errors' => ['code' => ['That code is wrong or has expired.']]], 422);
        }

        return $this->signedIn($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('auction_buyer_token')?->revoke();

        return response()->json(['ok' => true]);
    }

    // ── my account (buyer token required) ──────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->account($this->agency($request), $this->buyer($request))]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $buyer = $this->buyer($request);
        $data = $request->validate(['name' => 'required|string|max:190', 'phone' => 'nullable|string|max:40']);

        $contact = $this->buyers->contactFor($buyer, $agency);
        [$first, $last] = app(AuctionContactResolver::class)->splitName($data['name']);
        $contact->forceFill(['first_name' => $first, 'last_name' => $last, 'phone' => $data['phone'] ?? null])->saveQuietly();

        return response()->json(['ok' => true, 'buyer' => $this->present->buyer($buyer, $agency)]);
    }

    public function invoicePdf(Request $request, int $invoice)
    {
        $agency = $this->agency($request);
        $row = $this->present->invoices((int) $agency->id, $this->buyer($request)->id)->firstWhere('id', $invoice);
        abort_if($row === null, 404);

        $service = app(AuctionInvoiceService::class);

        return response($service->pdf($row), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $service->filename($row) . '"']);
    }

    // ── registering to bid ─────────────────────────────────────────────────

    /** What the registration form needs for THIS buyer: the deadline, the documents required, the ones already on file, the conditions. */
    public function registrationShow(Request $request, int $auction): JsonResponse
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $buyer = $this->buyer($request);
        $reg = $this->registrations->forBuyer($a, $buyer);
        $kinds = AgencyAuctionSettings::DOCUMENT_KINDS;

        return response()->json(['data' => [
            'open' => $this->registrations->isOpen($a),
            'closes_at' => $this->registrations->deadline($a)?->toIso8601String(),
            'registration' => $reg ? $this->present->registration($reg) : null,
            'required_documents' => [
                'individual' => collect($this->registrations->requiredKinds($a, 'individual'))->map(fn ($k) => ['kind' => $k, 'label' => $kinds[$k] ?? $k])->values()->all(),
                'company' => collect($this->registrations->requiredKinds($a, 'company'))->map(fn ($k) => ['kind' => $k, 'label' => $kinds[$k] ?? $k])->values()->all(),
            ],
            'on_file' => $this->registrations->onFile($buyer, (int) $agency->id)->map(fn ($docs) => $docs->map(fn ($d) => ['id' => $d->id, 'name' => $d->original_name, 'uploaded_at' => $d->created_at?->toIso8601String()])->values())->all(),
            'conditions_text' => AgencyAuctionSettings::termsTextFor((int) $agency->id),
            'fee_notice' => app(AuctionFeeCalculator::class)->notice($a) ?: null,
        ]]);
    }

    /** Register to bid. Multipart: bidder_type, company_name?, company_reg_no?, accept_conditions, documents[<kind>] (files), existing[<kind>] (ids on file). */
    public function registrationSubmit(Request $request, int $auction): JsonResponse
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'bidder_type' => 'required|in:individual,company',
            'company_name' => 'nullable|string|max:190',
            'company_reg_no' => 'nullable|string|max:60',
            'accept_conditions' => 'accepted',
            'documents' => 'nullable|array',
            'documents.*' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'existing' => 'nullable|array',
            'existing.*' => 'nullable|integer',
        ], ['accept_conditions.accepted' => 'Please accept the conditions of the auction to register.']);

        $reg = $this->registrations->submit(
            $a, $buyer, $this->buyers->contactFor($buyer, $agency), $data,
            array_filter((array) $request->file('documents', [])), array_filter((array) $request->input('existing', [])), $request->ip(),
        );

        return response()->json(['ok' => true, 'data' => $this->present->registration($reg)], 201);
    }

    /** Add the lot to "watching", or take it off again. */
    public function watch(Request $request, int $lot): JsonResponse
    {
        $l = $this->publicLot($request, $lot);
        $buyer = $this->buyer($request);

        $existing = AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $l->id)->where('client_user_id', $buyer->id)->first();
        if ($existing) {
            $existing->delete();
        } else {
            AuctionWatch::withoutAgencyStamping(fn () => AuctionWatch::create(['agency_id' => $l->agency_id, 'lot_id' => $l->id, 'client_user_id' => $buyer->id]));
        }

        return response()->json(['ok' => true, 'watching' => $existing === null]);
    }

    private function signedIn(Request $request, ClientUser $user, int $status = 200): JsonResponse
    {
        $agency = $this->agency($request);
        [$row, $plain] = AuctionBuyerToken::issue($user, (int) $agency->id, $this->key($request)->id, $request->ip());

        return response()->json([
            'ok' => true, 'token' => $plain, 'expires_at' => $row->expires_at?->toIso8601String(),
            'buyer' => $this->present->buyer($user, $agency),
        ], $status);
    }
}
