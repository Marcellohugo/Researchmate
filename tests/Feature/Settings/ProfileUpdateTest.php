<?php

namespace Tests\Feature\Settings;

use App\Jobs\PruneVectorIndex;
use App\Models\Document;
use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_uppercase_profile_email_is_normalized_and_can_log_in(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'PENELITI@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('peneliti@example.com', $user->refresh()->email);
        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => 'PENELITI@example.com',
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_profile_email_cannot_duplicate_another_user_with_different_case(): void
    {
        $other = User::factory()->create(['email' => 'peneliti@example.com']);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'PENELITI@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertSame('peneliti@example.com', $other->refresh()->email);
        $this->assertNotSame($other->email, $user->refresh()->email);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_account_deletion_removes_only_its_private_files_and_queues_vector_cleanup(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();
        $project = ResearchProject::factory()->for($user)->create();
        $secondProject = ResearchProject::factory()->for($user)->create();
        $other = ResearchProject::factory()->create();
        foreach ([$project, $other] as $item) {
            $path = 'projects/'.$item->id.'/documents/test.pdf';
            Storage::disk('local')->put($path, 'test');
            Document::query()->create(['project_id' => $item->id, 'original_filename' => 'test.pdf', 'storage_path' => $path,
                'mime_type' => 'application/pdf', 'file_size' => 4, 'file_hash' => hash('sha256', 'test')]);
        }
        $this->actingAs($user);
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();
        Storage::disk('local')->assertMissing('projects/'.$project->id.'/documents/test.pdf');
        Storage::disk('local')->assertExists('projects/'.$other->id.'/documents/test.pdf');
        $this->assertNull($user->fresh());
        $this->assertDatabaseHas('documents', ['project_id' => $other->id]);
        foreach ([$project, $secondProject] as $deletedProject) {
            Queue::assertPushed(PruneVectorIndex::class, fn ($job) => $job->projectId === $deletedProject->id && $job->documentId === null);
        }
        Queue::assertPushed(PruneVectorIndex::class, 2);
    }
}
