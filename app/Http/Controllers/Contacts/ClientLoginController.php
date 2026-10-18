<?php

namespace App\Http\Controllers\Contacts;

use App\Exceptions\Rentals\PortalAccessException;
use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Services\ClientAuthService;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Web controller — agent actions on a contact's client-app login.
 * Spec: .ai/specs/client-auth.md
 */
class ClientLoginController extends Controller
{
    public function __construct(private readonly ClientAuthService $service) {}

    /**
     * Create the client login for this contact — or attach the contact to the login that already exists for the
     * email (a person is one login). Never a bare "already in use": the service says in plain words why not.
     * Spec: .ai/specs/rental-portal-access.md §16.
     */
    public function create(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('client_app.create_login');

        $data = $request->validate([
            'email'    => 'required|email|max:255',
            'password' => 'nullable|string|min:8|max:120',
        ]);

        try {
            $result = app(RentalPortalAccessService::class)->attach(
                $contact, $data['email'], auth()->user(), $request, $data['password'] ?? null
            );
        } catch (PortalAccessException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('client_login_success', $result['message']);
    }

    public function reset(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('client_app.reset_password');

        $data = $request->validate(['password' => 'required|string|min:8|max:120']);

        if (!$contact->client_user_id) {
            return back()->withErrors(['password' => 'No client login on this contact.']);
        }

        /** @var ClientUser $cu */
        $cu = ClientUser::findOrFail($contact->client_user_id);
        $this->ensureOriginAgency($cu, $contact);
        $cu->forceFill([
            'password' => Hash::make($data['password']),
            'password_must_change' => true,
            'password_set_at' => now(),
        ])->save();

        // Revoke all existing tokens — force re-login.
        $cu->tokens()->delete();

        $this->service->log($cu, $contact->agency_id, $contact->id, 'password_reset_by_agent', $request, [
            'agent_user_id' => auth()->id(),
        ]);

        return back()->with('client_login_success', 'Password reset. Client must change it on next sign-in.');
    }

    public function forceLogout(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('client_app.force_logout');

        if (!$contact->client_user_id) {
            return back();
        }

        /** @var ClientUser $cu */
        $cu = ClientUser::findOrFail($contact->client_user_id);
        $this->ensureOriginAgency($cu, $contact);
        $count = $cu->tokens()->count();
        $cu->tokens()->delete();

        $this->service->log($cu, $contact->agency_id, $contact->id, 'force_logout', $request, [
            'agent_user_id' => auth()->id(),
            'tokens_revoked' => $count,
        ]);

        return back()->with('client_login_success', "Revoked {$count} active device(s).");
    }

    public function remove(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('client_app.remove_access');

        if (!$contact->client_user_id) {
            return back();
        }

        /** @var ClientUser $cu */
        $cu = ClientUser::find($contact->client_user_id);

        if ($cu) {
            $this->ensureOriginAgency($cu, $contact);
        }

        $contact->forceFill(['client_user_id' => null])->save();

        if ($cu) {
            // If no other contact is linked to this ClientUser, soft-delete it.
            $stillLinked = Contact::query()
                ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
                ->where('client_user_id', $cu->id)->exists();

            if (!$stillLinked) {
                $cu->tokens()->delete();
                $cu->delete();
            }

            $this->service->log($cu, $contact->agency_id, $contact->id, 'access_removed', $request, [
                'agent_user_id' => auth()->id(),
                'client_user_soft_deleted' => !$stillLinked,
            ]);
        }

        return back()->with('client_login_success', 'Client app access removed.');
    }

    private function authorize(string $permission): void
    {
        if (!auth()->user()?->hasPermission($permission)) {
            abort(403);
        }
    }

    /**
     * Only the agency that fabricated an agency-managed login (fake @domain
     * email + agent-set password) may manage it (reset password, force logout,
     * remove access). Self-service logins (real email, client-set password via
     * OTP) are owned by the client — any linked agency may manage them and the
     * origin lock never applies. See spec: .ai/specs/client-auth.md.
     */
    private function ensureOriginAgency(ClientUser $cu, Contact $contact): void
    {
        if (!$cu->isAgencyManaged()) {
            return; // self-service login — client owns credentials, no origin lock.
        }
        $origin = $cu->created_by_agency_id;
        if ($origin === null) {
            return; // legacy row — allow (will be backfilled).
        }
        if ((int) $origin !== (int) $contact->agency_id) {
            abort(403, 'This client login is managed by another agency.');
        }
    }
}
