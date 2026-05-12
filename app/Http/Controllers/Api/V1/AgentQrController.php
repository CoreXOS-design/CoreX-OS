<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Contact;
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
     * Creates a Contact in the agent's agency + a ClientUser + logs the
     * client in. If the email is already a CoreX client, attaches a new
     * Contact to the existing identity without changing the password.
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
        $existing = ClientUser::where('email', $email)->first();

        // ----- Create/locate the Contact in the agent's agency -----
        // ContactObserver auto-links to an existing ClientUser by email,
        // so we don't have to attach it here manually.
        $contact = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agent->agency_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->forceFill([
                'agency_id'          => $agent->agency_id,
                'branch_id'          => $agent->branch_id,
                'created_by_user_id' => $agent->id,
                'first_name'         => trim($data['first_name']),
                'last_name'          => trim($data['last_name']),
                'phone'              => $data['phone'] ?? null,
                'email'              => $email,
            ])->save();
        }

        // ----- Resolve / create ClientUser -----
        if ($existing) {
            $clientUser = $existing;

            // Ensure the new (or pre-existing) Contact is linked.
            if (!$contact->client_user_id) {
                $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();
            }
            if (empty($clientUser->created_by_agency_id)) {
                $clientUser->forceFill(['created_by_agency_id' => $agent->agency_id])->save();
            }

            $token = $this->service->issueSanctumToken(
                $clientUser,
                $data['device_name'] ?? 'CoreX Client App'
            );

            $this->service->log(
                $clientUser, $agent->agency_id, $contact->id,
                'agent_qr_linked_existing', $request,
                ['agent_user_id' => $agent->id, 'qr_slug' => $slug]
            );

            return response()->json([
                'existing'      => true,
                'message'       => 'Account already exists — signed in with your existing CoreX credentials and linked to this agent.',
                'token'         => $token,
                'agent'         => $this->presentAgent($agent),
                'agency'        => $this->presentAgency($agent),
                'contact'       => ['id' => $contact->id],
                'client_user'   => ['id' => $clientUser->id, 'email' => $clientUser->email],
            ], 200);
        }

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

    private function resolveAgent(string $slug): ?User
    {
        if (!preg_match('/^[a-z0-9]{6,16}$/', $slug)) {
            return null;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('qr_code_slug', $slug)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
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
