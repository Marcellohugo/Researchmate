<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_endpoints_reject_non_string_email_instead_of_crashing(): void
    {
        foreach (['login.store', 'register.store', 'password.email', 'password.update'] as $route) {
            $this->postJson(route($route), [
                'name' => 'Peneliti',
                'email' => ['invalid'],
                'password' => 'password',
                'password_confirmation' => 'password',
                'token' => 'invalid',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_password_reset_rejects_non_string_token(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('password.update'), [
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'token' => ['invalid'],
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }
}
