<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ResearchProject;
use App\Services\Academic\OpenAlexService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LiteratureController extends Controller
{
    public function index(ResearchProject $project): View
    {
        Gate::authorize('view', $project);

        return view('research.literature', ['project' => $project, 'papers' => $project->papers()->orderByPivot('relevance_score', 'desc')->get(), 'latestRun' => $project->runs()->latest('id')->first()]);
    }

    public function search(ResearchProject $project, Request $request, OpenAlexService $openalex): RedirectResponse
    {
        Gate::authorize('update', $project);
        $input = $request->validate(['query' => 'required|string|max:500', 'year_from' => 'nullable|integer|min:1500|max:'.(now()->year + 1), 'year_to' => 'nullable|integer|min:1500|max:'.(now()->year + 1).'|gte:year_from']);
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
