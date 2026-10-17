<?php

namespace Tests\Feature;

use App\Jobs\PruneVectorChunks;
use App\Models\Document;
use App\Models\PaperChunk;
use App\Services\Documents\QdrantService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\QdrantFixture;
use Tests\TestCase;

class QdrantServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai.qdrant_url' => 'http://qdrant.test:6333', 'ai.qdrant_api_key' => 'qdrant-secret', 'ai.qdrant_collection' => 'researchmate']);
    }

    public function test_collection_separates_model_and_dimensions_and_creates_cosine_and_payload_indexes(): void
    {
        $qdrant = app(QdrantService::class);
        $name = $qdrant->collection('test-model', 2);
        $this->assertNotSame($name, $qdrant->collection('other-model', 2));
        $this->assertNotSame($name, $qdrant->collection('test-model', 3));
        Http::fake([
            'http://qdrant.test:6333/collections/'.$name => Http::sequence()
                ->push([], 404)->push(['status' => 'ok', 'result' => true])
                ->push(['status' => 'ok', 'result' => ['config' => ['params' => ['vectors' => ['size' => 2, 'distance' => 'Cosine']]]]]),
            'http://qdrant.test:6333/*/index?wait=true' => Http::response(['status' => 'ok', 'result' => ['status' => 'completed']]),
        ]);

        $qdrant->ensureCollection('test-model', 2);

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && ($request['vectors'] ?? null) === ['size' => 2, 'distance' => 'Cosine']
            && $request->hasHeader('api-key', 'qdrant-secret') && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request) => ($request['field_name'] ?? null) === 'project_id' && $request['field_schema'] === 'integer');
    }

    public function test_upsert_sends_vectors_and_identifiers_without_private_text_or_gateway_token(): void
    {
        QdrantFixture::fake();
        $chunk = new PaperChunk(['document_id' => 8, 'content' => 'Teks privat lengkap.', 'embedding' => '[1,0]', 'embedding_model' => 'model', 'embedding_dimensions' => 2]);
        $chunk->id = 42;
        $chunk->setRelation('document', new Document(['project_id' => 7]));

        app(QdrantService::class)->upsertChunks([$chunk]);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points?wait=true')
            && $request['points'] === [['id' => 42, 'vector' => [1, 0], 'payload' => ['project_id' => 7, 'document_id' => 8, 'embedding_model' => 'model', 'embedding_dimensions' => 2]]]
            && ! str_contains($request->body(), 'Teks privat') && ! str_contains($request->body(), 'test-secret'));
    }

    /** @return array<string, array{array}> */
    public static function invalidVectors(): array
    {
        return ['empty' => [[]], 'zero norm' => [[0.0, 0.0]], 'non numeric' => [['1', 0]], 'non finite' => [[INF, 1]], 'sparse keys' => [[1 => 1.0]]];
    }

    #[DataProvider('invalidVectors')]
    public function test_invalid_vector_is_rejected_before_http(array $vector): void
    {
        try {
            app(QdrantService::class)->search(7, 'model', $vector, [42], 5);
            $this->fail('Vektor tidak valid harus ditolak.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_query_is_filtered_to_project_model_dimensions_and_live_chunk_ids(): void
    {
        QdrantFixture::fake();
        $results = app(QdrantService::class)->search(7, 'model', [1.0, 0.0], [42, 43], 1);
        $this->assertSame([['id' => 42, 'score' => 1.0]], $results);
        Http::assertSent(fn (Request $request) => $request['filter'] === ['must' => [
            ['key' => 'project_id', 'match' => ['value' => 7]],
            ['key' => 'embedding_model', 'match' => ['value' => 'model']],
            ['key' => 'embedding_dimensions', 'match' => ['value' => 2]],
            ['has_id' => [42, 43]],
        ]] && $request['with_payload'] === false && $request['with_vector'] === false && $request['limit'] === 1);
    }

    public function test_query_http_error_is_reported_without_private_response_body(): void
    {
        Http::fake(['http://qdrant.test:6333/*' => Http::response(['token' => 'private-response'], 503)]);
        try {
            app(QdrantService::class)->search(7, 'model', [1.0, 0.0], [42], 5);
            $this->fail('Gangguan Qdrant harus dilaporkan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
            $this->assertStringNotContainsString('private-response', $exception->getMessage());
        }
    }

    public function test_query_rejects_malformed_point_scores(): void
    {
        Http::fake(['http://qdrant.test:6333/*' => Http::response(['status' => 'ok', 'result' => ['points' => [['id' => 42, 'score' => 'high']]]])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('titik atau skor');
        app(QdrantService::class)->search(7, 'model', [1.0, 0.0], [42], 5);
    }

    public function test_deletion_is_limited_to_application_collections_and_payload_id(): void
    {
        $name = app(QdrantService::class)->collection('model', 2);
        Http::fake([
            'http://qdrant.test:6333/collections' => Http::response(['status' => 'ok', 'result' => ['collections' => [['name' => $name], ['name' => 'another_application'], ['name' => 'researchmate_backup']]]]),
            'http://qdrant.test:6333/*/points/delete?wait=true' => Http::response([], 404),
        ]);

        app(QdrantService::class)->deleteDocument(8);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/'.$name.'/points/delete?wait=true')
            && $request['filter'] === ['must' => [['key' => 'document_id', 'match' => ['value' => 8]]]]);
    }

    public function test_old_chunk_cleanup_deletes_only_explicit_point_ids(): void
    {
        QdrantFixture::fake();
        $chunks = [['id' => 42, 'embedding_model' => 'model', 'embedding_dimensions' => 2]];

        (new PruneVectorChunks($chunks))->handle(app(QdrantService::class));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/delete?wait=true') && $request['points'] === [42] && ! isset($request['filter']));
    }
}
