<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessDocument;
use App\Models\Paper;
use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SeedResearchDataCommand extends Command
{
    protected $signature = 'research:seed-data {user : ID pengguna pemilik proyek uji}';

    protected $description = 'Tambahkan proyek dan lima PDF fixture, tanpa menimpa data yang sudah ada.';

    public function handle(): int
    {
        $user = User::query()->find($this->argument('user'));
        if ($user === null) {
            $this->error('Pengguna tidak ditemukan. Gunakan ID akun yang sudah terdaftar.');

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents(database_path('fixtures/dummy-research/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $createdPaths = [];
        try {
            $project = DB::transaction(function () use ($user, $manifest, &$createdPaths): ResearchProject {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $project = $user->projects()->where('settings->fixture', 'researchmate-dummy-v1')->first();
                if ($project === null) {
                    $project = $user->projects()->create($manifest['project']);
                    $project->history()->create(['user_id' => $user->id, 'action' => 'created', 'payload' => ['fixture' => 'researchmate-dummy-v1', 'synthetic' => true]]);
                }
                foreach ($manifest['papers'] as $sample) {
                    if ($project->documents()->where('original_filename', $sample['filename'])->exists()) {
                        continue;
                    }
                    $pdf = file_get_contents(database_path('fixtures/dummy-research/'.$sample['filename']));
                    if ($pdf === false || ! str_starts_with($pdf, '%PDF-')) {
                        throw new RuntimeException('PDF fixture tidak tersedia atau tidak valid.');
                    }
                    $path = 'projects/'.$project->id.'/documents/fixtures/'.$sample['filename'];
                    if (! Storage::disk('local')->put($path, $pdf)) {
                        throw new RuntimeException('PDF fixture tidak dapat disimpan privat.');
                    }
                    $createdPaths[] = $path;
                    $paper = Paper::query()->create([
                        'title' => $sample['title'], 'authors' => [$sample['author']], 'publication_year' => $sample['year'],
                        'abstract' => $sample['sections']['Tujuan'], 'metadata_source' => 'synthetic_fixture', 'metadata_verification_status' => 'metadata_unverified',
                    ]);
                    $project->papers()->attach($paper->id, ['is_selected' => true, 'analysis_status' => 'pending']);
                    $document = $project->documents()->create([
                        'paper_id' => $paper->id, 'original_filename' => $sample['filename'], 'storage_path' => $path,
                        'mime_type' => 'application/pdf', 'file_size' => strlen($pdf), 'file_hash' => hash('sha256', $pdf), 'extraction_status' => 'queued',
                    ]);
                    ProcessDocument::dispatch($document->id)->afterCommit();
                }

                return $project;
            });
        } catch (Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $this->info('Proyek #'.$project->id.' tersedia dengan '.$project->documents()->count().' PDF. Dokumen baru masuk antrean indeks Senopati.');
        $this->line(url('/projects/'.$project->id));

        return self::SUCCESS;
    }
}
