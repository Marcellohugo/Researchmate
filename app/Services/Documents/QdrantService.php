<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\PaperChunk;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QdrantService
{
    public function collection(string $model, int $dimensions): string
    {
        $prefix = (string) config('ai.qdrant_collection', 'researchmate');
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $prefix) !== 1 || $model === '' || $dimensions < 1 || $dimensions > 65536) {
            throw new RuntimeException('Konfigurasi koleksi atau dimensi Qdrant tidak valid.');
        }

        return $prefix.'_'.substr(hash('sha256', $model), 0, 16).'_'.$dimensions;
    }

    public function ensureCollection(string $model, int $dimensions): void
    {
        $name = $this->collection($model, $dimensions);
        $response = $this->request('GET', '/collections/'.$name, allowed: [404]);
        if ($response->status() === 404) {
            $this->request('PUT', '/collections/'.$name, ['vectors' => ['size' => $dimensions, 'distance' => 'Cosine']], [409]);
            $response = $this->request('GET', '/collections/'.$name);
        }
        $this->validateCollection($response, $dimensions);
        foreach (['project_id' => 'integer', 'document_id' => 'integer', 'embedding_model' => 'keyword', 'embedding_dimensions' => 'integer'] as $field => $schema) {
            $this->request('PUT', '/collections/'.$name.'/index?wait=true', ['field_name' => $field, 'field_schema' => $schema]);
        }
    }

    public function checkCollection(string $model, int $dimensions): void
    {
        $this->validateCollection($this->request('GET', '/collections/'.$this->collection($model, $dimensions)), $dimensions);
    }

    /** @param iterable<PaperChunk> $chunks */
    public function upsertChunks(iterable $chunks): void
    {
        $groups = [];
        foreach ($chunks as $chunk) {
            $model = (string) $chunk->embedding_model;
            $dimensions = (int) $chunk->embedding_dimensions;
            $name = $this->collection($model, $dimensions);
            $vector = json_decode((string) $chunk->embedding, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($vector) || count($vector) !== $dimensions) {
                throw new RuntimeException('Embedding tersimpan tidak sesuai dengan dimensinya.');
            }
            $this->validateVector($vector);
            $chunk->loadMissing('document');
            $groups[$name]['model'] = $model;
            $groups[$name]['dimensions'] = $dimensions;
            $groups[$name]['points'][] = [
                'id' => $chunk->id, 'vector' => $vector,
                'payload' => [
                    'project_id' => $chunk->document->project_id, 'document_id' => $chunk->document_id,
                    'embedding_model' => $model, 'embedding_dimensions' => $dimensions,
                ],
            ];
        }
        foreach ($groups as $name => $group) {
            $this->ensureCollection($group['model'], $group['dimensions']);
            foreach (array_chunk($group['points'], 100) as $points) {
                $response = $this->request('PUT', '/collections/'.$name.'/points?wait=true', ['points' => $points]);
                if ($response->json('result.status') !== 'completed') {
                    throw new RuntimeException('Qdrant belum menyelesaikan penyimpanan vektor. Indeks lama dipertahankan.');
                }
            }
        }
    }

    /**
     * @param  list<float>  $vector
     * @param  list<int>  $chunkIds
     * @return list<array{id: int, score: float}>
     */
    public function search(int $projectId, string $model, array $vector, array $chunkIds, int $limit): array
    {
        $this->validateVector($vector);
        if ($projectId < 1 || $limit < 1 || $limit > 30) {
            throw new RuntimeException('Parameter pencarian vektor tidak valid.');
        }
        if ($chunkIds === []) {
            return [];
        }
        $data = $this->request('POST', '/collections/'.$this->collection($model, count($vector)).'/points/query', [
            'query' => $vector, 'limit' => $limit, 'with_payload' => false, 'with_vector' => false,
            'filter' => ['must' => [
                ['key' => 'project_id', 'match' => ['value' => $projectId]],
                ['key' => 'embedding_model', 'match' => ['value' => $model]],
                ['key' => 'embedding_dimensions', 'match' => ['value' => count($vector)]],
                // PostgreSQL remains authoritative after a rollback, reindex, or deletion.
                ['has_id' => $chunkIds],
            ]],
        ])->json('result.points');
        if (! is_array($data) || ! array_is_list($data) || count($data) > $limit) {
            throw new RuntimeException('Qdrant mengembalikan hasil pencarian yang tidak valid.');
        }
        $results = [];
        foreach ($data as $point) {
            $id = filter_var($point['id'] ?? null, FILTER_VALIDATE_INT);
            $score = $point['score'] ?? null;
            if ($id === false || $id < 1 || (! is_float($score) && ! is_int($score)) || ! is_finite($score) || isset($results[$id])) {
                throw new RuntimeException('Qdrant mengembalikan titik atau skor yang tidak valid.');
            }
            $results[$id] = ['id' => $id, 'score' => (float) $score];
        }

        return array_values($results);
    }

    /** @param list<array{id: int, embedding_model: string, embedding_dimensions: int}> $chunks */
    public function deleteChunks(array $chunks): void
    {
        $groups = [];
        foreach ($chunks as $chunk) {
            $groups[$this->collection($chunk['embedding_model'], $chunk['embedding_dimensions'])][] = $chunk['id'];
        }
        foreach ($groups as $name => $ids) {
            foreach (array_chunk($ids, 100) as $batch) {
                $this->request('POST', '/collections/'.$name.'/points/delete?wait=true', ['points' => $batch], [404]);
            }
        }
    }

    public function deleteDocument(int $documentId): void
    {
        $this->deleteByPayload('document_id', $documentId);
    }

    public function deleteProject(int $projectId): void
    {
        $this->deleteByPayload('project_id', $projectId);
    }

    /** @param array<mixed> $vector */
    public function validateVector(array $vector): void
    {
        if ($vector === [] || ! array_is_list($vector) || count($vector) > 65536) {
            throw new RuntimeException('Vektor pencarian tidak valid.');
        }
        $norm = 0.0;
        foreach ($vector as $number) {
            if ((! is_float($number) && ! is_int($number)) || ! is_finite($number)) {
                throw new RuntimeException('Vektor mengandung angka tidak valid.');
            }
            $norm += $number * $number;
        }
        if ($norm === 0.0 || ! is_finite($norm)) {
            throw new RuntimeException('Norma vektor tidak valid atau bernilai nol.');
        }
    }

    private function validateCollection(Response $response, int $dimensions): void
    {
        if ($response->json('result.config.params.vectors.size') !== $dimensions
            || $response->json('result.config.params.vectors.distance') !== 'Cosine') {
            throw new RuntimeException('Koleksi Qdrant tidak sesuai model atau dimensi. Periksa konfigurasi dan jalankan ai:setup-vector.');
        }
    }

    private function deleteByPayload(string $field, int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('ID pembersihan indeks tidak valid.');
        }
        $collections = $this->request('GET', '/collections')->json('result.collections');
        if (! is_array($collections) || ! array_is_list($collections)) {
            throw new RuntimeException('Daftar koleksi Qdrant tidak valid.');
        }
        $prefix = preg_quote((string) config('ai.qdrant_collection', 'researchmate'), '/');
        foreach ($collections as $collection) {
            $name = $collection['name'] ?? null;
            if (is_string($name) && preg_match('/\A'.$prefix.'_[a-f0-9]{16}_[0-9]+\z/', $name) === 1) {
                $this->request('POST', '/collections/'.$name.'/points/delete?wait=true', [
                    'filter' => ['must' => [['key' => $field, 'match' => ['value' => $id]]]],
                ], [404]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<int>  $allowed
     */
    private function request(string $method, string $path, array $body = [], array $allowed = []): Response
    {
        $url = rtrim((string) config('ai.qdrant_url', 'http://127.0.0.1:6333'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('QDRANT_URL tidak valid.');
        }
        $request = Http::acceptJson()->asJson()->withoutRedirecting()
            ->connectTimeout(5)->timeout(max(1, (int) config('ai.qdrant_timeout', 15)));
        if ((string) config('ai.qdrant_api_key') !== '') {
            $request = $request->withHeaders(['api-key' => (string) config('ai.qdrant_api_key')]);
        }
        try {
            $response = $request->send($method, $url.$path, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new RuntimeException('Qdrant tidak dapat dihubungi. Periksa layanan dan QDRANT_URL.');
        }
        if (in_array($response->status(), $allowed, true)) {
            return $response;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Qdrant mengembalikan HTTP '.$response->status().'. Periksa layanan; jalankan ai:setup-vector bila koleksi belum tersedia.');
        }
        if ($response->json('status') !== 'ok') {
            throw new RuntimeException('Qdrant mengembalikan respons yang tidak valid.');
        }

        return $response;
    }
}
