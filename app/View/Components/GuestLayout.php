<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * The card is sized for a login form — an email, a password, a button. Pages
     * that are NOT a login form (the demo T&C clickwrap is a legal document) can
     * ask for a wider card and their own heading.
     *
     * Both default to exactly what every existing caller already renders, so the
     * ten auth views that share this layout are untouched.
     *
     * @param string      $maxWidth CSS width for the card.
     * @param string|null $heading  Small centred label above the slot. NULL removes
     *                              it — for pages that render their own <h1> and
     *                              would otherwise be titled "Sign in to your
     *                              account", which they are not.
     */
    public function __construct(
        public string $maxWidth = '400px',
        public ?string $heading = 'Sign in to your account',
    ) {
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
