<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use App\Services\AI\SenopatiService;
use App\Services\Documents\QdrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QdrantFixture;
use Tests\TestCase;

class VectorSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_syncs_existing_vectors_to_qdrant_without_changing_postgresql_chunks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->artisan('ai:setup-vector')->expectsOutput('Perintah ini memerlukan PostgreSQL.')->assertFailed();

            return;
        }
        $project = ResearchProject::factory()->create();
        $document = Document::query()->create(['project_id' => $project->id, 'original_filename' => 'source.pdf', 'storage_path' => 'test.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'file_hash' => hash('sha256', 'source')]);
        $chunk = PaperChunk::query()->create(['document_id' => $document->id, 'chunk_index' => 0, 'content' => 'Sumber asli', 'embedding' => '[1,0]', 'embedding_dimensions' => 2, 'embedding_model' => 'test']);
        Http::preventStrayRequests();
        QdrantFixture::fake();
        $this->mock(SenopatiService::class)->shouldReceive('generateEmbedding')->once()->andReturn([1.0, 0.0]);
        $this->artisan('ai:setup-vector')->assertSuccessful();
        $this->assertSame('[1,0]', $chunk->fresh()->embedding);
        $this->assertDatabaseHas('paper_chunks', ['id' => $chunk->id, 'content' => 'Sumber asli', 'embedding_model' => 'test', 'embedding_dimensions' => 2]);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/'.app(QdrantService::class)->collection('test', 2).'/points?wait=true')
            && $request['points'][0]['id'] === $chunk->id && $request['points'][0]['vector'] === [1, 0]
            && $request['points'][0]['payload']['project_id'] === $project->id
            && ! array_key_exists('content', $request['points'][0]['payload']));
    }
}
