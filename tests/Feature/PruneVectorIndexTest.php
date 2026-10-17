<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PruneVectorIndex;
use App\Services\Documents\QdrantService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PruneVectorIndexTest extends TestCase
{
    public function test_cleanup_targets_the_deleted_document_or_project(): void
    {
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('deleteDocument')->once()->with(12);
        $qdrant->shouldReceive('deleteProject')->once()->with(5);

        (new PruneVectorIndex(5, 12))->handle($qdrant);
        (new PruneVectorIndex(5))->handle($qdrant);
    }

    public function test_cleanup_failure_reaches_queue_retry(): void
    {
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('deleteProject')->once()->with(5)->andThrow(new RuntimeException('Qdrant unavailable'));

        $this->expectException(RuntimeException::class);
        (new PruneVectorIndex(5))->handle($qdrant);
    }
}
