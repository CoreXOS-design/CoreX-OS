<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public self-registration is CLOSED by design (routes/auth.php): a self-registered "agent" would have no
 * agency, so it could end up with unscoped, cross-agency data. Users are created by an agency admin or an
 * invitation. These tests used to assert the Breeze default (open registration); they now pin the closure.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_public_registration_screen(): void
    {
        $this->get('/register')->assertStatus(404);
    }

    public function test_a_visitor_cannot_register_themselves(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'test@example.com')->count(), 'no account may be created through /register');
    }
}
