<?php

namespace Tests\Feature;

use App\Agents\DiscoveryAgent;
use App\Agents\VerificationAgent;
use App\Models\Document;
use App\Models\Paper;
use App\Models\PaperAnalysis;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Models\User;
use App\Services\Academic\ProjectPaperMetadata;
use App\Services\AI\AgentContext;
use App\Services\AI\SenopatiService;
use App\Services\AI\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayFixture;
use Tests\TestCase;

class PaperMetadataTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['title' => 'Judul yang ditinjau', 'authors' => "Smith, Jane\nBudi", 'publication_year' => 2025, 'doi' => 'https://doi.org/10.1234/Reviewed', 'journal_name' => 'Jurnal Pendidikan', 'source_url' => 'https://journal.example/article', 'volume' => '4', 'issue' => '2', 'pages' => '10–20'];
    }

    private function paper(ResearchProject $project): Paper
    {
        $paper = Paper::query()->create(['title' => 'Judul OpenAlex', 'doi' => '10.1234/original', 'authors' => ['Nama Asli'], 'abstract' => 'Abstrak sumber asli.', 'metadata_source' => 'openalex']);
        $project->papers()->attach($paper->id, ['is_selected' => true, 'relevance_score' => 0.9]);

        return $paper;
    }

    public function test_metadata_is_project_specific_and_preserves_evidence_and_canonical_paper(): void
    {
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $other = ResearchProject::factory()->create();
        $paper = $this->paper($project);
        $other->papers()->attach($paper->id);
        $document = Document::query()->create(['project_id' => $project->id, 'paper_id' => $paper->id, 'original_filename' => 'artikel.pdf', 'storage_path' => 'testing/metadata.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'file_hash' => hash('sha256', 'metadata'), 'extraction_status' => 'indexed']);
        $chunk = $document->chunks()->create(['chunk_index' => 0, 'content' => 'Teks sumber asli.']);
        $run = ResearchRun::query()->create(['project_id' => $project->id, 'status' => 'completed', 'workflow_state' => ['selected_paper_ids' => [$paper->id]]]);
        $analysis = PaperAnalysis::query()->create(['project_id' => $project->id, 'paper_id' => $paper->id, 'run_id' => $run->id, 'structured_analysis' => ['title' => 'Judul OpenAlex'], 'evidence_level' => 'full_text', 'analysis_model' => 'test']);

        $this->actingAs($project->user)->put(route('projects.literature.update', [$project, $paper]), [...$this->input(), 'metadata_verification_status' => 'verified', 'abstract' => 'Abstrak yang diganti'])
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.literature.index', $project));

        $effective = app(ProjectPaperMetadata::class)->apply($paper->fresh(), $project);
        $this->assertSame('Judul yang ditinjau', $effective->title);
        $this->assertSame(['Smith, Jane', 'Budi'], $effective->authors);
        $this->assertSame('10.1234/reviewed', $effective->doi);
        $this->assertSame('metadata_human_reviewed', $effective->metadata_verification_status);
        $this->assertSame('Abstrak sumber asli.', $effective->abstract);
        $this->assertSame('Judul OpenAlex', $paper->fresh()->title);
        $this->assertSame('10.1234/original', $paper->fresh()->doi);
        $this->assertSame('Judul OpenAlex', app(ProjectPaperMetadata::class)->apply($paper, $other)->title);
        $this->assertSame($paper->id, $effective->id);
        $this->assertFalse($effective->exists);
        $this->assertSame($paper->id, $document->fresh()->paper_id);
        $this->assertSame($paper->id, $analysis->fresh()->paper_id);
        $this->assertSame([$paper->id], $run->fresh()->workflow_state['selected_paper_ids']);
        $this->assertDatabaseHas('paper_chunks', ['id' => $chunk->id, 'content' => 'Teks sumber asli.']);
        $this->assertSame('paper_metadata_updated', $project->history()->firstOrFail()->action);
        $this->actingAs($project->user)->get(route('projects.literature.index', $project))->assertOk()->assertSee('Judul yang ditinjau')->assertSee('Ubah metadata');
        $this->actingAs($other->user)->get(route('projects.literature.index', $other))->assertOk()->assertSee('Judul OpenAlex')->assertDontSee('Judul yang ditinjau');
        Http::assertNothingSent();
    }

    public function test_other_users_and_papers_outside_the_project_cannot_be_edited(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = $this->paper($project);
        $this->actingAs(User::factory()->create())->get(route('projects.literature.edit', [$project, $paper]))->assertForbidden();
        $this->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertForbidden();
        $this->get(route('projects.literature.references', $project))->assertForbidden();
        $unattached = Paper::query()->create(['title' => 'Di luar proyek']);
        $this->actingAs($project->user)->get(route('projects.literature.edit', [$project, $unattached]))->assertNotFound();
        $this->put(route('projects.literature.update', [$project, $unattached]), $this->input())->assertNotFound();
        $this->assertNull($project->projectPapers()->firstOrFail()->metadata_overrides);
    }

    public function test_active_workflow_freezes_metadata_including_checkpoints_and_failure(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = $this->paper($project);
        $run = ResearchRun::query()->create(['project_id' => $project->id]);
        foreach (['pending', 'analyzing', 'awaiting_paper_selection', 'awaiting_approval', 'failed'] as $status) {
            $run->update(['status' => $status]);
            $this->actingAs($project->user)->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertSessionHasErrors('workflow');
            $this->assertNull($project->projectPapers()->firstOrFail()->metadata_overrides);
        }
        $this->get(route('projects.literature.edit', [$project, $paper]))->assertOk()->assertSee('Selesaikan atau batalkan');
        $run->update(['status' => 'cancelled']);
        $this->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertSessionHasNoErrors();
    }

    public function test_bibliographic_inputs_reject_unsafe_urls_dois_and_excessive_authors(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = $this->paper($project);
        $this->actingAs($project->user);
        foreach (['javascript:alert(1)', 'https://user:secret@journal.example/article', 'file:///private/article.pdf'] as $url) {
            $this->put(route('projects.literature.update', [$project, $paper]), [...$this->input(), 'source_url' => $url])->assertSessionHasErrors('source_url');
        }
        $this->put(route('projects.literature.update', [$project, $paper]), [...$this->input(), 'doi' => '10.invalid/doi', 'publication_year' => 1000])->assertSessionHasErrors(['doi', 'publication_year']);
        $this->put(route('projects.literature.update', [$project, $paper]), [...$this->input(), 'authors' => implode("\n", array_fill(0, 101, 'Nama Penulis'))])->assertSessionHasErrors('authors');
        $this->assertNull($project->projectPapers()->firstOrFail()->metadata_overrides);
    }

    public function test_bibliography_uses_project_metadata_and_supports_text_download(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = $this->paper($project);
        $this->actingAs($project->user)->get(route('projects.literature.edit', [$project, $paper]))->assertOk()->assertSee('Nama Asli');
        $this->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertSessionHasNoErrors();
        $this->get(route('projects.literature.references', $project))->assertOk()->assertSee('Smith, J., &amp; Budi', false)->assertSee('Judul yang ditinjau')->assertSee('10.1234/reviewed');
        $this->get(route('projects.literature.references', [$project, 'style' => 'ieee', 'download' => 1]))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('[1] J. Smith and Budi', false);
        $this->get(route('projects.literature.references', [$project, 'style' => 'invalid']))->assertSessionHasErrors('style');
    }

    public function test_discovery_and_tools_use_reviewed_metadata_but_evidence_keeps_original_abstract(): void
    {
        $project = ResearchProject::factory()->create(['year_from' => 2020, 'year_to' => 2026]);
        $paper = $this->paper($project);
        $paper->update(['publication_year' => 2010]);
        $this->actingAs($project->user)->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertSessionHasNoErrors();
        Document::query()->create(['project_id' => $project->id, 'paper_id' => $paper->id, 'original_filename' => 'artikel.pdf', 'storage_path' => 'testing/discovery.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'file_hash' => hash('sha256', 'discovery'), 'extraction_status' => 'indexed']);
        $run = ResearchRun::query()->create(['project_id' => $project->id]);
        Http::preventStrayRequests();
        Http::fake([SenopatiService::ENDPOINT => fn (Request $request) => isset($request['tools'])
            ? Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_metadata', 'type' => 'function', 'function' => ['name' => 'search_project_documents', 'arguments' => '{"query":"AI"}']]]]]]])
            : Http::response(GatewayFixture::chat(json_encode(['discovered_paper_ids' => [$paper->id], 'ranking_notes' => 'Metadata yang ditinjau.', 'missing_metadata' => []]))),
        ]);
        $context = new AgentContext($project, $run);
        $result = app(DiscoveryAgent::class)->execute($context);

        $this->assertSame([$paper->id], $result->output['discovered_paper_ids']);
        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];
            $tool = collect($messages)->firstWhere('role', 'tool');
            $last = $messages[array_key_last($messages)];

            return $tool !== null && str_contains($tool['content'], 'Judul yang ditinjau') && str_contains($last['content'], 'Judul yang ditinjau') && str_contains($last['content'], '2025');
        });
        $evidence = app(ToolRegistry::class)->verifyEvidence($context, [['paper_id' => $paper->id, 'quote' => 'Abstrak sumber asli.']]);
        $this->assertCount(1, $evidence['valid']);
        $this->assertSame([], $evidence['invalid']);
        $this->assertSame('Judul OpenAlex', $paper->fresh()->title);
    }

    public function test_apa_bibliography_orders_authors_and_keeps_edit_links_aligned(): void
    {
        $project = ResearchProject::factory()->create();
        $zulu = Paper::query()->create(['title' => 'A title', 'authors' => ['Zulu, Jane']]);
        $alpha = Paper::query()->create(['title' => 'Z title', 'authors' => ['Alpha, Jane']]);
        $project->papers()->attach([$zulu->id, $alpha->id]);

        $this->actingAs($project->user)->get(route('projects.literature.references', $project))
            ->assertOk()->assertSeeInOrder(['Alpha, J.', route('projects.literature.edit', [$project, $alpha]), 'Zulu, J.', route('projects.literature.edit', [$project, $zulu])], false)
            ->assertViewHas('papers', fn ($papers): bool => $papers->pluck('id')->all() === [$alpha->id, $zulu->id]);
        $this->get(route('projects.literature.references', [$project, 'style' => 'ieee']))
            ->assertOk()->assertViewHas('papers', fn ($papers): bool => $papers->pluck('id')->all() === [$zulu->id, $alpha->id]);
        $this->get(route('projects.literature.references', [$project, 'download' => 1]))->assertOk()->assertSeeInOrder(['Alpha, J.', 'Zulu, J.'], false);
    }

    public function test_external_metadata_verification_uses_reviewed_doi_and_title(): void
    {
        $project = ResearchProject::factory()->create(['research_mode' => 'online']);
        $paper = $this->paper($project);
        $this->actingAs($project->user)->put(route('projects.literature.update', [$project, $paper]), $this->input())->assertSessionHasNoErrors();
        $run = ResearchRun::query()->create(['project_id' => $project->id]);
        Http::preventStrayRequests();
        Http::fake([
            SenopatiService::ENDPOINT => Http::response(GatewayFixture::chat(json_encode(['checks' => [], 'revision_requested' => false, 'feedback' => 'Tidak ada klaim.']))),
            'https://api.crossref.org/works/10.1234%2Freviewed' => Http::response(['message' => ['DOI' => '10.1234/reviewed', 'title' => ['Judul yang ditinjau']]]),
        ]);

        $result = app(VerificationAgent::class)->execute(new AgentContext($project, $run));

        $this->assertSame([['paper_id' => $paper->id, 'status' => 'verified']], $result->output['metadata_checks']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.crossref.org/works/10.1234%2Freviewed');
        $this->assertSame('10.1234/original', $paper->fresh()->doi);
        $this->assertSame('metadata_unverified', $paper->fresh()->metadata_verification_status);
    }
}
