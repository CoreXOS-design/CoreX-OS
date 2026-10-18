<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\Rentals\PortalAccessException;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lease;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-portal-access.md §16 — the lease screen's "Tenant portal access" / "Landlord portal access"
 * cards. Three actions on one of THIS lease's own tenants or landlords: set up access (create the login, or attach
 * the person to the one they already have), email the link (setting access up first when needed — one obvious
 * action), and switch the login to the contact's current email. Every action is scoped through the lease first
 * (own / branch / agency), and the contact must be a party to that lease — a direct URL for any other contact is a 404.
 */
class LeasePortalAccessController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalPortalAccessService $access)
    {
    }

    public function setup(Request $request, Lease $lease, int $contact): RedirectResponse
    {
        return $this->run($request, $lease, $contact, function (Contact $c) use ($request) {
            return $this->access->attach($c, null, $request->user(), $request)['message'];
        });
    }

    public function invite(Request $request, Lease $lease, int $contact): RedirectResponse
    {
        return $this->run($request, $lease, $contact, function (Contact $c) use ($request, $lease) {
            $roles = $this->access->rolesOnLease($lease, $c);

            return $this->access->sendInvite($c, $roles[0] ?? RentalPortalAccessService::ROLE_TENANT, $request->user(), $lease, $request);
        });
    }

    public function switchEmail(Request $request, Lease $lease, int $contact): RedirectResponse
    {
        return $this->run($request, $lease, $contact, function (Contact $c) use ($request) {
            return $this->access->switchToContactEmail($c, $request->user(), $request)['message'];
        });
    }

    private function run(Request $request, Lease $lease, int $contactId, callable $action): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        abort_unless($request->user()->hasPermission('client_app.create_login'), 403);

        $lease->loadMissing(['tenants.contact', 'property']);
        $contact = $lease->tenantContacts()->concat($lease->landlordContacts())->firstWhere('id', $contactId);
        abort_if($contact === null, 404);

        $flash = ['contact_id' => $contact->id];
        try {
            $flash += ['type' => 'ok', 'message' => $action($contact)];
        } catch (PortalAccessException $e) {
            $flash += ['type' => 'error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);
            $flash += ['type' => 'error', 'message' => 'Something went wrong and nothing was changed. Please try again, or tell support if it keeps happening.'];
        }

        return redirect()->to(route('corex.leases.show', $lease) . '#portal-access-' . $contact->id)->with('portal_access_flash', $flash);
    }
}
