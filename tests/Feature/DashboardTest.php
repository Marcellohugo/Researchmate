<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee($user->name)
            ->assertSee('ResearchMate AI')
            ->assertSee('Belum diperiksa')
            ->assertSee(config('ai.chat_model'))
            ->assertSee('Proyek baru')
            ->assertDontSee('href="https://github.com/laravel', false);
    }

    public function test_settings_routes_require_authentication(): void
    {
        foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_dashboard_displays_only_the_current_users_identity(): void
    {
        $owner = User::factory()->create(['name' => 'Peneliti Satu']);
        $other = User::factory()->create(['name' => 'Peneliti Dua']);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($owner->name)
            ->assertDontSee($other->name)
            ->assertDontSee($other->email);
    }
}
