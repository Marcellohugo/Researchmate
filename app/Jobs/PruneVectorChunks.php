<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Documents\QdrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneVectorChunks implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param list<array{id: int, embedding_model: string, embedding_dimensions: int}> $chunks */
    public function __construct(public array $chunks) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(QdrantService $qdrant): void
    {
        $qdrant->deleteChunks($this->chunks);
    }
}
