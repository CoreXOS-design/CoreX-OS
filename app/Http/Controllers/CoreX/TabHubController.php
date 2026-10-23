<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Support\Navigation\TabHubs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/real-estate-menu-tabs.md - a tabbed menu item (Market intelligence, Properties, Buyers, Outreach, Presentations). The tabs
 * are existing screens on their own routes with their own access checks; this entry sends the user to the tab they asked for (?tab=) or,
 * failing that, the first tab they may open. No tab open to them = 403, same as the screens themselves.
 */
class TabHubController extends Controller
{
    public function show(Request $request, string $hub): RedirectResponse
    {
        $class = TabHubs::find($hub);
        abort_if($class === null, 404);
        $url = $class::landingUrl($request->user(), $request->query('tab'));
        abort_if($url === null, 403, 'You don\'t have access to this resource.');

        return redirect()->to($url);
    }
}
