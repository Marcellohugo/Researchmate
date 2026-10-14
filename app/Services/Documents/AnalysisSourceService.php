<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Paper;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use Illuminate\Database\Eloquent\Collection;

class AnalysisSourceService
{
    /** @return Collection<int, PaperChunk> */
    public function forPaper(ResearchProject $project, Paper $paper): Collection
    {
        $query = PaperChunk::query()->whereHas('document', fn ($query) => $query
            ->where('project_id', $project->id)->where('paper_id', $paper->id)->where('extraction_status', 'indexed'))
            ->orderBy('document_id')->orderBy('chunk_index');
        $limit = max(1, min(1000, (int) config('ai.max_document_chunks', 1000)));
        $chunks = $query->limit($limit + 1)->get(['id', 'document_id', 'page_number', 'section_name', 'content']);
        if ($chunks->count() > $limit) {
            throw new \RuntimeException('Artikel melampaui batas '.$limit.' potongan untuk analisis. Kurangi dokumen duplikat pada artikel ini.');
        }
        $lengths = $chunks->map(fn (PaperChunk $chunk): int => mb_strlen($chunk->content));
        if ($lengths->sum() > $limit * 1200 || ($lengths->max() ?? 0) > 14400) {
            throw new \RuntimeException('Teks artikel melampaui anggaran analisis. Indeks ulang PDF dengan ukuran potongan 1200 karakter atau kurangi dokumen duplikat.');
        }

        return $chunks;
    }

    /**
     * @param  Collection<int, PaperChunk>  $chunks
     * @return list<Collection<int, PaperChunk>>
     */
    public function batches(Collection $chunks): array
    {
        $batches = [];
        $batch = null;
        $characters = 0;
        foreach ($chunks as $chunk) {
            $length = mb_strlen($chunk->content);
            if ($batch !== null && ($batch->count() >= 12 || $characters + $length > 14400)) {
                $batches[] = $batch;
                $batch = null;
                $characters = 0;
            }
            if ($batch === null) {
                $batch = new Collection([$chunk]);
            } else {
                $batch->push($chunk);
            }
            $characters += $length;
        }
        if ($batch !== null) {
            $batches[] = $batch;
        }

        return $batches === [] ? [$chunks] : $batches;
    }
}
