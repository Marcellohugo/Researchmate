<?php

namespace Tests\Feature;

use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_project_page_is_connected_and_requires_ownership(): void
    {
        $owner = User::factory()->create();
        $project = ResearchProject::factory()->for($owner)->create(['title' => 'Penelitian sumber nyata']);
        $this->actingAs($owner);
        foreach (['show', 'edit', 'literature.index', 'documents.index', 'matrix', 'gaps', 'agents', 'chat.index', 'reports'] as $page) {
            $this->get(route('projects.'.$page, $project))->assertOk()->assertSee('ResearchMate AI');
        }
        $this->getJson(route('api.projects.status', $project))->assertOk()->assertJsonPath('project.id', $project->id)->assertJsonPath('run', null);
        $this->actingAs(User::factory()->create());
        foreach (['show', 'edit', 'literature.index', 'documents.index', 'matrix', 'gaps', 'agents', 'chat.index', 'reports'] as $page) {
            $this->get(route('projects.'.$page, $project))->assertForbidden();
        }
        $this->getJson(route('api.projects.status', $project))->assertForbidden();
    }

    public function test_dashboard_counts_and_projects_are_scoped_to_current_user(): void
    {
        $owner = User::factory()->create();
        ResearchProject::factory()->for($owner)->create(['title' => 'Proyek milik saya']);
        ResearchProject::factory()->count(2)->create(['title' => 'Proyek pengguna lain']);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Proyek milik saya')->assertDontSee('Proyek pengguna lain')->assertViewHas('stats', fn ($stats) => $stats['Proyek riset'] === 1);
    }
}
