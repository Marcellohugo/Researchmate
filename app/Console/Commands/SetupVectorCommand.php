<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\SenopatiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetupVectorCommand extends Command
{
    protected $signature = 'ai:setup-vector';

    protected $description = 'Validasi embedding nyata dan siapkan kolom pgvector tanpa menghapus indeks lama.';

    public function handle(SenopatiService $ai): int
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $this->error('Perintah ini memerlukan PostgreSQL.');

                return self::FAILURE;
            }
            if (DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'") === null) {
                $this->error('Pasang pgvector lalu jalankan CREATE EXTENSION vector sebagai administrator pada database ini.');

                return self::FAILURE;
            }
            $dimensions = count($ai->generateEmbedding('Validasi dimensi embedding ResearchMate.'));
            $column = DB::selectOne("SELECT format_type(a.atttypid,a.atttypmod) AS type FROM pg_attribute a JOIN pg_class c ON a.attrelid=c.oid JOIN pg_namespace n ON c.relnamespace=n.oid WHERE c.relname='paper_chunks' AND n.nspname=current_schema() AND a.attname='embedding'");
            $type = $column->type ?? '';
            if ($type === 'text' || str_starts_with($type, 'vector(')) {
                DB::transaction(fn () => DB::statement('ALTER TABLE paper_chunks ALTER COLUMN embedding TYPE vector USING embedding::vector'));
            } elseif ($type !== 'vector') {
                $this->error('Kolom embedding tidak ditemukan atau memiliki tipe yang tidak didukung. Jalankan migrasi terlebih dahulu.');

                return self::FAILURE;
            }
            $this->info('Kolom vector siap. Dimensi model terdeteksi: '.$dimensions.'. Indeks disaring menurut model dan dimensi.');
            $this->line('Jika model berubah, jalankan ai:reindex {project} --force. Indeks lama dipertahankan sampai pengganti berhasil.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
