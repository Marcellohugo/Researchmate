<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\ResearchProject;
use App\Models\User;
use App\Services\Documents\PdfParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedResearchDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_import_is_idempotent_private_and_scoped_without_fabricated_ai_outputs(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->artisan('research:seed-data', ['user' => $owner->id])->assertSuccessful();
        $project = $owner->projects()->firstOrFail();
        $this->assertTrue($project->settings['synthetic']);
        $this->assertStringNotContainsString('Dummy', $project->title);
        $this->assertSame(0, $other->projects()->count());
        foreach ($project->documents()->with('paper')->get() as $document) {
            $pdf = Storage::disk('local')->get($document->storage_path);
            $this->assertSame(hash('sha256', $pdf), $document->file_hash);
            $pages = app(PdfParserService::class)->extract(Storage::disk('local')->path($document->storage_path));
            $this->assertCount(1, $pages);
            $this->assertStringContainsString('Keterbatasan', $pages[0]['content']);
            $this->assertStringNotContainsString('DUMMY', $pages[0]['content']);
            $this->assertSame('synthetic_fixture', $document->paper->metadata_source);
            $this->assertNull($document->paper->doi);
        }
        $this->artisan('research:seed-data', ['user' => $owner->id])->assertSuccessful();
        $this->assertDatabaseCount('research_projects', 1);
        $this->assertDatabaseCount('documents', 5);
        $this->assertDatabaseCount('papers', 5);
        $this->assertDatabaseCount('project_history', 1);
        $this->assertDatabaseCount('paper_analyses', 0);
        $this->assertDatabaseCount('research_runs', 0);
        Queue::assertPushed(ProcessDocument::class, 5);
        $this->actingAs($other)->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_unknown_user_does_not_create_any_data(): void
    {
        Queue::fake();
        $this->artisan('research:seed-data', ['user' => 999999])->assertFailed();
        $this->assertSame(0, ResearchProject::query()->count());
        Queue::assertNothingPushed();
    }
}
