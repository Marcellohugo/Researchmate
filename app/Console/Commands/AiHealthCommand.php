<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\SenopatiService;
use App\Services\Documents\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiHealthCommand extends Command
{
    protected $signature = 'ai:check';

    protected $description = 'Periksa chat dan embedding Senopati, PostgreSQL, serta Qdrant.';

    public function handle(SenopatiService $ai, QdrantService $qdrant): int
    {
        $status = $ai->checkConnection();
        $this->line($status['message']);
        if (! $status['connected'] || ! $status['chat_available'] || ! $status['embedding_available']) {
            return self::FAILURE;
        }
        try {
            $embedding = $ai->generateEmbedding('Pemeriksaan model embedding ResearchMate.');
            $this->line('Dimensi embedding terdeteksi: '.count($embedding));
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $this->error('Database aplikasi harus menggunakan PostgreSQL.');

                return self::FAILURE;
            }
            DB::selectOne('SELECT 1');
            $qdrant->checkCollection((string) config('ai.embed_model'), count($embedding));
            $this->info('Model AI, PostgreSQL, dan Qdrant siap.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
