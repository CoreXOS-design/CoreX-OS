<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Agent Activation Gate (.ai/specs/agent-activation-gate.md) — first_login_at
            // is the "has ever completed activation" marker. Defaulting it here (like
            // email_verified_at above) means a plain factory user represents a normal,
            // already-onboarded team member — the assumption almost every existing test
            // makes. A test representing a genuine still-pending invite must opt in via
            // pendingInvite() below, which clears both markers together.
            'first_login_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A genuine still-pending invite: never verified, never activated, never
     * logged in. Mirrors what UserManagementController::store() now creates.
     */
    public function pendingInvite(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'first_login_at'    => null,
            'is_active'         => false,
            'password'          => \App\Models\User::pendingInvitePassword(),
        ]);
    }
}
