<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaperChunk;
use App\Services\AI\SenopatiService;
use App\Services\Documents\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetupVectorCommand extends Command
{
    protected $signature = 'ai:setup-vector';

    protected $description = 'Siapkan Qdrant dan sinkronkan embedding tersimpan tanpa menghapus data PostgreSQL.';

    public function handle(SenopatiService $ai, QdrantService $qdrant): int
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $this->error('Perintah ini memerlukan PostgreSQL.');

                return self::FAILURE;
            }
            $dimensions = count($ai->generateEmbedding('Validasi dimensi embedding ResearchMate.'));
            $qdrant->ensureCollection((string) config('ai.embed_model'), $dimensions);
            $count = 0;
            PaperChunk::query()->with('document')->whereNotNull('embedding')->whereNotNull('embedding_model')->whereNotNull('embedding_dimensions')
                ->chunkById(100, function ($chunks) use ($qdrant, &$count): void {
                    $qdrant->upsertChunks($chunks);
                    $count += $chunks->count();
                });
            $this->info('Qdrant siap. Dimensi model terdeteksi: '.$dimensions.'. '.$count.' potongan teks disinkronkan dari PostgreSQL.');
            $this->line('Jika model berubah, jalankan ai:reindex {project} --force. Indeks lama dipertahankan sampai pengganti berhasil.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
