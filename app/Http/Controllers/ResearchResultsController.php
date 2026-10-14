<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaperChunk;
use App\Models\ResearchArtifact;
use App\Models\ResearchProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResearchResultsController extends Controller
{
    public function matrix(ResearchProject $project, Request $request): View
    {
        Gate::authorize('view', $project);
        $input = $request->validate(['q' => 'nullable|string|max:200', 'sort' => 'nullable|in:title,year,method', 'direction' => 'nullable|in:asc,desc']);
        $run = $project->runs()->latest('id')->first();
        $analyses = $run === null ? collect() : $project->analyses()->where('run_id', $run->id)->with('paper')->get();
        if (! empty($input['q'])) {
            $query = mb_strtolower($input['q']);
            $analyses = $analyses->filter(fn ($analysis) => str_contains(mb_strtolower($analysis->paper->title.' '.json_encode($analysis->structured_analysis, JSON_UNESCAPED_UNICODE)), $query));
        }
        $analyses = $analyses->sortBy(fn ($analysis) => match ($input['sort'] ?? 'title') {
            'year' => $analysis->paper->publication_year ?? 0,
            'method' => $analysis->structured_analysis['methodology'] ?? '',
            default => $analysis->paper->title,
        }, SORT_REGULAR, ($input['direction'] ?? 'asc') === 'desc');

        return view('research.matrix', compact('project', 'analyses'));
    }

    public function gaps(ResearchProject $project): View
    {
        Gate::authorize('view', $project);

        $run = $project->runs()->latest('id')->first();

        return view('research.gaps', ['project' => $project, 'gaps' => $run === null ? collect() : $project->gaps()->where('run_id', $run->id)->latest('id')->get()]);
    }

    public function reports(ResearchProject $project): View
    {
        Gate::authorize('view', $project);

        $latestRun = $project->runs()->latest('id')->first();

        return view('research.reports', ['project' => $project, 'artifacts' => $project->artifacts()->latest('id')->get(), 'latestRun' => $latestRun, 'claims' => $latestRun === null ? collect() : $project->claims()->where('run_id', $latestRun->id)->with('evidence.paper')->latest('id')->get()]);
    }

    public function export(ResearchProject $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        $run = $project->runs()->latest('id')->first();
        if ($run === null || ! in_array($run->status, ['awaiting_approval', 'completed'], true)) {
            return back()->withErrors(['report' => 'Selesaikan verifikasi sebelum mengekspor laporan.']);
        }
        $plan = $run->research_plan ?: ($run->workflow_state['planning'] ?? []);
        $lines = ['# '.$project->title, '', '**Pertanyaan riset:** '.$project->research_question, '', '**Status alur:** '.$run->status, '**Status laporan:** '.($run->status === 'completed' ? 'Disetujui oleh pengguna.' : 'Draf; menunggu persetujuan pengguna.'), '**Mode literatur:** '.$project->research_mode, '', 'Celah yang ditampilkan merupakan kandidat berdasarkan koleksi proyek, bukan bukti bahwa suatu topik belum pernah diteliti.', '', '## Rencana Penelitian', '', '```json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '```', '', '## Batas Analisis dan Verifikasi', '', 'Mode literatur offline membatasi sumber pada dokumen lokal. Chat, agen, dan embedding memakai gateway Senopati dan memerlukan internet.', '', 'Analisis PDF menggunakan paling banyak 12 cuplikan awal per artikel. Status full_text menunjukkan sumber PDF tersedia, bukan bahwa seluruh isi PDF telah dianalisis. Informasi di luar cuplikan dapat terlewat.', '', 'Pencocokan kutipan memastikan teks bukti terdapat dalam sumber proyek. Penilaian hubungan makna antara klaim dan bukti dilakukan model dan tetap memerlukan tinjauan ilmiah oleh manusia. Status verified bukan jaminan kebenaran ilmiah atau kualitas penelitian.', '', 'Metadata offline tidak diverifikasi melalui layanan eksternal. Persetujuan pengguna tidak mengubah keterbatasan bukti dan metadata.', '', '## Sintesis', '', (string) ($run->workflow_state['synthesizing']['synthesis'] ?? 'Belum tersedia.'), '', '## Matriks Literatur', ''];
        foreach ($project->analyses()->where('run_id', $run->id)->with('paper')->get() as $analysis) {
            $lines[] = '### '.$analysis->paper->title;
            $lines[] = 'Sumber: '.($analysis->paper->doi ? 'https://doi.org/'.$analysis->paper->doi : ($analysis->paper->source_url ?: 'Metadata lokal; DOI belum tersedia.'));
            $lines[] = 'Akses sumber: '.$analysis->evidence_level;
            $lines[] = 'Status verifikasi metadata: '.$analysis->paper->metadata_verification_status;
            $lines[] = '```json';
            $lines[] = json_encode($analysis->structured_analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $lines[] = '```';
        }
        $lines = array_merge($lines, ['', '## Kandidat Celah', '']);
        foreach ($project->gaps()->where('run_id', $run->id)->get() as $gap) {
            $lines[] = '### '.$gap->title;
            $lines[] = $gap->description;
            $lines[] = '**Status bukti:** '.$gap->review_status;
            $lines[] = '**Pertanyaan lanjutan:** '.$gap->proposed_question;
            $lines[] = '**Keterbatasan:** '.($gap->supporting_evidence['limitations'] ?? 'Perlu tinjauan manusia.');
            $lines[] = json_encode($gap->supporting_evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $lines = array_merge($lines, ['', '## Verifikasi Klaim', '']);
        foreach ($project->claims()->where('run_id', $run->id)->with('evidence')->get() as $claim) {
            $lines[] = '- '.$claim->claim_text.' ['.$claim->verification_status.']';
            foreach ($claim->evidence as $evidence) {
                $lines[] = '  - Artikel #'.$evidence->paper_id.'; bagian #'.($evidence->chunk_id ?? 'abstrak').'; kutipan: '.$evidence->evidence_text;
            }
        }
        $lines = array_merge($lines, ['', '## Catatan Verifikasi Akhir', '', 'Jumlah putaran revisi: '.($run->workflow_state['revision_count'] ?? 0), 'Batas revisi tercapai: '.(($run->workflow_state['revision_limit_reached'] ?? false) ? 'Ya; tinjau masalah yang belum terselesaikan.' : 'Tidak.'), '', '```json', json_encode($run->workflow_state['verifying'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '```']);
        $path = 'users/'.$project->user_id.'/projects/'.$project->id.'/reports/'.Str::uuid().'.md';
        if (! Storage::disk('local')->put($path, implode("\n", $lines)."\n")) {
            throw new \RuntimeException('Laporan tidak dapat disimpan pada penyimpanan lokal.');
        }
        try {
            $project->artifacts()->create(['run_id' => $run->id, 'title' => $project->title, 'artifact_type' => 'markdown_report', 'storage_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return to_route('projects.reports', $project)->with('status', 'Laporan Markdown tersimpan pada penyimpanan privat.');
    }

    public function download(ResearchProject $project, ResearchArtifact $artifact): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($artifact->project_id === $project->id, 404);
        $prefix = 'users/'.$project->user_id.'/projects/'.$project->id.'/reports/';
        abort_unless(str_starts_with($artifact->storage_path, $prefix) && preg_match('/^[a-f0-9-]{36}\.md$/', substr($artifact->storage_path, strlen($prefix))) === 1, 404);
        abort_unless(Storage::disk('local')->exists($artifact->storage_path), 404);

        return Storage::disk('local')->download($artifact->storage_path, (Str::slug($project->title) ?: 'laporan-riset').'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    public function evidence(ResearchProject $project, PaperChunk $chunk): View
    {
        Gate::authorize('view', $project);
        abort_unless($chunk->document->project_id === $project->id, 404);

        return view('research.evidence', compact('project', 'chunk'));
    }
}
