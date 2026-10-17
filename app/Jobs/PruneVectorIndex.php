<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Documents\QdrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneVectorIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 1800;

    public function __construct(public int $projectId, public ?int $documentId = null) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(QdrantService $qdrant): void
    {
        if ($this->documentId !== null) {
            $qdrant->deleteDocument($this->documentId);
        } else {
            $qdrant->deleteProject($this->projectId);
        }
    }
}
