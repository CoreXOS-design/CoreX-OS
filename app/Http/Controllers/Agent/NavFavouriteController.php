<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\UserNavFavourite;
use App\Services\Navigation\NavFavouriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sidebar Favourites — the single save for My Profile → Favourites.
 * Spec: .ai/specs/sidebar-favourites.md §6.3, §7.
 *
 * Scoping is structural: no route in this feature accepts a favourite id or a
 * user id, and this action only ever reads and writes Auth::user()'s own rows.
 * There is nothing in the payload for a crafted request to substitute.
 */
class NavFavouriteController extends Controller
{
    public function __construct(private readonly NavFavouriteService $favourites)
    {
    }

    public function update(Request $request): RedirectResponse
    {
        $max = UserNavFavourite::MAX_PER_USER;

        $validated = $request->validate([
            'favourites' => ['nullable', 'array', 'max:'.$max],
            'favourites.*.key' => ['nullable', 'string', 'max:255'],
            'favourites.*.label' => ['nullable', 'string', 'max:255'],
        ], [
            'favourites.max' => "You can keep up to {$max} favourites — please untick a few.",
        ]);

        $user = $request->user();

        // $request->has() guard, not a bare boolean(): the picker always renders
        // this control, but a partial post must never silently flip a setting
        // the form did not show (CLAUDE.md #10a §6.1).
        if ($request->has('auto_open')) {
            $user->nav_favourites_autoopen = $request->boolean('auto_open');
            $user->save();
        }

        $result = $this->favourites->sync($user, $validated['favourites'] ?? []);

        $message = $result['saved'] === 0
            ? 'Favourites cleared.'
            : 'Favourites saved.';

        if ($result['rejected'] > 0) {
            $message .= $result['rejected'] === 1
                ? ' One page you chose isn\'t available to you any more, so it was left out.'
                : ' '.$result['rejected'].' pages you chose aren\'t available to you any more, so they were left out.';
        }

        return redirect()
            ->route('agent.portal')
            ->withFragment('favourites')
            ->with('success', $message);
    }
}
