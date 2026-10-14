<?php

namespace Tests\Feature;

use App\Jobs\AnswerResearchQuestion;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Paper;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use App\Models\User;
use App\Services\AI\SenopatiService;
use App\Services\Documents\EmbeddingService;
use App\Services\Documents\RetrievalService;
use App\Services\Documents\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\GatewayFixture;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class DocumentRagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_upload_is_private_queued_deduplicated_and_metadata_is_honest(): void
    {
        $project = ResearchProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.documents.store', $project), ['pdf' => UploadedFile::fake()->createWithContent('artikel-uji.pdf', PdfFixture::make())])->assertRedirect(route('projects.documents.index', $project));
        $document = $project->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringStartsWith('projects/'.$project->id.'/documents/', $document->storage_path);
        $this->assertSame('queued', $document->extraction_status);
        $this->assertSame('uploaded_filename', $document->paper->metadata_source);
        $this->assertSame('metadata_unverified', $document->paper->metadata_verification_status);
        Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->documentId === $document->id);
        $this->post(route('projects.documents.store', $project), ['pdf' => UploadedFile::fake()->createWithContent('different-name.pdf', PdfFixture::make())])->assertSessionHasErrors('pdf');
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_document_ownership_and_nested_project_scope_are_enforced(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project);
        $this->actingAs(User::factory()->create())->get(route('projects.documents.show', [$project, $document]))->assertForbidden();
        $this->delete(route('projects.documents.destroy', [$project, $document]))->assertForbidden();
        $otherProject = ResearchProject::factory()->create(['user_id' => $project->user_id]);
        $this->actingAs($project->user)->get(route('projects.documents.show', [$otherProject, $document]))->assertNotFound();
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_invalid_extension_content_and_size_are_rejected(): void
    {
        $project = ResearchProject::factory()->create();
        $this->actingAs($project->user);
        foreach ([UploadedFile::fake()->createWithContent('paper.txt', PdfFixture::make()), UploadedFile::fake()->createWithContent('paper.pdf', 'not a pdf'), UploadedFile::fake()->createWithContent(str_repeat('a', 256).'.pdf', PdfFixture::make()), UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf')] as $file) {
            $this->post(route('projects.documents.store', $project), ['pdf' => $file])->assertSessionHasErrors('pdf');
        }
        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_indexing_uses_real_pdf_and_failed_embedding_preserves_previous_chunks(): void
    {
        $document = $this->document(ResearchProject::factory()->create());
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]]))]);
        app(EmbeddingService::class)->process($document);
        $document->refresh();
        $this->assertSame('indexed', $document->extraction_status);
        $this->assertSame(1, $document->page_count);
        $chunk = $document->chunks()->firstOrFail();
        $this->assertSame(3, $chunk->embedding_dimensions);
        $this->assertSame(1, $chunk->page_number);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response([], 500)]);
        try {
            app(EmbeddingService::class)->process($document);
            $this->fail('Embedding gagal harus menolak indeks ulang.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('paper_chunks', ['id' => $chunk->id]);
        }
    }

    public function test_vector_retrieval_filters_project_model_dimensions_and_status(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $this->chunk($document, [1, 0, 0]);
        $wrong = $this->document(ResearchProject::factory()->create(), 'indexed');
        $this->chunk($wrong, [1, 0, 0]);
        $pending = $this->document($project, 'queued');
        $this->chunk($pending, [1, 0, 0]);
        $model = $this->document($project, 'indexed');
        $this->chunk($model, [1, 0, 0], 'other-model');
        $results = app(VectorSearchService::class)->search($project, [1.0, 0.0, 0.0]);
        $this->assertCount(1, $results);
        $this->assertSame($document->id, $results[0]['document_id']);
        $this->assertSame(1.0, $results[0]['score']);
    }

    public function test_chat_without_documents_marks_insufficient_evidence_without_calling_ai(): void
    {
        $project = ResearchProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.chat.store', $project), ['question' => 'Apa temuannya?'])->assertRedirect(route('projects.chat.index', $project));
        Queue::assertPushed(AnswerResearchQuestion::class);
        $message = $project->messages()->where('role', 'assistant')->firstOrFail();
        (new AnswerResearchQuestion($message->id, 'Apa temuannya?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
        $message->refresh();
        $this->assertSame('completed', $message->status);
        $this->assertSame('insufficient_evidence', $message->evidence_status);
        Http::assertNothingSent();
    }

    public function test_chat_rejects_fabricated_chunk_reference_instead_of_saving_answer(): void
    {
        $project = ResearchProject::factory()->create();
        $this->chunk($this->document($project, 'indexed'), [1, 0, 0]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => Http::response(GatewayFixture::chat(json_encode(['answer' => 'Klaim rekaan.', 'evidence_status' => 'supported', 'citations' => [['chunk_id' => 999]]]))),
        ]);
        try {
            (new AnswerResearchQuestion($message->id, 'Apa temuannya?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
            $this->fail('Sumber rekaan harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tidak ditemukan', $exception->getMessage());
            $this->assertSame('failed', $message->fresh()->status);
            $this->assertSame('', $message->fresh()->content);
        }
    }

    public function test_chat_preserves_valid_quote_page_and_document_reference(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $this->chunk($document, [1, 0, 0]);
        $chunk = $document->chunks()->firstOrFail();
        $chunk->update(['content' => "Teks bukti asli.\nSimbol ±0,75 dan pe-\nmenggalan kata tetap dipertahankan."]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => Http::response(GatewayFixture::chat(json_encode(['answer' => 'Dokumen memuat teks bukti pengujian.', 'evidence_status' => 'supported', 'citations' => [['chunk_id' => $chunk->id]]]))),
        ]);
        (new AnswerResearchQuestion($message->id, 'Apa isi dokumen?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
        $message->refresh();
        $this->assertSame('supported', $message->evidence_status);
        $this->assertSame($document->id, $message->source_references[0]['document_id']);
        $this->assertSame($document->paper_id, $message->source_references[0]['paper_id']);
        $this->assertSame(1, $message->source_references[0]['page_number']);
        $this->assertSame($chunk->content, $message->source_references[0]['quote']);
    }

    public function test_chat_rejects_model_supplied_quote_instead_of_overwriting_source(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $this->chunk($document, [1, 0, 0]);
        $chunk = $document->chunks()->firstOrFail();
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => Http::response(GatewayFixture::chat(json_encode(['answer' => 'Jawaban rekaan.', 'evidence_status' => 'supported', 'citations' => [['chunk_id' => $chunk->id, 'quote' => 'Kutipan yang diubah model.']]]))),
        ]);
        try {
            (new AnswerResearchQuestion($message->id, 'Apa temuannya?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
            $this->fail('Model tidak boleh mengganti kutipan asli.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('quote', $exception->getMessage());
            $this->assertSame('failed', $message->fresh()->status);
            $this->assertSame('', $message->fresh()->content);
            $this->assertNull($message->fresh()->source_references);
            $this->assertSame('Teks bukti pengujian.', $chunk->fresh()->content);
        }
    }

    public function test_chat_rejects_supported_answer_without_citations(): void
    {
        $project = ResearchProject::factory()->create();
        $this->chunk($this->document($project, 'indexed'), [1, 0, 0]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => Http::response(GatewayFixture::chat(json_encode(['answer' => 'Tidak ada dukungan sumber.', 'evidence_status' => 'supported', 'citations' => []]))),
        ]);
        try {
            (new AnswerResearchQuestion($message->id, 'Apa temuannya?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
            $this->fail('Status didukung wajib memiliki sumber.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tanpa kutipan', $exception->getMessage());
            $this->assertSame('failed', $message->fresh()->status);
            $this->assertSame('', $message->fresh()->content);
        }
    }

    public function test_chat_rejects_evidence_that_disappears_before_save(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $chunk = $this->chunk($document, [1, 0, 0]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => function (Request $request) use ($chunk) {
                $chunk->delete();

                return Http::response(GatewayFixture::chat(json_encode(['answer' => 'Jawaban lama.', 'evidence_status' => 'supported', 'citations' => [['chunk_id' => $chunk->id]]])));
            },
        ]);

        try {
            (new AnswerResearchQuestion($message->id, 'Apa temuannya?'))->handle(app(RetrievalService::class), app(SenopatiService::class));
            $this->fail('Bukti yang berubah harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Bukti berubah', $exception->getMessage());
            $this->assertSame('failed', $message->fresh()->status);
            $this->assertNull($message->fresh()->source_references);
        }
    }

    public function test_reindex_marks_historical_citations_for_review_and_requires_force_for_changed_dimensions(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $this->chunk($document, [1, 0, 0]);
        $oldChunk = $document->chunks()->firstOrFail();
        $message = $project->messages()->create(['role' => 'assistant', 'content' => 'Jawaban lama.', 'status' => 'completed', 'evidence_status' => 'supported', 'source_references' => [['document_id' => $document->id, 'chunk_id' => $oldChunk->id]]]);
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0]]))]);
        try {
            app(EmbeddingService::class)->process($document);
            $this->fail('Dimensi berubah harus memerlukan indeks ulang penuh.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
            $this->assertDatabaseHas('paper_chunks', ['id' => $oldChunk->id]);
        }
        app(EmbeddingService::class)->process($document, true);
        $this->assertDatabaseMissing('paper_chunks', ['id' => $oldChunk->id]);
        $this->assertSame(2, $document->chunks()->firstOrFail()->embedding_dimensions);
        $this->assertSame('needs_human_review', $message->fresh()->evidence_status);
        $this->assertSame('Jawaban lama.', $message->fresh()->content);
    }

    public function test_queue_failure_records_honest_error_and_never_marks_document_indexed(): void
    {
        $document = $this->document(ResearchProject::factory()->create());
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response([], 404)]);
        try {
            (new ProcessDocument($document->id))->handle(app(EmbeddingService::class));
            $this->fail('Model yang belum tersedia harus menggagalkan pekerjaan.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $document->fresh()->extraction_status);
            $this->assertStringContainsString('HTTP 404', $document->fresh()->error_message);
            $this->assertDatabaseCount('paper_chunks', 0);
        }
    }

    public function test_reindex_command_refuses_without_force_and_queues_only_project_documents(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $other = $this->document(ResearchProject::factory()->create(), 'indexed');
        $this->artisan('ai:reindex', ['project' => $project->id])->assertExitCode(1);
        Http::assertNothingSent();
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]]))]);
        $this->artisan('ai:reindex', ['project' => $project->id, '--force' => true])->assertSuccessful();
        Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->documentId === $document->id && $job->forceReindex);
        Queue::assertPushed(ProcessDocument::class, 1);
        $this->assertSame('queued', $document->fresh()->extraction_status);
        $this->assertSame('indexed', $other->fresh()->extraction_status);
    }

    public function test_deleting_document_cleans_private_pdf_but_keeps_paper_metadata(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project);
        $paperId = $document->paper_id;
        $this->actingAs($project->user)->delete(route('projects.documents.destroy', [$project, $document]))->assertRedirect();
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseHas('papers', ['id' => $paperId]);
        Storage::disk('local')->assertMissing($document->storage_path);
    }

    public function test_document_changes_are_blocked_while_a_research_run_is_active(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project);
        $project->runs()->create(['status' => 'analyzing', 'current_stage' => 'analyzing']);

        $this->actingAs($project->user)->delete(route('projects.documents.destroy', [$project, $document]))->assertSessionHasErrors('workflow');
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    private function document(ResearchProject $project, string $status = 'queued'): Document
    {
        $paper = Paper::query()->create(['title' => 'Berkas pengujian', 'authors' => [], 'metadata_source' => 'uploaded_filename']);
        $project->papers()->attach($paper);
        $path = 'projects/'.$project->id.'/documents/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, PdfFixture::make());

        return $project->documents()->create(['paper_id' => $paper->id, 'original_filename' => 'pengujian.pdf', 'storage_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen(PdfFixture::make()), 'file_hash' => hash('sha256', $path), 'extraction_status' => $status]);
    }

    private function chunk(Document $document, array $vector, ?string $model = null): PaperChunk
    {
        return $document->chunks()->create(['chunk_index' => 0, 'page_number' => 1, 'content' => 'Teks bukti pengujian.',
            'embedding' => json_encode($vector), 'embedding_dimensions' => count($vector), 'embedding_model' => $model ?? config('ai.embed_model')]);
    }
}
