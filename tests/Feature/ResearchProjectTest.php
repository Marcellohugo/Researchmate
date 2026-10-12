<?php

namespace Tests\Feature;

use App\Models\Paper;
use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResearchProjectTest extends TestCase
{
    use DatabaseMigrations;

    /** @return array<string, mixed> */
    private function projectInput(): array
    {
        return [
            'title' => 'AI dan berpikir kritis',
            'research_question' => 'Bagaimana AI memengaruhi berpikir kritis mahasiswa?',
            'topic' => 'Pendidikan tinggi',
            'description' => 'Kajian berdasarkan bukti ilmiah.',
            'objectives' => 'Membandingkan temuan penelitian.',
            'keywords' => 'AI generatif, berpikir kritis',
            'discipline' => 'Pendidikan',
            'language' => 'id',
            'research_mode' => 'offline',
            'year_from' => 2020,
            'year_to' => 2026,
            'target_papers' => 20,
        ];
    }

    public function test_user_can_create_project_with_transactional_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('projects.store'), [
            ...$this->projectInput(),
            'user_id' => $other->id,
            'status' => 'completed',
        ]);

        $project = ResearchProject::firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $project));
        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame('draft', $project->status);
        $this->assertSame('created', $project->history()->firstOrFail()->action);
        $this->assertSame('AI dan berpikir kritis', $project->history()->firstOrFail()->payload['title']);
    }

    public function test_project_routes_require_authentication(): void
    {
        $project = ResearchProject::factory()->create();

        foreach (['projects.index', 'projects.create', 'projects.show', 'projects.edit'] as $route) {
            $this->get(route($route, $project))->assertRedirect(route('login'));
        }
    }

    public function test_user_cannot_read_or_modify_another_users_project(): void
    {
        $project = ResearchProject::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.show', $project))->assertForbidden();
        $this->get(route('projects.edit', $project))->assertForbidden();
        $this->put(route('projects.update', $project), $this->projectInput())->assertForbidden();
        $this->delete(route('projects.destroy', $project))->assertForbidden();
        $this->post(route('projects.duplicate', $project))->assertForbidden();

        $this->assertDatabaseHas('research_projects', ['id' => $project->id, 'title' => $project->title]);
    }

    public function test_index_only_shows_owned_projects(): void
    {
        $owner = User::factory()->create();
        $owned = ResearchProject::factory()->create(['user_id' => $owner->id, 'title' => 'Proyek milik saya']);
        $other = ResearchProject::factory()->create(['title' => 'Proyek pengguna lain']);

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()->assertSee($owned->title)->assertDontSee($other->title);
    }

    public function test_year_range_and_paper_limit_are_validated_in_indonesian(): void
    {
        $this->actingAs(User::factory()->create())->post(route('projects.store'), [
            ...$this->projectInput(),
            'year_from' => 2025,
            'year_to' => 2024,
            'target_papers' => config('ai.max_papers', 20) + 1,
            'research_mode' => 'invalid',
        ])->assertSessionHasErrors([
            'year_to' => 'Tahun akhir tidak boleh lebih kecil dari tahun awal.',
            'target_papers' => 'target artikel tidak boleh lebih dari '.config('ai.max_papers', 20).'.',
            'research_mode' => 'Pilihan mode riset tidak valid.',
        ]);

        $this->assertDatabaseCount('research_projects', 0);
    }

    public function test_search_matches_question_and_remains_scoped_to_current_user(): void
    {
        $owner = User::factory()->create();
        $matched = ResearchProject::factory()->create(['user_id' => $owner->id, 'title' => 'Proyek relevan', 'research_question' => 'Bagaimana pengaruh pembelajaran mesin?']);
        $unmatched = ResearchProject::factory()->create(['user_id' => $owner->id, 'title' => 'Proyek tidak relevan', 'research_question' => 'Bagaimana kualitas udara?']);
        $foreign = ResearchProject::factory()->create(['title' => 'Proyek rahasia', 'research_question' => 'Pembelajaran mesin untuk kedokteran']);

        $this->actingAs($owner)->get(route('projects.index', ['search' => 'pembelajaran mesin']))
            ->assertOk()->assertSee($matched->title)->assertDontSee($unmatched->title)->assertDontSee($foreign->title);

        $this->get(route('projects.index', ['search' => ['invalid']]))->assertSessionHasErrors('search');
    }

    public function test_owner_can_update_project_and_history_records_old_values(): void
    {
        $project = ResearchProject::factory()->create();
        $previousTitle = $project->title;

        $this->actingAs($project->user)->put(route('projects.update', $project), $this->projectInput())
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $project));

        $this->assertSame('AI dan berpikir kritis', $project->refresh()->title);
        $history = $project->history()->firstOrFail();
        $this->assertSame('updated', $history->action);
        $this->assertSame($previousTitle, $history->payload['before']['title']);
        $this->assertSame('AI dan berpikir kritis', $history->payload['after']['title']);
    }

    public function test_duplication_copies_settings_without_papers_or_results(): void
    {
        $project = ResearchProject::factory()->create(['status' => 'completed', 'settings' => ['chunk_size' => 1200]]);
        $paper = Paper::create(['title' => 'Dokumen contoh']);
        $project->papers()->attach($paper->id, ['is_selected' => true]);
        $project->runs()->create(['status' => 'completed']);

        $this->actingAs($project->user)->post(route('projects.duplicate', $project))->assertRedirect();

        $copy = ResearchProject::where('id', '!=', $project->id)->firstOrFail();
        $this->assertSame($project->user_id, $copy->user_id);
        $this->assertSame($project->settings, $copy->settings);
        $this->assertSame($project->research_question, $copy->research_question);
        $this->assertSame('draft', $copy->status);
        $this->assertSame(0, $copy->papers()->count());
        $this->assertSame(0, $copy->runs()->count());
        $this->assertSame('duplicated', $copy->history()->firstOrFail()->action);
        $this->assertSame(1, $project->papers()->count());
    }

    public function test_project_deletion_cascades_data_and_private_files_but_preserves_global_papers(): void
    {
        Storage::fake('local');
        $project = ResearchProject::factory()->create();
        $paper = Paper::create(['title' => 'Dokumen contoh']);
        $project->papers()->attach($paper->id);
        $document = $project->documents()->create([
            'paper_id' => $paper->id,
            'original_filename' => 'paper.pdf',
            'storage_path' => 'projects/'.$project->id.'/paper.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'file_hash' => str_repeat('a', 64),
        ]);
        $chunk = $document->chunks()->create(['chunk_index' => 0, 'content' => 'Bukti penelitian.']);
        $run = $project->runs()->create();
        $step = $run->steps()->create(['agent_name' => 'planner', 'step_name' => 'planning']);
        $artifact = $project->artifacts()->create(['run_id' => $run->id, 'artifact_type' => 'report', 'storage_path' => 'reports/report.md']);
        Storage::disk('local')->put($document->storage_path, 'local PDF');
        Storage::disk('local')->put($artifact->storage_path, 'local report');

        $this->actingAs($project->user)->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index'));

        $this->assertModelMissing($project);
        $this->assertModelMissing($document);
        $this->assertModelMissing($chunk);
        $this->assertModelMissing($run);
        $this->assertModelMissing($step);
        $this->assertModelMissing($artifact);
        $this->assertModelExists($paper);
        Storage::disk('local')->assertMissing($document->storage_path);
        Storage::disk('local')->assertMissing($artifact->storage_path);
    }

    public function test_schema_supports_json_casts_and_normalized_doi(): void
    {
        $project = ResearchProject::factory()->create(['settings' => ['mode' => 'offline']]);
        $paper = Paper::create(['title' => 'Dokumen', 'doi' => ' HTTPS://DOI.ORG/10.1234/ABC ', 'authors' => [['name' => 'Peneliti']]]);
        $run = $project->runs()->create(['research_plan' => ['main_keywords' => ['AI']]]);

        $this->assertSame('10.1234/abc', $paper->refresh()->doi);
        $this->assertSame([['name' => 'Peneliti']], $paper->authors);
        $this->assertSame(['mode' => 'offline'], $project->refresh()->settings);
        $this->assertSame(['main_keywords' => ['AI']], $run->refresh()->research_plan);
        $this->assertInstanceOf(HasMany::class, $project->documents());
    }
}
