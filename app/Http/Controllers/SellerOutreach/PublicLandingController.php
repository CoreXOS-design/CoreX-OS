<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Services\SellerOutreach\SellerOutreachLandingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public (unauthenticated) controller for the seller-facing landing page.
 *
 * The shortcode IS the entry point. Every click is logged. The page renders
 * one of 3 modes (Active / Generic / Agent-Unavailable) based on the
 * current state of the property + agent at click time.
 *
 * Spec: .ai/specs/seller-outreach-spec.md S8, 6.4, 6.5.
 */
final class PublicLandingController extends Controller
{
    public function __construct(
        private readonly SellerOutreachLandingService $landing,
    ) {}

    /**
     * GET /m/{shortcode}
     */
    public function show(Request $request, string $shortcode)
    {
        // The shortcode alphabet has no special chars — strict regex
        // protects against arbitrary URL probing.
        if (!preg_match('/^[A-Za-z0-9]{6}$/', $shortcode)) {
            abort(404);
        }

        try {
            $landingData = $this->landing->resolveLanding($shortcode);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        // Record the click — failure-isolated so the page still renders
        // even if recording fails (POPIA + network resilience).
        try {
            $this->landing->recordClick($landingData->send, $request);
        } catch (Throwable $e) {
            Log::warning('Landing page click recording failed', [
                'short_code' => $shortcode,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()
            ->view('seller-outreach.landing', ['ld' => $landingData])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * POST /m/{shortcode}/callback
     *
     * Seller requests a callback. Creates a row in seller_outreach_callbacks
     * that surfaces on the agent's dashboard (subsequent prompt).
     */
    public function callback(Request $request, string $shortcode)
    {
        if (!preg_match('/^[A-Za-z0-9]{6}$/', $shortcode)) {
            abort(404);
        }

        try {
            $landingData = $this->landing->resolveLanding($shortcode);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $validated = $request->validate([
            'requester_name'  => 'nullable|string|max:150',
            'requester_phone' => 'nullable|string|max:30',
            'requester_email' => 'nullable|email|max:255',
            'preferred_time'  => 'nullable|string|max:100',
            'message'         => 'nullable|string|max:2000',
        ]);

        if (empty($validated['requester_phone']) && empty($validated['requester_email'])) {
            return back()
                ->withErrors(['contact_required' => 'Please leave at least a phone number or email so we can reach you.'])
                ->withInput();
        }

        DB::table('seller_outreach_callbacks')->insert([
            'agency_id'       => $landingData->send->agency_id,
            'send_id'         => $landingData->send->id,
            'contact_id'      => $landingData->send->contact_id,
            'requester_name'  => $validated['requester_name'] ?? null,
            'requester_phone' => $validated['requester_phone'] ?? null,
            'requester_email' => $validated['requester_email'] ?? null,
            'preferred_time'  => $validated['preferred_time'] ?? null,
            'message'         => $validated['message'] ?? null,
            'ip_address'      => $request->ip(),
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return back()->with('callback_status', "Thanks — we'll be in touch soon.");
    }
}
