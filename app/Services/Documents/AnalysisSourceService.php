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
        $chunks = (clone $query)->get(['id', 'document_id', 'chunk_index', 'section_name']);
        $selected = $chunks->modelKeys();

        if ($chunks->count() > 12) {
            // shortcut: twelve excerpts cannot cover every detail; use question-based retrieval for deeper analysis.
            $selected = $chunks->take(2)->modelKeys();
            foreach (['methods?|metodologi|metode', 'results?|hasil', 'discussion|pembahasan', 'conclusions?|kesimpulan', 'limitations?|keterbatasan'] as $section) {
                $matches = $chunks->filter(fn (PaperChunk $chunk): bool => preg_match('/^(?:'.$section.')$/iu', $chunk->section_name ?? '') === 1);
                if ($matches->isNotEmpty()) {
                    $selected[] = $matches->first()->id;
                    $selected[] = $matches->last()->id;
                }
            }
            $selected = array_values(array_unique($selected));
            if (count($selected) < 12) {
                $selected[] = $chunks->last()->id;
                $selected = array_values(array_unique($selected));
            }
            for ($position = 0; count($selected) < 12 && $position < 12; $position++) {
                $sample = $chunks->get((int) round($position * ($chunks->count() - 1) / 11));
                $selected[] = $sample->id;
                $selected = array_values(array_unique($selected));
            }
        }

        return $query->whereKey($selected)->get(['id', 'document_id', 'page_number', 'section_name', 'content']);
    }
}
