<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfflineAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_the_password_without_network_access_in_production(): void
    {
        $this->app->instance('env', 'production');
        Http::preventStrayRequests();
        Http::fake();

        $this->withSession(['_token' => 'offline-test'])->post(route('register.store'), [
            '_token' => 'offline-test',
            'name' => 'Peneliti Lokal',
            'email' => 'lokal@example.com',
            'password' => 'FixtureOnly!7319',
            'password_confirmation' => 'FixtureOnly!7319',
        ])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

        $user = User::where('email', 'lokal@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('FixtureOnly!7319', $user->password));
        $this->assertNotSame('FixtureOnly!7319', $user->password);
        Http::assertNothingSent();
    }

    public function test_production_registration_rejects_weak_passwords(): void
    {
        $this->app->instance('env', 'production');

        $this->withSession(['_token' => 'offline-test'])->post(route('register.store'), [
            '_token' => 'offline-test',
            'name' => 'Peneliti Lokal',
            'email' => 'lokal@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }
}
