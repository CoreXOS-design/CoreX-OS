<?php

namespace App\Http\Controllers;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\PortalDemoSignin;
use App\Services\ClientAuthService;
use App\Services\PortalTabSession;
use App\Support\PortalDemoSigninGate;
use App\Support\PortalLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * QA DEMO SIGN-IN (never on Staging/live) - .ai/specs/rental-portal-access.md "QA demo sign-in". Opening the link signs THIS TAB in as the link's
 * contact, on the link's side, with no emailed code, and lands on the portal. The tab gets a login of its own (a token handed over in the URL
 * fragment), so the browser's session - a staff login, or another tab's person - is untouched. Every use is logged.
 */
class PortalDemoSigninController extends Controller
{
    public function open(Request $request, string $token): RedirectResponse
    {
        abort_unless(PortalDemoSigninGate::open(), 404);

        $record = PortalDemoSignin::findLive($token);
        abort_unless($record, 404);

        $contact = Contact::withoutGlobalScopes()->whereNull('deleted_at')->find($record->contact_id);
        $login = $contact && $contact->client_user_id ? ClientUser::find($contact->client_user_id) : null;
        abort_unless($contact && $login, 404);

        // Per-tab sign-in (rental-portal-access.md §28.5): the tab gets a login of its own, handed over in the URL FRAGMENT (never sent to a server
        // or written to a log) - the browser's shared session cookie is not touched, so the other demo tab (and any staff login) stays signed in.
        $tabs = app(PortalTabSession::class);
        $tabToken = $tabs->issue($login);
        $tabs->remember($request, $login);

        $record->forceFill(['last_used_at' => now(), 'use_count' => (int) $record->use_count + 1])->save();
        app(ClientAuthService::class)->log($login, (int) $contact->agency_id, (int) $contact->id, 'demo_signin', $request, ['role' => $record->role, 'demo_signin_id' => $record->id]);

        $view = $record->role === 'owner' ? PortalLink::VIEW_OWNER : PortalLink::VIEW_TENANT;

        // demo=1: this tab is a QA demo tab, fixed to this side for its whole life (portal shell + EnsurePortalRole)
        return redirect()->to(PortalLink::personal((string) $login->email, $view, [], (int) $contact->id) . '&demo=1#tt=' . rawurlencode($tabToken));
    }
}
