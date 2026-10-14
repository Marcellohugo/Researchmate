<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_analyses', function (Blueprint $table): void {
            $table->dropUnique('paper_analyses_project_id_paper_id_unique');
            $table->foreignId('run_id')->nullable()->after('paper_id')->constrained('research_runs')->cascadeOnDelete();
            $table->unique(['project_id', 'paper_id', 'run_id']);
        });

        // Preserve legacy rows without guessing their run; rebuild history from saved checkpoints.
        foreach (DB::table('research_runs')->orderBy('id')->get() as $run) {
            $state = json_decode($run->workflow_state ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $analyses = $state['analyzing']['analyses'] ?? [];
            if ($analyses === []) {
                $analyses = DB::table('agent_steps')->where('run_id', $run->id)->where('agent_name', 'analysis')
                    ->where('step_name', 'like', 'analysis:paper:%')->where('status', 'completed')->pluck('output_payload')
                    ->map(fn ($payload) => json_decode($payload, true, 512, JSON_THROW_ON_ERROR))->all();
            }
            foreach ($analyses as $analysis) {
                $paperId = $analysis['paper_id'] ?? null;
                if ($paperId === null || ! DB::table('project_papers')->where('project_id', $run->project_id)->where('paper_id', $paperId)->exists()) {
                    continue;
                }
                $legacy = DB::table('paper_analyses')->where('project_id', $run->project_id)->where('paper_id', $paperId)->whereNull('run_id')->first();
                if ($legacy !== null && $legacy->evidence_level === 'needs_human_review') {
                    $analysis['analysis_status'] = 'needs_human_review';
                }
                DB::table('paper_analyses')->updateOrInsert(['project_id' => $run->project_id, 'paper_id' => $paperId, 'run_id' => $run->id], [
                    'structured_analysis' => json_encode($analysis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'evidence_level' => ($analysis['analysis_status'] ?? '') === 'needs_human_review' ? 'needs_human_review' : ($analysis['source_access_level'] ?? 'abstract_only'),
                    'analysis_model' => $legacy->analysis_model ?? 'historical_checkpoint',
                    'created_at' => $run->created_at, 'updated_at' => $run->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('paper_analyses')->select('project_id', 'paper_id')->groupBy('project_id', 'paper_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback ditolak karena analisis beberapa run harus tetap dipertahankan.');
        }
        Schema::table('paper_analyses', function (Blueprint $table): void {
            $table->dropUnique('paper_analyses_project_id_paper_id_run_id_unique');
            $table->dropForeign(['run_id']);
            $table->dropColumn('run_id');
            $table->unique(['project_id', 'paper_id']);
        });
    }
};
