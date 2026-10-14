<?php

namespace Tests\Feature;

use App\Agents\DiscoveryAgent;
use App\Jobs\RunResearchWorkflow;
use App\Models\AgentStep;
use App\Models\Claim;
use App\Models\Document;
use App\Models\Paper;
use App\Models\PaperAnalysis;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use App\Models\ResearchGap;
use App\Models\ResearchRun;
use App\Models\User;
use App\Services\AI\AgentContext;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\SenopatiService;
use App\Services\AI\ToolRegistry;
use App\Services\AI\WorkflowStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\GatewayFixture;
use Tests\TestCase;

class ResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Mahasiswa menggunakan AI untuk membandingkan argumen sebelum mengambil keputusan.';

    private function source(ResearchProject $project): PaperChunk
    {
        $paper = Paper::query()->create(['title' => 'Dokumen uji alur lokal', 'abstract' => self::QUOTE, 'metadata_source' => 'test_fixture']);
        $project->papers()->attach($paper->id);
        $document = Document::query()->create(['project_id' => $project->id, 'paper_id' => $paper->id, 'original_filename' => 'uji.pdf', 'storage_path' => 'testing/uji.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'file_hash' => hash('sha256', (string) $project->id), 'extraction_status' => 'indexed', 'page_count' => 1]);

        return $document->chunks()->create(['chunk_index' => 0, 'page_number' => 1, 'content' => self::QUOTE]);
    }

    private function fakeAgents(PaperChunk $chunk, bool $unsupported = false, bool $invalidCitation = false): void
    {
        $toolTurns = 0;
        Http::preventStrayRequests();
        Http::fake([SenopatiService::ENDPOINT => function (Request $request) use ($chunk, $unsupported, $invalidCitation, &$toolTurns) {
            if (isset($request['tools'])) {
                $toolTurns++;

                return Http::response(['choices' => [['message' => $toolTurns % 2 === 1
                    ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_search', 'type' => 'function', 'function' => ['name' => 'search_project_documents', 'arguments' => '{"query":"AI mahasiswa"}']]]]
                    : ['role' => 'assistant', 'content' => 'Koleksi tersedia.']]]], 200);
            }
            $properties = $request['response_format']['json_schema']['schema']['properties'];
            $reference = ['paper_id' => $chunk->document->paper_id, 'chunk_id' => $chunk->id, 'quote' => self::QUOTE];
            if (isset($properties['recommended_paper_count'])) {
                $output = ['research_question' => 'AI dan berpikir kritis', 'research_objectives' => ['Membandingkan temuan'], 'main_keywords' => ['AI'], 'alternative_keywords' => ['generative AI'], 'search_queries' => ['AI students'], 'inclusion_criteria' => ['Mahasiswa'], 'exclusion_criteria' => ['Di luar konteks'], 'recommended_paper_count' => 1, 'planning_notes' => 'Gunakan koleksi lokal.'];
            } elseif (isset($properties['discovered_paper_ids'])) {
                $output = ['discovered_paper_ids' => [$chunk->document->paper_id], 'ranking_notes' => 'Tersedia dalam proyek.', 'missing_metadata' => ['DOI']];
            } elseif (isset($properties['source_access_level'])) {
                $output = ['title' => 'Dokumen uji', 'research_objective' => 'Membandingkan argumen', 'methodology' => null, 'research_design' => null, 'sample' => null, 'variables' => null, 'main_findings' => 'AI digunakan untuk membandingkan argumen.', 'limitations' => null, 'recommendations' => null, 'evidence_references' => [$reference], 'source_access_level' => 'full_text', 'analysis_status' => 'supported'];
            } elseif (isset($properties['synthesis'])) {
                $references = $unsupported ? [] : [$reference];
                if ($invalidCitation) {
                    $references[] = ['paper_id' => $chunk->document->paper_id, 'chunk_id' => 999999, 'quote' => self::QUOTE];
                }
                $output = ['common_topics' => ['Perbandingan argumen'], 'methodology_groups' => [], 'consistent_findings' => [], 'inconsistent_findings' => [], 'synthesis' => 'Dokumen melaporkan penggunaan AI untuk membandingkan argumen.', 'claims' => [['claim_text' => 'AI digunakan untuk membandingkan argumen.', 'evidence_references' => $references]]];
            } elseif (isset($properties['gaps'])) {
                $output = ['gaps' => [['gap_title' => 'Kandidat pengujian konteks lain', 'gap_category' => 'contextual', 'gap_description' => 'Koleksi terbatas pada konteks yang tersedia.', 'supporting_paper_ids' => [$chunk->document->paper_id], 'evidence_references' => [$reference], 'rationale' => 'Perlu studi lanjutan.', 'proposed_research_question' => 'Bagaimana hasil pada konteks lain?', 'suggested_methodology' => null, 'limitations' => 'Satu dokumen uji, bukan klaim universal.', 'review_status' => 'needs_human_review']]];
            } else {
                $output = ['checks' => [['claim_id' => Claim::query()->latest('id')->first()->id, 'status' => 'verified', 'feedback' => 'Sesuai kutipan.']], 'revision_requested' => false, 'feedback' => $unsupported ? 'Tidak ada klaim yang memerlukan revisi.' : 'Tinjau hasil; metodologi belum dilaporkan.'];
            }

            return Http::response(GatewayFixture::chat(json_encode($output)), 200);
        }]);
    }

    private function advance(ResearchRun $run): void
    {
        for ($iteration = 0; $iteration < 15; $iteration++) {
            $run->refresh();
            if (in_array($run->status, ['awaiting_paper_selection', 'awaiting_approval', 'completed'], true)) {
                return;
            }
            app(AgentOrchestrator::class)->run($run);
        }
        $this->fail('Alur tidak mencapai checkpoint dalam batas tahap.');
    }

    public function test_offline_workflow_executes_real_tool_calls_then_requires_selection_and_approval(): void
    {
        Queue::fake();
        Storage::fake('local');
        $project = ResearchProject::factory()->create();
        $chunk = $this->source($project);
        $this->fakeAgents($chunk);
        $states = app(WorkflowStateManager::class);
        $run = $states->start($project);
        $this->advance($run);
        $this->assertSame('awaiting_paper_selection', $run->fresh()->status);
        $this->assertDatabaseHas('agent_steps', ['run_id' => $run->id, 'agent_name' => 'discovery', 'status' => 'completed']);
        $this->assertSame(1, $run->fresh()->workflow_state['tool_call_count']);
        $this->assertCount(1, Http::recorded(fn (Request $request) => isset($request['tools'])));
        $run = $states->select($project, [$chunk->document->paper_id]);
        $this->advance($run);
        $this->assertSame('awaiting_approval', $run->fresh()->status);
        $this->assertDatabaseHas('claims', ['run_id' => $run->id, 'verification_status' => 'verified']);
        $this->assertDatabaseHas('papers', ['id' => $chunk->document->paper_id, 'metadata_verification_status' => 'metadata_unverified']);
        $this->assertDatabaseCount('paper_analyses', 1);
        $this->assertDatabaseCount('research_gaps', 1);
        Http::assertSent(fn (Request $request) => collect($request['messages'])->contains(fn ($message) => ($message['role'] ?? '') === 'tool'));
        Http::assertNotSent(fn (Request $request) => ! str_starts_with($request->url(), 'https://senopati.its.ac.id/llm-gateway/v1/'));
        $verification = Http::recorded(fn (Request $request) => isset($request['response_format']['json_schema']['schema']['properties']['checks']))->last()[0];
        $verificationInput = json_decode($verification['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['data'];
        $this->assertArrayNotHasKey('claims', $verificationInput['synthesis']);
        $this->assertSame(['id', 'claim_text', 'evidence'], array_keys($verificationInput['claims'][0]));
        $this->assertSame(['paper_id' => $chunk->document->paper_id, 'chunk_id' => $chunk->id, 'evidence_text' => self::QUOTE], $verificationInput['claims'][0]['evidence'][0]);
        $this->actingAs($project->user)->post(route('projects.reports.export', $project))->assertRedirect();
        $draft = Storage::disk('local')->get($project->artifacts()->first()->storage_path);
        $this->assertStringContainsString('Draf; menunggu persetujuan pengguna.', $draft);
        $this->assertStringContainsString('Membandingkan temuan', $draft);
        $this->assertStringContainsString('Status verifikasi metadata: metadata_unverified', $draft);
        $this->assertStringContainsString('metodologi belum dilaporkan', $draft);
        $this->assertStringContainsString('paling banyak 12 cuplikan awal', $draft);
        $this->assertStringContainsString('bukan jaminan kebenaran ilmiah', $draft);
        $this->post(route('projects.workflow.approve', $project), ['approval' => '1'])->assertRedirect(route('projects.reports', $project));
        $this->assertSame('completed', $run->fresh()->status);
        $this->post(route('projects.reports.export', $project))->assertRedirect();
        $artifact = $project->artifacts()->latest('id')->first();
        Storage::disk('local')->assertExists($artifact->storage_path);
        $this->assertStringContainsString('Disetujui oleh pengguna.', Storage::disk('local')->get($artifact->storage_path));
        $this->get(route('projects.reports.download', [$project, $artifact]))->assertOk();
        Queue::assertPushed(RunResearchWorkflow::class);
    }

    public function test_unsupported_claims_cannot_be_marked_verified_and_revision_loop_is_bounded(): void
    {
        Queue::fake();
        $project = ResearchProject::factory()->create();
        $chunk = $this->source($project);
        $this->fakeAgents($chunk, true);
        $states = app(WorkflowStateManager::class);
        $run = $states->start($project);
        $this->advance($run);
        $run = $states->select($project, [$chunk->document->paper_id]);
        app(AgentOrchestrator::class)->run($run);
        $state = $run->fresh()->workflow_state;
        $state['analyzing']['analyses'][0]['invalid_references'] = [['paper_id' => $chunk->document->paper_id, 'chunk_id' => 999999, 'quote' => 'Kutipan ditolak.']];
        $run->update(['workflow_state' => $state]);
        $this->advance($run);
        $this->assertSame('awaiting_approval', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->workflow_state['revision_count']);
        $this->assertTrue($run->fresh()->workflow_state['revision_limit_reached']);
        $this->assertDatabaseHas('claims', ['run_id' => $run->id, 'verification_status' => 'unsupported']);
        $this->assertDatabaseCount('claims', 1);
        $this->assertDatabaseCount('research_gaps', 1);
        $revision = Http::recorded(fn (Request $request) => isset($request['response_format']['json_schema']['schema']['properties']['synthesis']))->last()[0];
        $revisionInput = json_decode($revision['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['data'];
        $this->assertSame(['analyzing', 'revision_feedback'], array_keys($revisionInput));
        $this->assertStringContainsString('[unsupported] AI digunakan untuk membandingkan argumen.: Sesuai kutipan.', $revisionInput['revision_feedback']);
        $this->assertStringNotContainsString('Tidak ada klaim yang memerlukan revisi.', $revisionInput['revision_feedback']);
        $this->assertSame('Tidak ada klaim yang memerlukan revisi.', $run->fresh()->workflow_state['verifying']['model_feedback']);
        $this->assertEquals(['paper_id' => $chunk->document->paper_id, 'chunk_id' => $chunk->id, 'document_id' => $chunk->document_id, 'page_number' => 1, 'quote' => self::QUOTE], $revisionInput['analyzing']['analyses'][0]['evidence_references'][0]);
        $this->assertArrayNotHasKey('invalid_references', $revisionInput['analyzing']['analyses'][0]);
        $this->assertSame(1, $revisionInput['analyzing']['analyses'][0]['invalid_reference_count']);
        $this->assertSame('Kutipan ditolak.', $run->fresh()->workflow_state['analyzing']['analyses'][0]['invalid_references'][0]['quote']);
    }

    public function test_duplicate_start_and_foreign_paper_selection_are_rejected(): void
    {
        Queue::fake();
        $project = ResearchProject::factory()->create();
        $states = app(WorkflowStateManager::class);
        $run = $states->start($project);
        $this->actingAs($project->user)->post(route('projects.workflow.start', $project))->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('research_runs', 1);
        $other = ResearchProject::factory()->create();
        $foreign = $this->source($other);
        $run->update(['status' => 'awaiting_paper_selection']);
        $this->post(route('projects.literature.select', $project), ['paper_ids' => [$foreign->document->paper_id]])->assertSessionHasErrors('paper_ids');
        $this->actingAs(User::factory()->create())->post(route('projects.workflow.resume', $project))->assertForbidden();
    }

    public function test_fabricated_citation_remains_flagged_when_another_citation_is_valid(): void
    {
        Queue::fake();
        $project = ResearchProject::factory()->create();
        $chunk = $this->source($project);
        $this->fakeAgents($chunk, invalidCitation: true);
        $states = app(WorkflowStateManager::class);
        $run = $states->start($project);
        $this->advance($run);
        $run = $states->select($project, [$chunk->document->paper_id]);
        $this->advance($run);
        $this->assertDatabaseHas('claims', ['run_id' => $run->id, 'verification_status' => 'insufficient_evidence']);
        $this->assertDatabaseCount('claim_evidence', 1);
        $this->assertSame(2, $run->fresh()->workflow_state['revision_count']);
    }

    public function test_failed_jobs_preserve_step_and_retry_limit_stops_further_model_requests(): void
    {
        Queue::fake();
        Http::fake([SenopatiService::ENDPOINT => Http::response([], 500)]);
        $project = ResearchProject::factory()->create();
        $run = app(WorkflowStateManager::class)->start($project);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                app(AgentOrchestrator::class)->run($run);
                $this->fail('Kegagalan Senopati harus dilaporkan.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Senopati', $exception->getMessage());
            }
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(3, $run->fresh()->retry_count);
        $this->assertCount(3, $run->fresh()->workflow_state['failures']);
        $this->assertSame('planning', $run->fresh()->workflow_state['failures'][0]['stage']);
        app(AgentOrchestrator::class)->run($run);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('agent_steps', 1);
        $this->actingAs($project->user)->post(route('projects.workflow.resume', $project))->assertRedirect();
        $this->assertSame('planning', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->retry_count);
    }

    public function test_completed_step_survives_crash_before_transition_and_is_reused(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $run = app(WorkflowStateManager::class)->start($project);
        AgentStep::query()->create(['run_id' => $run->id, 'agent_name' => 'planner', 'step_name' => 'planning:0', 'status' => 'completed', 'output_payload' => ['research_question' => 'Hasil tersimpan'], 'completed_at' => now()]);
        (new RunResearchWorkflow($run->id))->handle(app(AgentOrchestrator::class));
        $this->assertSame('discovering', $run->fresh()->status);
        $this->assertSame('Hasil tersimpan', $run->fresh()->research_plan['research_question']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_steps', 1);
    }

    public function test_registry_rejects_unknown_tools_offline_network_and_cross_project_evidence(): void
    {
        $project = ResearchProject::factory()->create();
        $foreign = $this->source(ResearchProject::factory()->create());
        $run = app(WorkflowStateManager::class)->start($project);
        $context = new AgentContext($project, $run);
        $tools = app(ToolRegistry::class);
        $agent = app(DiscoveryAgent::class);
        $evidence = $tools->verifyEvidence($context, [['paper_id' => $foreign->document->paper_id, 'chunk_id' => $foreign->id, 'quote' => self::QUOTE]]);
        $this->assertSame([], $evidence['valid']);
        $this->assertCount(1, $evidence['invalid']);
        foreach (['execute_shell', 'search_openalex'] as $name) {
            try {
                $tools->execute($agent, $name, ['query' => 'AI'], $context);
                $this->fail('Alat terlarang seharusnya ditolak.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_cancelled_run_does_not_execute_models_and_approval_requires_checkpoint(): void
    {
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $states = app(WorkflowStateManager::class);
        $run = $states->start($project);
        $states->cancel($project);
        app(AgentOrchestrator::class)->run($run);
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $states->approve($project);
    }

    public function test_analysis_recovers_a_completed_paper_without_repeating_inference(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $chunk = $this->source($project);
        $paperId = $chunk->document->paper_id;
        $project->papers()->updateExistingPivot($paperId, ['is_selected' => true]);
        $run = app(WorkflowStateManager::class)->start($project);
        $run->update(['status' => 'analyzing', 'current_stage' => 'analyzing']);
        AgentStep::query()->create(['run_id' => $run->id, 'agent_name' => 'analysis', 'step_name' => 'analysis:paper:'.$paperId, 'status' => 'completed', 'output_payload' => ['paper_id' => $paperId, 'title' => 'Hasil sebelum worker berhenti', 'source_access_level' => 'full_text', 'analysis_status' => 'supported', 'evidence_references' => []], 'completed_at' => now()]);
        app(AgentOrchestrator::class)->run($run);
        $this->assertSame('synthesizing', $run->fresh()->status);
        $this->assertDatabaseCount('paper_analyses', 1);
        $this->assertDatabaseCount('agent_steps', 2);
        Http::assertNothingSent();
    }

    public function test_results_and_export_use_only_the_latest_run(): void
    {
        Storage::fake('local');
        $project = ResearchProject::factory()->create();
        $oldPaper = Paper::query()->create(['title' => 'Artikel lama', 'metadata_source' => 'test_fixture']);
        $newPaper = Paper::query()->create(['title' => 'Artikel terbaru', 'metadata_source' => 'test_fixture']);
        $project->papers()->attach([$oldPaper->id, $newPaper->id]);
        $oldRun = $project->runs()->create(['status' => 'completed', 'current_stage' => 'awaiting_approval', 'workflow_state' => []]);
        $newRun = $project->runs()->create(['status' => 'awaiting_approval', 'current_stage' => 'awaiting_approval', 'research_plan' => [], 'workflow_state' => ['synthesizing' => ['synthesis' => 'Sintesis terbaru.']]]);
        PaperAnalysis::query()->create(['project_id' => $project->id, 'paper_id' => $oldPaper->id, 'run_id' => $oldRun->id, 'structured_analysis' => ['title' => $oldPaper->title], 'evidence_level' => 'abstract_only', 'analysis_model' => 'test']);
        PaperAnalysis::query()->create(['project_id' => $project->id, 'paper_id' => $newPaper->id, 'run_id' => $newRun->id, 'structured_analysis' => ['title' => $newPaper->title], 'evidence_level' => 'abstract_only', 'analysis_model' => 'test']);
        $project->claims()->createMany([
            ['run_id' => $oldRun->id, 'claim_text' => 'Klaim lama'],
            ['run_id' => $newRun->id, 'claim_text' => 'Klaim terbaru'],
        ]);
        $project->gaps()->createMany([
            ['run_id' => $oldRun->id, 'title' => 'Celah lama', 'category' => 'contextual', 'description' => 'Lama'],
            ['run_id' => $newRun->id, 'title' => 'Celah terbaru', 'category' => 'contextual', 'description' => 'Terbaru'],
        ]);

        $this->actingAs($project->user)->get(route('projects.matrix', $project))->assertSee('Artikel terbaru')->assertDontSee('Artikel lama');
        $this->get(route('projects.gaps', $project))->assertSee('Celah terbaru')->assertDontSee('Celah lama');
        $this->get(route('projects.reports', $project))->assertSee('Klaim terbaru')->assertDontSee('Klaim lama');
        $this->post(route('projects.reports.export', $project))->assertRedirect();
        $artifact = $project->artifacts()->latest('id')->firstOrFail();
        $report = Storage::disk('local')->get($artifact->storage_path);
        $this->assertStringContainsString('Artikel terbaru', $report);
        $this->assertStringNotContainsString('Artikel lama', $report);
    }

    public function test_tool_budget_is_persistent_and_stops_further_execution(): void
    {
        config(['ai.max_tool_calls' => 1]);
        $project = ResearchProject::factory()->create();
        $run = app(WorkflowStateManager::class)->start($project);
        $context = new AgentContext($project, $run);
        $tools = app(ToolRegistry::class);
        $agent = app(DiscoveryAgent::class);
        $this->assertSame(['doi' => '10.1234/test'], $tools->execute($agent, 'normalize_doi', ['doi' => '10.1234/TEST'], $context));
        try {
            $tools->execute($agent, 'normalize_doi', ['doi' => '10.1234/other'], $context);
            $this->fail('Batas alat harus mencegah eksekusi berikutnya.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Batas eksekusi', $exception->getMessage());
        }
        $this->assertSame(1, $run->fresh()->workflow_state['tool_call_count']);
        $this->assertDatabaseCount('agent_steps', 1);
    }

    public function test_evidence_and_report_download_are_scoped_to_owner_and_project(): void
    {
        Storage::fake('local');
        $project = ResearchProject::factory()->create();
        $other = ResearchProject::factory()->create();
        $foreignChunk = $this->source($other);
        $this->actingAs($project->user)->get(route('projects.evidence', [$project, $foreignChunk]))->assertNotFound();
        $this->get(route('projects.evidence', [$other, $foreignChunk]))->assertForbidden();
        $artifact = $project->artifacts()->create(['artifact_type' => 'markdown_report', 'storage_path' => 'users/'.$other->user_id.'/projects/'.$other->id.'/reports/11111111-1111-1111-1111-111111111111.md']);
        Storage::disk('local')->put($artifact->storage_path, 'Privat');
        $this->get(route('projects.reports.download', [$project, $artifact]))->assertNotFound();
    }

    public function test_postgresql_lock_serializes_workers_and_releases_when_connection_closes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Pemeriksaan kunci sesi memerlukan PostgreSQL nyata.');
        }
        config(['database.connections.advisory_test' => config('database.connections.pgsql')]);
        $second = DB::connection('advisory_test');
        $lockId = -203070901;
        try {
            $this->assertTrue($second->selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockId])->acquired);
            $this->assertFalse(DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockId])->acquired);
            DB::purge('advisory_test');
            $this->assertTrue(DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockId])->acquired);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?)', [$lockId]);
            DB::purge('advisory_test');
        }
    }
}
