<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => trim((string) $request->input('email'))]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // AT-423 — a sub-user (signs in with a username, shares an inbox) cannot reset their
        // own password: the link would land in a shared inbox. Only an admin resets it
        // (spec one-email-sub-users.md §6.5). Nothing is sent.
        $subUser = \App\Models\User::withoutGlobalScopes()
            ->where('email', $request->input('email'))
            ->where('is_sub_user', true)
            ->exists();
        if ($subUser) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Passwords for username sign-ins are reset by your agency admin — please ask them to reset it for you.']);
        }

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
