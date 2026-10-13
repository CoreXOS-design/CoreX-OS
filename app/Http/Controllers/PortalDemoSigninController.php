<?php

namespace App\Http\Controllers;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\PortalDemoSignin;
use App\Services\ClientAuthService;
use App\Support\PortalDemoSigninGate;
use App\Support\PortalLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * QA DEMO SIGN-IN (never on Staging/live) - .ai/specs/rental-portal-access.md "QA demo sign-in". Opening the link signs THIS browser's portal
 * session in as the link's contact, on the link's side, with no emailed code, and lands on the portal. Only the portal login (the
 * `client-web` guard) is touched: a staff login in the same browser session stays signed in. Every use is logged.
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

        Auth::guard('client-web')->login($login);
        $request->session()->regenerate();

        $record->forceFill(['last_used_at' => now(), 'use_count' => (int) $record->use_count + 1])->save();
        app(ClientAuthService::class)->log($login, (int) $contact->agency_id, (int) $contact->id, 'demo_signin', $request, ['role' => $record->role, 'demo_signin_id' => $record->id]);

        $view = $record->role === 'owner' ? PortalLink::VIEW_OWNER : PortalLink::VIEW_TENANT;

        return redirect()->to(PortalLink::personal((string) $login->email, $view, [], (int) $contact->id));
    }
}
