<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\SenopatiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiHealthCommand extends Command
{
    protected $signature = 'ai:check';

    protected $description = 'Periksa chat dan embedding gateway Senopati serta pgvector.';

    public function handle(SenopatiService $ai): int
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
                $this->error('Database produksi harus menggunakan PostgreSQL dengan pgvector.');

                return self::FAILURE;
            }
            $column = DB::selectOne("SELECT format_type(a.atttypid,a.atttypmod) AS type FROM pg_attribute a JOIN pg_class c ON a.attrelid=c.oid JOIN pg_namespace n ON c.relnamespace=n.oid WHERE c.relname='paper_chunks' AND n.nspname=current_schema() AND a.attname='embedding'");
            $type = $column->type ?? '';
            if ($type !== 'vector' && $type !== 'vector('.count($embedding).')') {
                $this->error('Kolom paper_chunks.embedding bukan vector dengan dimensi yang sesuai.');

                return self::FAILURE;
            }
            $this->info('Model AI dan kolom pgvector siap.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
