<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessDocument;
use App\Models\ResearchProject;
use App\Services\AI\SenopatiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReindexDocumentsCommand extends Command
{
    protected $signature = 'ai:reindex {project : ID proyek lokal} {--force : Setujui membangun ulang seluruh indeks proyek}';

    protected $description = 'Antrekan indeks ulang PDF setelah pergantian model atau dimensi embedding.';

    public function handle(SenopatiService $ai): int
    {
        if (! $this->option('force')) {
            $this->error('Gunakan --force untuk menyetujui indeks ulang. PDF asli tetap dipertahankan.');

            return self::FAILURE;
        }
        $project = ResearchProject::query()->find($this->argument('project'));
        if ($project === null) {
            $this->error('Proyek tidak ditemukan.');

            return self::FAILURE;
        }
        if ($project->documents()->whereIn('extraction_status', ['processing', 'queued'])->exists()) {
            $this->error('Tunggu pekerjaan dokumen aktif selesai sebelum indeks ulang.');

            return self::FAILURE;
        }
        try {
            $embedding = $ai->generateEmbedding('Validasi model sebelum indeks ulang.');
            $ids = $project->documents()->pluck('id');
            DB::transaction(function () use ($project, $ids): void {
                $project->documents()->update(['extraction_status' => 'queued', 'error_message' => null]);
                foreach ($ids as $id) {
                    ProcessDocument::dispatch((int) $id, true)->afterCommit();
                }
            });
            $this->info(count($ids).' PDF diantrekan dengan model '.config('ai.embed_model').' ('.count($embedding).' dimensi). Indeks lama diganti secara atomik setelah tiap PDF berhasil.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
