<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\ResearchProject;
use App\Services\Academic\CitationFormatter;
use App\Services\Academic\OpenAlexService;
use App\Services\Academic\ProjectPaperMetadata;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LiteratureController extends Controller
{
    public function index(ResearchProject $project, ProjectPaperMetadata $metadata): View
    {
        Gate::authorize('view', $project);

        return view('research.literature', ['project' => $project, 'papers' => $project->papers()->orderByPivot('relevance_score', 'desc')->get()->map(fn (Paper $paper): Paper => $metadata->apply($paper, $project)), 'latestRun' => $project->runs()->latest('id')->first()]);
    }

    public function edit(ResearchProject $project, Paper $paper, ProjectPaperMetadata $metadata): View
    {
        Gate::authorize('update', $project);
        abort_unless($project->papers()->whereKey($paper->id)->exists(), 404);

        return view('research.paper-edit', ['project' => $project, 'paper' => $metadata->apply($paper, $project), 'activeRun' => $project->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()]);
    }

    public function update(ResearchProject $project, Paper $paper, Request $request): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->papers()->whereKey($paper->id)->exists(), 404);
        $input = $request->validate([
            'title' => ['required', 'string', 'max:2000'],
            'authors' => ['nullable', 'string', 'max:25500'],
            'publication_year' => ['nullable', 'integer', 'min:1500', 'max:'.(now()->year + 1)],
            'doi' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (OpenAlexService::normalizeDoi(is_string($value) ? $value : null) === null) {
                    $fail('DOI harus berupa 10.xxxx/identitas atau tautan https://doi.org/ yang valid.');
                }
            }],
            'journal_name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:4000', 'url:http,https', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
                    $fail('URL sumber harus berupa tautan HTTP/HTTPS tanpa kredensial.');
                }
            }],
            'volume' => ['nullable', 'string', 'max:50'],
            'issue' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'string', 'max:80'],
        ], [], ['title' => 'judul', 'authors' => 'penulis', 'publication_year' => 'tahun terbit', 'journal_name' => 'jurnal', 'source_url' => 'URL sumber', 'volume' => 'volume', 'issue' => 'nomor', 'pages' => 'halaman']);
        $authors = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input['authors'] ?? '') ?: []), fn (string $name): bool => $name !== ''));
        if (count($authors) > 100 || collect($authors)->contains(fn (string $name): bool => mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1)) {
            throw ValidationException::withMessages(['authors' => 'Isi satu penulis per baris, maksimal 100 penulis dan 255 karakter per nama.']);
        }
        $input['authors'] = $authors;
        $input['doi'] = OpenAlexService::normalizeDoi($input['doi'] ?? null);
        $input['publication_year'] = isset($input['publication_year']) ? (int) $input['publication_year'] : null;
        $input['metadata_source'] = 'user_reviewed';
        $input['metadata_verification_status'] = 'metadata_human_reviewed';
        DB::transaction(function () use ($project, $paper, $request, $input): void {
            $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['workflow' => 'Metadata tidak dapat diubah saat alur riset masih berjalan. Selesaikan atau batalkan alur terlebih dahulu.']);
            }
            $entry = $locked->projectPapers()->where('paper_id', $paper->id)->lockForUpdate()->firstOrFail();
            $previous = $entry->metadata_overrides;
            $entry->update(['metadata_overrides' => $input]);
            $locked->history()->create(['user_id' => $request->user()?->id, 'action' => 'paper_metadata_updated', 'payload' => ['paper_id' => $paper->id, 'before' => $previous, 'after' => $input]]);
        });

        return to_route('projects.literature.index', $project)->with('status', 'Metadata artikel diperbarui untuk proyek ini. Hasil lama tetap memerlukan tinjauan; metadata belum diverifikasi secara eksternal.');
    }

    public function references(ResearchProject $project, Request $request, ProjectPaperMetadata $metadata, CitationFormatter $formatter): View|Response
    {
        Gate::authorize('view', $project);
        $input = $request->validate(['style' => ['nullable', 'in:apa,ieee'], 'download' => ['nullable', 'boolean']]);
        $style = $input['style'] ?? 'apa';
        $papers = $project->papers()->get()->map(fn (Paper $paper): Paper => $metadata->apply($paper, $project))
            ->sortBy(fn (Paper $paper): string => mb_strtolower($style === 'apa' ? $formatter->format($paper, $style) : $paper->title))->values();
        $citations = $papers->map(fn (Paper $paper): string => $formatter->format($paper, $style));
        if ($request->boolean('download')) {
            $lines = $citations->map(fn (string $citation, int $index): string => $style === 'ieee' ? '['.($index + 1).'] '.$citation : $citation);

            return response($lines->implode("\n\n")."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="daftar-pustaka-'.$project->id.'-'.$style.'.txt"', 'X-Content-Type-Options' => 'nosniff']);
        }

        return view('research.bibliography', compact('project', 'papers', 'citations', 'style'));
    }

    public function search(ResearchProject $project, Request $request, OpenAlexService $openalex): RedirectResponse
    {
        Gate::authorize('update', $project);
        $input = $request->validate(['query' => 'required|string|max:500', 'year_from' => 'nullable|integer|min:1500|max:'.(now()->year + 1), 'year_to' => 'nullable|integer|min:1500|max:'.(now()->year + 1).'|gte:year_from']);
        if ($project->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            return back()->withInput()->withErrors(['query' => 'Penelusuran literatur tidak dapat mengubah koleksi saat alur riset masih berjalan.']);
        }
        if ($project->research_mode === 'offline') {
            return to_route('projects.literature.index', $project)->withErrors(['query' => 'Proyek luring hanya menggunakan PDF lokal. Ubah mode proyek untuk penelusuran OpenAlex.']);
        }
        try {
            $records = $openalex->search($input['query'], isset($input['year_from']) ? (int) $input['year_from'] : $project->year_from, isset($input['year_to']) ? (int) $input['year_to'] : $project->year_to, $project->target_papers);
            $ids = $openalex->save($project, $records);
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['query' => $exception->getMessage()]);
        }

        return to_route('projects.literature.index', $project)->with('status', count($ids).' artikel ditemukan dan ditambahkan ke proyek. Tinjau metadata sebelum analisis.');
    }
}
