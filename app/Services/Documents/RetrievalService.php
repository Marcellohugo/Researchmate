<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\ResearchProject;
use App\Services\AI\SenopatiService;

class RetrievalService
{
    public function __construct(private SenopatiService $ai, private VectorSearchService $search) {}

    /** @return list<array<string, mixed>> */
    public function retrieve(ResearchProject $project, string $question): array
    {
        if (! $project->documents()->where('extraction_status', 'indexed')->exists()) {
            return [];
        }

        $input = 'Topik riset: '.mb_substr($project->topic, 0, 255)
            ."\nPertanyaan riset: ".mb_substr($project->research_question, 0, 1000)
            ."\nKata kunci: ".mb_substr($project->keywords ?? '', 0, 500)
            ."\nPertanyaan pengguna: ".mb_substr($question, 0, 4000);

        return $this->search->search($project, $this->ai->generateEmbedding(mb_substr($input, 0, 6000)), (int) config('ai.retrieval_limit', 5));
    }
}
