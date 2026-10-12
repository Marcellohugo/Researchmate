<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('research_question');
            $table->string('topic');
            $table->text('description')->nullable();
            $table->text('objectives')->nullable();
            $table->text('keywords')->nullable();
            $table->string('discipline')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('language', 10)->default('id');
            $table->string('research_mode', 20)->default('offline');
            $table->unsignedSmallInteger('target_papers')->default(20);
            $table->jsonb('settings')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('papers', function (Blueprint $table) {
            $table->id();
            $table->string('doi')->nullable()->unique();
            $table->text('title');
            $table->text('abstract')->nullable();
            $table->jsonb('authors')->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable()->index();
            $table->string('journal_name')->nullable();
            $table->text('source_url')->nullable();
            $table->string('openalex_id')->nullable()->unique();
            $table->string('metadata_source')->default('upload');
            $table->string('metadata_verification_status')->default('metadata_unverified');
            $table->timestamps();
        });

        Schema::create('project_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $table->decimal('relevance_score', 8, 5)->nullable();
            $table->boolean('is_selected')->default(false);
            $table->string('analysis_status')->default('pending');
            $table->timestamps();
            $table->unique(['project_id', 'paper_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('paper_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('extraction_status')->default('pending')->index();
            $table->unsignedInteger('page_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'file_hash']);
        });

        Schema::create('paper_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_name')->nullable();
            $table->longText('content');
            $table->text('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'chunk_index']);
        });

        if (DB::getDriverName() === 'pgsql' && DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'") !== null) {
            DB::statement('ALTER TABLE paper_chunks ALTER COLUMN embedding TYPE vector USING embedding::vector');
        }

        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('current_stage')->default('pending');
            $table->jsonb('research_plan')->nullable();
            $table->jsonb('workflow_state')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('research_runs')->cascadeOnDelete();
            $table->string('agent_name');
            $table->string('step_name');
            $table->string('status')->default('pending');
            $table->jsonb('input_payload')->nullable();
            $table->jsonb('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'agent_name', 'step_name']);
        });

        Schema::create('paper_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $table->jsonb('structured_analysis');
            $table->string('evidence_level');
            $table->string('analysis_model');
            $table->timestamps();
            $table->unique(['project_id', 'paper_id']);
        });

        Schema::create('research_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('research_runs')->cascadeOnDelete();
            $table->string('title');
            $table->string('category');
            $table->text('description');
            $table->text('rationale')->nullable();
            $table->text('proposed_question')->nullable();
            $table->text('suggested_methodology')->nullable();
            $table->jsonb('supporting_evidence')->nullable();
            $table->string('review_status')->default('needs_human_review');
            $table->timestamps();
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('research_runs')->cascadeOnDelete();
            $table->text('claim_text');
            $table->string('verification_status')->default('insufficient_evidence');
            $table->timestamps();
        });

        Schema::create('claim_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chunk_id')->nullable()->constrained('paper_chunks')->nullOnDelete();
            $table->text('evidence_text');
            $table->string('support_type');
            $table->timestamps();
        });

        Schema::create('research_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('research_runs')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('artifact_type');
            $table->string('storage_path');
            $table->timestamps();
        });

        Schema::create('research_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->jsonb('source_references')->nullable();
            $table->string('status')->default('pending');
            $table->string('evidence_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('project_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['project_history', 'research_messages', 'research_artifacts', 'claim_evidence', 'claims', 'research_gaps', 'paper_analyses', 'agent_steps', 'research_runs', 'paper_chunks', 'documents', 'project_papers', 'papers', 'research_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
