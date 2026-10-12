<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\PaperChunk;
use App\Models\ResearchProject;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VectorSearchService
{
    /**
     * @param  list<float>  $embedding
     * @return list<array<string, mixed>>
     */
    public function search(ResearchProject $project, array $embedding, int $limit = 5): array
    {
        if ($embedding === [] || $limit < 1 || $limit > 30) {
            throw new RuntimeException('Parameter pencarian vektor tidak valid.');
        }
        foreach ($embedding as $number) {
            if (! is_finite($number)) {
                throw new RuntimeException('Vektor pencarian mengandung angka tidak valid.');
            }
        }
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $embedding)));
        if ($norm === 0.0 || ! is_finite($norm)) {
            throw new RuntimeException('Norma vektor pencarian tidak valid atau bernilai nol.');
        }
        $query = PaperChunk::query()->with('document')
            ->whereNotNull('embedding')
            ->whereHas('document', fn ($q) => $q->where('project_id', $project->id)->where('extraction_status', 'indexed'))
            ->where('embedding_model', (string) config('ai.embed_model'))->where('embedding_dimensions', count($embedding));
        if (DB::connection()->getDriverName() === 'pgsql') {
            $vector = json_encode($embedding, JSON_THROW_ON_ERROR);
            $chunks = $query->select('paper_chunks.*')->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$vector])
                ->orderByRaw('embedding <=> ?::vector', [$vector])->limit($limit)->get();
        } elseif (app()->environment('testing')) {
            $chunks = $query->get()->map(function (PaperChunk $chunk) use ($embedding, $norm): PaperChunk {
                $stored = json_decode($chunk->embedding, true, 512, JSON_THROW_ON_ERROR);
                $dot = 0.0;
                $storedNorm = 0.0;
                foreach ($embedding as $index => $value) {
                    $dot += $value * $stored[$index];
                    $storedNorm += $stored[$index] ** 2;
                }
                $chunk->setAttribute('similarity', $storedNorm > 0 ? $dot / ($norm * sqrt($storedNorm)) : 0.0);

                return $chunk;
            })->sortByDesc('similarity')->take($limit);
        } else {
            throw new RuntimeException('Pencarian semantik memerlukan PostgreSQL dengan pgvector.');
        }

        return array_values($chunks->map(fn (PaperChunk $chunk): array => [
            'chunk_id' => $chunk->id, 'document_id' => $chunk->document_id, 'paper_id' => $chunk->document->paper_id,
            'page_number' => $chunk->page_number, 'content' => $chunk->content, 'score' => (float) $chunk->getAttribute('similarity'),
        ])->all());
    }
}
