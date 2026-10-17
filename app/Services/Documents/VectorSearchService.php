<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\PaperChunk;
use App\Models\ResearchProject;
use RuntimeException;

class VectorSearchService
{
    public function __construct(private QdrantService $qdrant) {}

    /**
     * @param  list<float>  $embedding
     * @return list<array<string, mixed>>
     */
    public function search(ResearchProject $project, array $embedding, int $limit = 5): array
    {
        $this->qdrant->validateVector($embedding);
        if ($limit < 1 || $limit > 30) {
            throw new RuntimeException('Parameter pencarian vektor tidak valid.');
        }
        $model = (string) config('ai.embed_model');
        $query = PaperChunk::query()->with('document')
            ->whereNotNull('embedding')
            ->whereHas('document', fn ($q) => $q->where('project_id', $project->id)->where('extraction_status', 'indexed'))
            ->where('embedding_model', $model)->where('embedding_dimensions', count($embedding));
        $ids = array_values((clone $query)->get(['id'])->map(fn (PaperChunk $chunk): int => $chunk->id)->all());
        $matches = $this->qdrant->search($project->id, $model, $embedding, $ids, $limit);
        $chunks = $query->whereIn('id', array_column($matches, 'id'))->get()->keyBy('id');
        $results = [];
        foreach ($matches as $match) {
            $chunk = $chunks->get($match['id']);
            if ($chunk !== null) {
                $results[] = [
                    'chunk_id' => $chunk->id, 'document_id' => $chunk->document_id, 'paper_id' => $chunk->document->paper_id,
                    'page_number' => $chunk->page_number, 'content' => $chunk->content, 'score' => $match['score'],
                ];
            }
        }

        return $results;
    }
}
