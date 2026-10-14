<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaperChunk;
use App\Models\ResearchArtifact;
use App\Models\ResearchProject;
use App\Services\Academic\ProjectPaperMetadata;
use App\Services\Reports\ReportDocumentRenderer;
use App\Services\Reports\ResearchReportContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResearchResultsController extends Controller
{
    public function matrix(ResearchProject $project, Request $request, ProjectPaperMetadata $metadata): View
    {
        Gate::authorize('view', $project);
        $input = $request->validate(['q' => 'nullable|string|max:200', 'sort' => 'nullable|in:title,year,method', 'direction' => 'nullable|in:asc,desc']);
        $run = $project->runs()->latest('id')->first();
        $analyses = $run === null ? collect() : $project->analyses()->where('run_id', $run->id)->with('paper')->get();
        foreach ($analyses as $analysis) {
            $analysis->setRelation('paper', $metadata->apply($analysis->paper, $project));
        }
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

    public function reports(ResearchProject $project, ProjectPaperMetadata $metadata): View
    {
        Gate::authorize('view', $project);

        $latestRun = $project->runs()->latest('id')->first();
        $claims = $latestRun === null ? collect() : $project->claims()->where('run_id', $latestRun->id)->with('evidence.paper')->latest('id')->get();
        foreach ($claims as $claim) {
            foreach ($claim->evidence as $evidence) {
                $evidence->setRelation('paper', $metadata->apply($evidence->paper, $project));
            }
        }

        return view('research.reports', ['project' => $project, 'artifacts' => $project->artifacts()->latest('id')->get(), 'latestRun' => $latestRun, 'claims' => $claims]);
    }

    public function export(ResearchProject $project, Request $request, ResearchReportContent $content, ReportDocumentRenderer $renderer): RedirectResponse
    {
        Gate::authorize('view', $project);
        $input = $request->validate(['format' => 'nullable|in:markdown,docx,pdf', 'citation_style' => 'nullable|in:apa,ieee']);
        $run = $project->runs()->latest('id')->first();
        if ($run === null || ! in_array($run->status, ['awaiting_approval', 'completed'], true)) {
            return back()->withErrors(['report' => 'Selesaikan verifikasi sebelum mengekspor laporan.']);
        }
        $format = $input['format'] ?? 'markdown';
        $style = $input['citation_style'] ?? 'apa';
        $report = $content->build($project, $run, $style);
        $bytes = $format === 'markdown' ? $content->markdown($report) : $renderer->render($report, $format);
        $extension = $format === 'markdown' ? 'md' : $format;
        $path = 'users/'.$project->user_id.'/projects/'.$project->id.'/reports/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Laporan tidak dapat disimpan pada penyimpanan privat.');
        }
        try {
            $project->artifacts()->create(['run_id' => $run->id, 'title' => $report['title'], 'artifact_type' => match ($format) {
                'docx' => 'word_report', 'pdf' => 'pdf_report', default => 'markdown_report',
            }, 'storage_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return to_route('projects.reports', $project)->with('status', 'Laporan '.match ($format) {
            'docx' => 'Word', 'pdf' => 'PDF', default => 'Markdown',
        }.' tersimpan pada penyimpanan privat.');
    }

    public function download(ResearchProject $project, ResearchArtifact $artifact): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($artifact->project_id === $project->id, 404);
        [$extension, $mime] = match ($artifact->artifact_type) {
            'markdown_report' => ['md', 'text/markdown; charset=UTF-8'],
            'word_report' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'pdf_report' => ['pdf', 'application/pdf'],
            default => abort(404),
        };
        $prefix = 'users/'.$project->user_id.'/projects/'.$project->id.'/reports/';
        abort_unless(str_starts_with($artifact->storage_path, $prefix) && preg_match('/^[a-f0-9-]{36}\\.'.preg_quote($extension, '/').'$/', substr($artifact->storage_path, strlen($prefix))) === 1, 404);
        abort_unless(Storage::disk('local')->exists($artifact->storage_path), 404);

        return Storage::disk('local')->download($artifact->storage_path, (Str::slug($artifact->title ?: $project->title) ?: 'laporan-riset').'.'.$extension, ['Content-Type' => $mime]);
    }

    public function evidence(ResearchProject $project, PaperChunk $chunk): View
    {
        Gate::authorize('view', $project);
        abort_unless($chunk->document->project_id === $project->id, 404);

        return view('research.evidence', compact('project', 'chunk'));
    }
}
