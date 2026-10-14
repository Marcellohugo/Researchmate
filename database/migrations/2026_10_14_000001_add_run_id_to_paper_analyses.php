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
            $table->foreignId('run_id')->nullable()->after('paper_id')->constrained('research_runs')->nullOnDelete();
            $table->unique(['project_id', 'paper_id', 'run_id']);
        });

        foreach (DB::table('paper_analyses')->whereNull('run_id')->get(['id', 'project_id']) as $analysis) {
            $runId = DB::table('research_runs')->where('project_id', $analysis->project_id)->latest('id')->value('id');
            if ($runId !== null) {
                DB::table('paper_analyses')->where('id', $analysis->id)->update(['run_id' => $runId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('paper_analyses', function (Blueprint $table): void {
            $table->dropUnique('paper_analyses_project_id_paper_id_run_id_unique');
            $table->dropForeign(['run_id']);
            $table->dropColumn('run_id');
            $table->unique(['project_id', 'paper_id']);
        });
    }
};
