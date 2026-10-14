<?php

namespace Tests\Feature;

use App\Models\Paper;
use App\Models\PaperAnalysis;
use App\Models\ResearchProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RunAnalysisMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_keeps_unmatched_legacy_analysis_and_rebuilds_each_checkpoint(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel historis', 'metadata_source' => 'test_fixture']);
        $project->papers()->attach($paper->id);
        $firstRun = $project->runs()->create([
            'status' => 'completed',
            'current_stage' => 'awaiting_approval',
            'workflow_state' => ['analyzing' => ['analyses' => [['paper_id' => $paper->id, 'title' => 'Hasil putaran pertama', 'source_access_level' => 'abstract_only']]]],
        ]);
        $secondRun = $project->runs()->create([
            'status' => 'completed',
            'current_stage' => 'awaiting_approval',
            'workflow_state' => ['analyzing' => ['analyses' => [['paper_id' => $paper->id, 'title' => 'Hasil putaran kedua', 'source_access_level' => 'full_text']]]],
        ]);
        $partialRun = $project->runs()->create(['status' => 'failed', 'current_stage' => 'analyzing', 'workflow_state' => []]);
        $partialRun->steps()->create(['agent_name' => 'analysis', 'step_name' => 'analysis:paper:'.$paper->id, 'status' => 'completed',
            'output_payload' => ['paper_id' => $paper->id, 'title' => 'Checkpoint parsial', 'source_access_level' => 'full_text']]);

        $migration = require base_path('database/migrations/2026_10_14_000001_add_run_id_to_paper_analyses.php');
        $migration->down();
        DB::table('paper_analyses')->insert([
            'project_id' => $project->id,
            'paper_id' => $paper->id,
            'structured_analysis' => json_encode(['title' => 'Analisis lama tanpa checkpoint'], JSON_THROW_ON_ERROR),
            'evidence_level' => 'needs_human_review',
            'analysis_model' => 'legacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $rows = DB::table('paper_analyses')->where('project_id', $project->id)->orderBy('run_id')->get();
        $this->assertCount(4, $rows);
        $this->assertCount(1, $rows->whereNull('run_id'));
        $this->assertSame(1, $rows->where('run_id', $firstRun->id)->count());
        $this->assertSame(1, $rows->where('run_id', $secondRun->id)->count());
        $this->assertSame('Hasil putaran pertama', json_decode($rows->firstWhere('run_id', $firstRun->id)->structured_analysis, true)['title']);
        $this->assertSame('Hasil putaran kedua', json_decode($rows->firstWhere('run_id', $secondRun->id)->structured_analysis, true)['title']);
        $this->assertSame('Checkpoint parsial', json_decode($rows->firstWhere('run_id', $partialRun->id)->structured_analysis, true)['title']);
        $this->assertSame('needs_human_review', $rows->firstWhere('run_id', $firstRun->id)->evidence_level);
        try {
            $migration->down();
            $this->fail('Rollback tidak boleh menghapus riwayat beberapa run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback ditolak', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('paper_analyses', 'run_id'));
            $this->assertDatabaseCount('paper_analyses', 4);
        }
    }

    public function test_matrix_uses_the_latest_analysis_when_the_same_paper_was_analyzed_twice(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel riset', 'metadata_source' => 'test_fixture']);
        $project->papers()->attach($paper->id);
        $firstRun = $project->runs()->create(['status' => 'completed', 'current_stage' => 'awaiting_approval', 'workflow_state' => []]);
        $secondRun = $project->runs()->create(['status' => 'awaiting_approval', 'current_stage' => 'awaiting_approval', 'workflow_state' => []]);
        PaperAnalysis::query()->create([
            'project_id' => $project->id,
            'paper_id' => $paper->id,
            'run_id' => $firstRun->id,
            'structured_analysis' => ['title' => 'Analisis lama'],
            'evidence_level' => 'abstract_only',
            'analysis_model' => 'test',
        ]);
        PaperAnalysis::query()->create([
            'project_id' => $project->id,
            'paper_id' => $paper->id,
            'run_id' => $secondRun->id,
            'structured_analysis' => ['title' => 'Analisis terbaru'],
            'evidence_level' => 'full_text',
            'analysis_model' => 'test',
        ]);

        $this->actingAs($project->user)
            ->get(route('projects.matrix', $project))
            ->assertOk()
            ->assertSee('Artikel riset')
            ->assertDontSee('Analisis lama')
            ->assertSee('Analisis terbaru');
    }
}
