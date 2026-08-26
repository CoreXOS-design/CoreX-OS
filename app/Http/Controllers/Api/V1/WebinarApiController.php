<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Services\Webinars\WebinarRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The public front door for webinar registration.
 *
 * Spec: .ai/specs/webinar-registration.md §4
 *
 * Called SERVER-TO-SERVER by the CoreX marketing website, authenticated by the site
 * connector (EnsureSiteConnector). The visitor's browser never reaches this
 * controller and never sees an access code — the website posts the form on their
 * behalf and shows its own thank-you page.
 *
 * SERVED BY PRIMARY ONLY; the middleware enforces it. Registrations and the demo
 * grants they issue are durable records, and the demo host's database is destroyed
 * every three days.
 */
class WebinarApiController extends Controller
{
    public function __construct(private readonly WebinarRegistrationService $service) {}

    /**
     * GET /api/v1/webinars/ping
     *
     * Reachability probe. Powers the "Test connection" button on the admin connector
     * card, and gives the website a cheap way to prove its token before anyone
     * depends on it at registration time.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok'      => true,
            'service' => 'corex-webinars',
            'time'    => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/webinars/{slug}
     *
     * So the website renders live details instead of hard-coding them and drifting
     * out of step with what CoreX will actually enforce.
     *
     * join_url IS DELIBERATELY ABSENT. It is earned by registering, not by reading
     * the page — a join link in a public response is a webinar anyone can walk into
     * without ever telling us who they are.
     */
    public function show(string $slug): JsonResponse
    {
        $webinar = Webinar::notArchived()->where('slug', $slug)->first();

        if (! $webinar) {
            return $this->notFound();
        }

        return response()->json([
            'ok'      => true,
            'webinar' => [
                'slug'                => $webinar->slug,
                'title'               => $webinar->title,
                'description'         => $webinar->description,
                'starts_at'           => $webinar->starts_at->toIso8601String(),
                'duration_minutes'    => $webinar->duration_minutes,
                'registration_open'   => $webinar->isOpenForRegistration(),
                'demo_access_ends_at' => $webinar->demoAccessEndsAt()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/webinars/{slug}/register
     *
     * Creates the registration, issues demo access on the webinar's fixed deadline,
     * and queues ONE email carrying the confirmation, the join link, a calendar
     * invite and the credentials.
     *
     * THE RESPONSE NEVER CONTAINS THE ACCESS CODE, and the code is never logged. It
     * exists in the queued email and nowhere else.
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        $webinar = Webinar::notArchived()->where('slug', $slug)->first();

        // Closed, archived, past, or never existed — all one answer. Which of those
        // it was is not the website's business, and distinguishing them would let
        // anyone map our sales calendar by probing slugs.
        if (! $webinar || ! $webinar->isOpenForRegistration()) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'name'         => ['required', 'string', 'max:255'],
                'email'        => ['required', 'string', 'email', 'max:255'],
                'company_name' => ['required', 'string', 'max:255'],
                'phone'        => ['nullable', 'string', 'max:50'],
            ], [
                'name.required'         => 'Please enter your name.',
                'email.required'        => 'Please enter your email address.',
                'email.email'           => 'Please enter a valid email address.',
                'company_name.required' => 'Please enter your company name.',
            ]);
        } catch (ValidationException $e) {
            // Field-keyed, so the website can render each message against its own
            // input rather than dumping one banner at the top of the form.
            return response()->json([
                'ok'     => false,
                'errors' => $e->errors(),
            ], 422);
        }

        $result = $this->service->register(
            $webinar,
            $data,
            $request->ip(),
            $request->userAgent(),
        );

        // Log the fact, never the credential.
        Log::info('[webinars] registration accepted', [
            'webinar_id'      => $webinar->id,
            'registration_id' => $result['registration']->id,
            'throttled'       => $result['throttled'],
        ]);

        // A repeat submit inside the cooldown is NOT an error — the person did
        // nothing wrong, and telling them so would be confusing. They are registered;
        // the email they already have is still the one that works.
        return response()->json([
            'ok'         => true,
            'registered' => true,
            'throttled'  => $result['throttled'],
            'message'    => $result['throttled']
                ? 'You are already registered. Check your inbox for the confirmation email we sent you a few minutes ago.'
                : 'You are registered. Check your inbox for your joining details and demo access.',
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'That webinar is not open for registration.',
        ], 404);
    }
}
