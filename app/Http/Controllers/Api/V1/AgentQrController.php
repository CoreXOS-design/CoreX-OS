<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use App\Services\ClientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

/**
 * Public endpoints for the mobile agent-QR onboarding flow.
 *
 * Spec: .ai/specs/agent-qr-onboarding.md
 */
class AgentQrController extends Controller
{
    public function __construct(private readonly ClientAuthService $service) {}

    /**
     * GET /api/v1/me/agent-qr
     * Returns the authenticated agent's own QR slug + canonical URL.
     * Rejects client-portal tokens (which auth as a ClientUser, not a User).
     */
    public function mine(\Illuminate\Http\Request $request): JsonResponse
    {
        $agent = $request->user();
        if (!$agent instanceof User) {
            return response()->json(['message' => 'Agent token required.'], 403);
        }

        $slug   = $agent->ensureQrSlug();
        $url    = $agent->qrCodeUrl();
        $imgArg = urlencode($url);

        return response()->json([
            'slug'    => $slug,
            'url'     => $url,
            'png_url' => "https://api.qrserver.com/v1/create-qr-code/?size=1024x1024&margin=8&ecc=H&format=png&data={$imgArg}",
            'agent'   => $this->presentAgent($agent),
        ]);
    }

    /**
     * GET /api/v1/client-auth/agent-qr/{slug}
     * Returns a public-safe preview of the agent for the onboarding screen.
     */
    public function show(string $slug): JsonResponse
    {
        $agent = $this->resolveAgent($slug);
        if (!$agent) {
            return response()->json(['message' => 'Unknown agent QR code.'], 404);
        }

        return response()->json([
            'agent' => $this->presentAgent($agent),
        ]);
    }

    /**
     * POST /api/v1/client-auth/agent-qr/{slug}/register
     *
     * Two outcomes, decided by whether the email is already a known CoreX
     * identity (a ClientUser auth account OR a Contact carrying this email in
     * any agency):
     *
     *  - BRAND-NEW email → create the Contact in the agent's agency (attributed
     *    to that agent, source "Agent QR"), create the ClientUser with the
     *    supplied password, link them, sign in. 201 with a session token.
     *
     *  - KNOWN email → still create/link the Contact in the agent's agency so
     *    the prospect shows under this agent, but NEVER accept the supplied
     *    password and NEVER issue a session (account-takeover guard). Instead
     *    tell the app to verify ownership via OTP first (same path as
     *    POST /api/v1/client-auth/lookup). 200 with requires_verification, no
     *    token.
     *
     * Spec: .ai/specs/agent-qr-onboarding.md, .ai/specs/client-auth.md
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        $agent = $this->resolveAgent($slug);
        if (!$agent) {
            return response()->json(['message' => 'Unknown agent QR code.'], 404);
        }

        $rlKey = "agent-qr.register:{$slug}:" . $request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            return response()->json(['message' => 'Too many sign-ups. Try again later.'], 429);
        }
        RateLimiter::hit($rlKey, 3600);

        $data = $request->validate([
            'first_name'  => 'required|string|max:80',
            'last_name'   => 'required|string|max:80',
            'phone'       => 'nullable|string|max:30',
            'email'       => 'required|email|max:255',
            'password'    => ['required', 'confirmed', Password::min(8)],
            'device_name' => 'nullable|string|max:120',
        ]);

        $email = strtolower(trim($data['email']));

        // Is this email already a known CoreX identity ANYWHERE? Known means a
        // ClientUser auth account exists, OR a Contact already carries this
        // email in some agency. Both must verify ownership via OTP before any
        // password is set — silently accepting a password here would let anyone
        // who knows the email take over the existing person's record.
        $existingClientUser = ClientUser::where('email', $email)->first();
        $crossAgency        = $this->service->findContactsByIdentifierAcrossAgencies($email);
        $isKnownIdentity    = $existingClientUser !== null || $crossAgency['contacts']->isNotEmpty();

        // Either way, the scan attaches the prospect to THIS agent in THIS
        // agency. Idempotent — repeated scans never duplicate the contact.
        $contact = $this->upsertAgencyContact($agent, $data, $email, $existingClientUser);

        if ($isKnownIdentity) {
            // Stamp origin agency on a pre-existing login if missing, but NEVER
            // touch the password and NEVER issue a session token.
            if ($existingClientUser && empty($existingClientUser->created_by_agency_id)) {
                $existingClientUser->forceFill(['created_by_agency_id' => $agent->agency_id])->save();
            }

            $this->service->log(
                $existingClientUser, $agent->agency_id, $contact->id,
                'agent_qr_linked_existing', $request,
                ['agent_user_id' => $agent->id, 'qr_slug' => $slug, 'verification' => 'otp']
            );

            $agency = $this->presentAgency($agent);

            return response()->json([
                'existing'              => true,
                'requires_verification' => true,
                'verification'          => 'otp',
                'agent'                 => $this->presentAgent($agent),
                'agency'                => $agency,
                'message'               => 'This email is already registered with '
                    . ($agency['name'] ?? 'this agency')
                    . '. Verify it to continue.',
            ], 200);
        }

        // ----- Brand-new identity: create the client login + sign them in. -----
        $clientUser = ClientUser::create([
            'email'                => $email,
            'password'             => Hash::make($data['password']),
            'password_must_change' => false,
            'password_set_at'      => now(),
            'activated_at'         => now(),
            'first_login_at'       => now(),
            'last_login_at'        => now(),
            'created_by_agency_id' => $agent->agency_id,
            'current_agency_id'    => $agent->agency_id,
        ]);

        if (!$contact->client_user_id) {
            $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();
        }

        $token = $this->service->issueSanctumToken(
            $clientUser,
            $data['device_name'] ?? 'CoreX Client App'
        );

        $this->service->log($clientUser, $agent->agency_id, $contact->id, 'password_set', $request, [
            'source'        => 'agent_qr',
            'agent_user_id' => $agent->id,
            'qr_slug'       => $slug,
        ]);
        $this->service->log($clientUser, $agent->agency_id, $contact->id, 'password_login_success', $request, [
            'agent_user_id' => $agent->id,
            'qr_slug'       => $slug,
        ]);

        return response()->json([
            'existing'    => false,
            'token'       => $token,
            'agent'       => $this->presentAgent($agent),
            'agency'      => $this->presentAgency($agent),
            'contact'     => ['id' => $contact->id],
            'client_user' => ['id' => $clientUser->id, 'email' => $clientUser->email],
        ], 201);
    }

    /**
     * Ensure a Contact exists in the agent's agency for this email, attributed
     * to the scanning agent and sourced as "Agent QR". Idempotent: an existing
     * contact is enriched (never overwritten) so repeated scans don't create
     * duplicates and never steal an existing agent's attribution or data.
     */
    private function upsertAgencyContact(User $agent, array $data, string $email, ?ClientUser $clientUser): Contact
    {
        $contact = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agent->agency_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$contact) {
            // ContactObserver::creating auto-links to an existing ClientUser by
            // email and backfills branch_id.
            $contact = new Contact();
            $contact->forceFill([
                'agency_id'          => $agent->agency_id,
                'branch_id'          => $agent->branch_id,
                'created_by_user_id' => $agent->id,
                'contact_source_id'  => $this->agentQrSourceId((int) $agent->agency_id),
                'first_name'         => trim($data['first_name']),
                'last_name'          => trim($data['last_name']),
                'phone'              => $data['phone'] ?? null,
                'email'              => $email,
            ])->save();
        } else {
            // Enrich only empty fields — never clobber an existing agent's lead.
            $patch = [];
            if (empty($contact->created_by_user_id)) {
                $patch['created_by_user_id'] = $agent->id;
            }
            if (empty($contact->contact_source_id)) {
                $patch['contact_source_id'] = $this->agentQrSourceId((int) $agent->agency_id);
            }
            if (empty($contact->first_name) && trim($data['first_name']) !== '') {
                $patch['first_name'] = trim($data['first_name']);
            }
            if (empty($contact->last_name) && trim($data['last_name']) !== '') {
                $patch['last_name'] = trim($data['last_name']);
            }
            if (empty($contact->phone) && !empty($data['phone'])) {
                $patch['phone'] = $data['phone'];
            }
            if ($patch) {
                $contact->forceFill($patch)->saveQuietly();
            }
        }

        // Safety net: link to the known identity if not already linked.
        if ($clientUser && empty($contact->client_user_id)) {
            $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();
        }

        return $contact;
    }

    /**
     * Find or create the per-agency "Agent QR" contact source. Idempotent so
     * repeated registrations reuse the same source row.
     */
    private function agentQrSourceId(int $agencyId): int
    {
        $source = ContactSource::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereRaw('LOWER(name) = ?', ['agent qr'])
            ->first();

        if (!$source) {
            $source = new ContactSource();
            $source->forceFill([
                'agency_id' => $agencyId,
                'name'      => 'Agent QR',
                'is_active' => true,
            ])->save();
        }

        return (int) $source->id;
    }

    private function resolveAgent(string $slug): ?User
    {
        // Follows the reroute chain so a departed agent's QR keeps working.
        return User::resolveByQrSlug($slug);
    }

    private function presentAgent(User $agent): array
    {
        $first = trim(explode(' ', (string) $agent->name)[0] ?? '');
        $last  = trim((string) str_replace($first, '', (string) $agent->name));

        return [
            'first_name' => $first,
            'last_name'  => $last,
            'full_name'  => $agent->name,
            'photo_url'  => method_exists($agent, 'profilePhotoUrl') ? $agent->profilePhotoUrl() : null,
            'agency'     => $this->presentAgency($agent),
        ];
    }

    private function presentAgency(User $agent): ?array
    {
        if (!$agent->agency_id) {
            return null;
        }
        $agency = \App\Models\Agency::withoutGlobalScopes()->find($agent->agency_id);
        if (!$agency) {
            return null;
        }
        return [
            'id'   => $agency->id,
            'name' => $agency->name,
            'slug' => $agency->slug,
        ];
    }
}
