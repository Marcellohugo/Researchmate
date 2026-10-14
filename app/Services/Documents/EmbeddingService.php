<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\PaperChunk;
use App\Models\ResearchProject;
use App\Services\AI\SenopatiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EmbeddingService
{
    public function __construct(private PdfParserService $parser, private DocumentChunkerService $chunker, private SenopatiService $ai, private EvidenceInvalidationService $evidenceInvalidation) {}

    public function process(Document $document, bool $forceReindex = false): void
    {
        if ($document->project->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw new RuntimeException('Indeks tidak dapat diganti saat alur riset masih berjalan.');
        }
        $deadline = microtime(true) + 1500;
        $pages = $this->parser->extract(Storage::disk('local')->path($document->storage_path));
        if ($pages === []) {
            throw new RuntimeException('PDF tidak memiliki teks yang dapat diekstrak.');
        }
        $chunks = $this->chunker->chunk($pages);
        if ($chunks === []) {
            throw new RuntimeException('Tidak ada potongan teks yang dapat diindeks.');
        }
        if (count($chunks) > (int) config('ai.max_document_chunks', 1000)) {
            throw new RuntimeException('PDF melewati batas aman jumlah potongan teks. Pisahkan dokumen menjadi berkas yang lebih kecil.');
        }
        $model = (string) config('ai.embed_model');
        $vectors = [];
        $checkpointKeys = [];
        foreach (array_chunk($chunks, 8) as $batch) {
            $texts = array_column($batch, 'content');
            $key = 'document-embeddings:'.$document->id.':'.hash('sha256', json_encode([$document->file_hash, $model, $texts], JSON_THROW_ON_ERROR));
            $checkpointKeys[] = $key;
            $batchVectors = Cache::remember($key, now()->addDay(), function () use ($texts, $deadline): array {
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 1) {
                    throw new RuntimeException('Batas waktu indeks tercapai. Batch yang berhasil disimpan untuk percobaan berikutnya.');
                }

                return $this->ai->generateEmbeddings($texts, min($remaining, (int) config('ai.senopati_timeout', 300)));
            });
            array_push($vectors, ...$batchVectors);
        }
        $dimensions = count($vectors[0]);
        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                foreach ($checkpointKeys as $key) {
                    Cache::forget($key);
                }
                throw new RuntimeException('Dimensi embedding berubah antar kelompok teks. Indeks lama dipertahankan.');
            }
        }
        if (! $forceReindex && PaperChunk::query()->whereHas('document', fn ($q) => $q->where('project_id', $document->project_id))
            ->where('embedding_model', $model)->where('embedding_dimensions', '!=', $dimensions)->exists()) {
            throw new RuntimeException('Dimensi model berbeda dari indeks proyek. Jalankan ai:reindex dengan --force.');
        }
        DB::transaction(function () use ($document, $chunks, $vectors, $dimensions, $model, $pages): void {
            $project = ResearchProject::query()->whereKey($document->project_id)->lockForUpdate()->firstOrFail();
            if ($project->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw new RuntimeException('Indeks tidak dapat diganti saat alur riset masih berjalan.');
            }
            $locked = Document::query()->lockForUpdate()->find($document->id);
            if ($locked === null) {
                throw new RuntimeException('Dokumen dihapus sebelum proses indeks selesai.');
            }
            if ($locked->chunks()->exists()) {
                $this->evidenceInvalidation->invalidate($locked);
            }
            $locked->chunks()->delete();
            foreach ($chunks as $index => $chunk) {
                $locked->chunks()->create($chunk + [
                    'embedding' => json_encode($vectors[$index], JSON_THROW_ON_ERROR),
                    'embedding_model' => $model, 'embedding_dimensions' => $dimensions,
                ]);
            }
            $locked->update(['extraction_status' => 'indexed', 'page_count' => max(array_column($pages, 'page_number')), 'error_message' => null]);
        });
        foreach ($checkpointKeys as $key) {
            Cache::forget($key);
        }
    }
}
