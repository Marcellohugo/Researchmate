<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use App\Services\AI\SenopatiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VectorSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_requires_postgresql_or_preserves_existing_vectors(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->artisan('ai:setup-vector')->expectsOutput('Perintah ini memerlukan PostgreSQL.')->assertFailed();

            return;
        }
        $project = ResearchProject::factory()->create();
        $document = Document::query()->create(['project_id' => $project->id, 'original_filename' => 'source.pdf', 'storage_path' => 'test.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'file_hash' => hash('sha256', 'source')]);
        DB::statement('ALTER TABLE paper_chunks ALTER COLUMN embedding TYPE text USING embedding::text');
        $chunk = PaperChunk::query()->create(['document_id' => $document->id, 'chunk_index' => 0, 'content' => 'Sumber asli', 'embedding' => '[1,0]', 'embedding_dimensions' => 2, 'embedding_model' => 'test']);
        $this->mock(SenopatiService::class)->shouldReceive('generateEmbedding')->once()->andReturn([1.0, 0.0]);
        $this->artisan('ai:setup-vector')->assertSuccessful();
        $this->assertSame('[1,0]', $chunk->fresh()->embedding);
        $this->assertSame(2, (int) DB::selectOne('SELECT vector_dims(embedding) AS dimensions FROM paper_chunks WHERE id = ?', [$chunk->id])->dimensions);
    }
}
