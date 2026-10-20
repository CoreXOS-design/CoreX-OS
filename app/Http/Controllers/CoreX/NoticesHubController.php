<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Support\Rentals\NoticesTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AT-466 - the Notices menu item. The tabs are the existing screens (breach register, notices received, notices sent, templates), each on its
 * own route with its own permission; this entry sends the user to the tab they asked for (?tab=breaches|received|sent|templates) or, failing
 * that, the first tab they may open. Route middleware: any one of the tab permissions.
 */
class NoticesHubController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $url = NoticesTabs::landingUrl($request->user(), $request->query('tab'));
        abort_if($url === null, 403, 'You don\'t have access to this resource.');

        return redirect()->to($url);
    }
}
