<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Documents\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 6000;

    public function __construct(public int $documentId, public bool $forceReindex = false) {}

    public function uniqueId(): string
    {
        return 'document-index-'.$this->documentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(EmbeddingService $service): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null || $document->extraction_status === 'indexed') {
            return;
        }
        $document->update(['extraction_status' => 'processing', 'error_message' => null]);
        try {
            $service->process($document, $this->forceReindex);
        } catch (Throwable $exception) {
            $document->update(['extraction_status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Document::query()->whereKey($this->documentId)->update(['extraction_status' => 'failed', 'error_message' => mb_substr($exception?->getMessage() ?? 'Pekerjaan indeks gagal.', 0, 2000)]);
    }
}
