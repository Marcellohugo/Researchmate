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

        return $this->search->search($project, $this->ai->generateEmbedding($question), (int) config('ai.retrieval_limit', 5));
    }
}
