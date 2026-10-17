<?php

namespace Tests\Feature;

use App\Jobs\AnswerResearchQuestion;
use App\Jobs\ProcessDocument;
use App\Jobs\PruneVectorChunks;
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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GatewayFixture;
use Tests\Support\PdfFixture;
use Tests\Support\QdrantFixture;
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
        QdrantFixture::fake();
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
        $validChunk = $this->chunk($document, [1, 0, 0]);
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
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/query')
            && $request['filter']['must'][0]['match']['value'] === $project->id
            && $request['filter']['must'][1]['match']['value'] === config('ai.embed_model')
            && $request['filter']['must'][2]['match']['value'] === 3
            && $request['filter']['must'][3]['has_id'] === [$validChunk->id]);
    }

    public function test_qdrant_failure_rolls_back_reindex_and_preserves_chunk_and_citations(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $oldChunk = $this->chunk($document, [1, 0, 0]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => 'Jawaban lama.', 'status' => 'completed', 'evidence_status' => 'supported', 'source_references' => [['document_id' => $document->id, 'chunk_id' => $oldChunk->id]]]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['http://qdrant.test:6333/*/points?wait=true' => Http::response([], 503)]);
        QdrantFixture::fake();
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]]))]);

        try {
            app(EmbeddingService::class)->process($document);
            $this->fail('Qdrant gagal harus mempertahankan indeks lama.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Qdrant mengembalikan HTTP 503', $exception->getMessage());
            $this->assertSame([$oldChunk->id], $document->chunks()->pluck('id')->all());
            $this->assertSame('supported', $message->fresh()->evidence_status);
            $this->assertSame('indexed', $document->fresh()->extraction_status);
            Queue::assertNotPushed(PruneVectorChunks::class);
        }
    }

    public function test_retrieval_rechecks_database_scope_even_if_qdrant_returns_foreign_points(): void
    {
        $project = ResearchProject::factory()->create();
        $valid = $this->chunk($this->document($project, 'indexed'), [1, 0, 0]);
        $foreign = $this->chunk($this->document(ResearchProject::factory()->create(), 'indexed'), [1, 0, 0]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['http://qdrant.test:6333/*/points/query' => Http::response(['status' => 'ok', 'result' => ['points' => [
            ['id' => $foreign->id, 'score' => 1.0], ['id' => $valid->id, 'score' => 0.8],
        ]]])]);

        $results = app(VectorSearchService::class)->search($project, [1.0, 0.0, 0.0]);

        $this->assertSame([$valid->id], array_column($results, 'chunk_id'));
        $this->assertSame(0.8, $results[0]['score']);
    }

    public function test_retrieval_uses_current_topic_question_and_keywords_while_preserving_project_scope(): void
    {
        $project = ResearchProject::factory()->create(['topic' => 'Energi surya', 'research_question' => 'Bagaimana efisiensi panel?', 'keywords' => 'fotovoltaik, efisiensi']);
        $chunk = $this->chunk($this->document($project, 'indexed'), [1, 0, 0]);
        $this->chunk($this->document(ResearchProject::factory()->create(), 'indexed'), [1, 0, 0]);
        $inputs = [];
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => function (Request $request) use (&$inputs) {
            $inputs[] = $request['input'][0];

            return Http::response(GatewayFixture::embeddings([[1, 0, 0]]));
        }]);

        $first = app(RetrievalService::class)->retrieve($project, 'Apa temuan utamanya?');
        $project->update(['topic' => 'Penyimpanan energi', 'research_question' => 'Bagaimana umur baterai?', 'keywords' => 'baterai, degradasi']);
        $second = app(RetrievalService::class)->retrieve($project, str_repeat('A', 10000));

        $this->assertStringContainsString('Energi surya', $inputs[0]);
        $this->assertStringContainsString('Bagaimana efisiensi panel?', $inputs[0]);
        $this->assertStringContainsString('fotovoltaik, efisiensi', $inputs[0]);
        $this->assertStringContainsString('Apa temuan utamanya?', $inputs[0]);
        $this->assertStringContainsString('Penyimpanan energi', $inputs[1]);
        $this->assertStringContainsString('Bagaimana umur baterai?', $inputs[1]);
        $this->assertStringContainsString('baterai, degradasi', $inputs[1]);
        $this->assertStringNotContainsString('Energi surya', $inputs[1]);
        $this->assertLessThanOrEqual(6000, mb_strlen($inputs[1]));
        $this->assertSame([$chunk->id], array_column($first, 'chunk_id'));
        $this->assertSame([$chunk->id], array_column($second, 'chunk_id'));
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

    /** @return array<string, array{string}> */
    public static function evidenceMutations(): array
    {
        return ['deleted' => ['deleted'], 'text changed' => ['text'], 'reindex queued' => ['queued']];
    }

    #[DataProvider('evidenceMutations')]
    public function test_chat_rejects_evidence_changed_before_save(string $mutation): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $chunk = $this->chunk($document, [1, 0, 0]);
        $message = $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending']);
        Http::fake([
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(GatewayFixture::embeddings([[1, 0, 0]])),
            SenopatiService::ENDPOINT => function (Request $request) use ($chunk, $document, $mutation) {
                match ($mutation) {
                    'text' => $chunk->update(['content' => 'Teks telah berubah saat inferensi.']),
                    'queued' => $document->update(['extraction_status' => 'queued']),
                    default => $chunk->delete(),
                };

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
        $this->post(route('projects.documents.reindex', [$project, $document]))->assertSessionHasErrors('workflow');
        $this->post(route('projects.documents.store', $project), ['pdf' => UploadedFile::fake()->createWithContent('baru.pdf', PdfFixture::make('Bukti baru.'))])->assertSessionHasErrors('workflow');
        $this->artisan('ai:reindex', ['project' => $project->id, '--force' => true])->assertExitCode(1);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_chat_warns_and_hides_links_after_source_reindex(): void
    {
        $project = ResearchProject::factory()->create();
        $document = $this->document($project, 'indexed');
        $chunk = $this->chunk($document, [1, 0, 0]);
        $project->messages()->create(['role' => 'assistant', 'content' => 'Jawaban historis.', 'status' => 'completed', 'evidence_status' => 'needs_human_review',
            'error_message' => 'Dokumen sumber perlu diverifikasi ulang.', 'source_references' => [['chunk_id' => $chunk->id, 'document_id' => $document->id]]]);

        $this->actingAs($project->user)->get(route('projects.chat.index', $project))->assertOk()->assertSee('Dokumen sumber perlu diverifikasi ulang.')
            ->assertDontSee(route('projects.evidence', [$project, $chunk]), false);
    }

    public function test_indexing_retry_reuses_completed_batches_and_preserves_old_index_until_complete(): void
    {
        config(['ai.chunk_size' => 100, 'ai.chunk_overlap' => 0]);
        $document = $this->document(ResearchProject::factory()->create());
        $oldChunk = $this->chunk($document, [1, 0, 0]);
        $text = implode(' ', array_map(fn ($number) => 'Bukti unik nomor '.$number.'.', range(1, 100)));
        Storage::disk('local')->put($document->storage_path, PdfFixture::make($text));
        $requests = 0;
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => function (Request $request) use (&$requests) {
            $requests++;
            if ($requests === 2) {
                return Http::response([], 429);
            }

            return Http::response(GatewayFixture::embeddings(array_fill(0, count($request['input']), [1, 0, 0])));
        }]);
        try {
            (new ProcessDocument($document->id))->handle(app(EmbeddingService::class));
            $this->fail('Batch gagal harus menunda penggantian indeks.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 429', $exception->getMessage());
            $this->assertDatabaseHas('paper_chunks', ['id' => $oldChunk->id]);
            $this->assertSame('failed', $document->fresh()->extraction_status);
        }

        (new ProcessDocument($document->id))->handle(app(EmbeddingService::class));
        $this->assertSame('indexed', $document->fresh()->extraction_status);
        $this->assertDatabaseMissing('paper_chunks', ['id' => $oldChunk->id]);
        $this->assertSame((int) ceil($document->chunks()->count() / 8) + 1, $requests);
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
