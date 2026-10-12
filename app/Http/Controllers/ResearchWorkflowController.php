<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunResearchWorkflow;
use App\Models\ResearchProject;
use App\Services\AI\WorkflowStateManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ResearchWorkflowController extends Controller
{
    public function __construct(private WorkflowStateManager $states) {}

    public function start(ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $run = $this->states->start($project);
        RunResearchWorkflow::dispatch($run->id);

        return to_route('projects.show', $project)->with('status', 'Alur riset masuk antrean. Pantau proses melalui monitor agen.');
    }

    public function resume(ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $run = $this->states->resume($project);
        RunResearchWorkflow::dispatch($run->id);

        return to_route('projects.show', $project)->with('status', 'Alur dilanjutkan dari tahap terakhir yang tersimpan.');
    }

    public function select(ResearchProject $project, Request $request): RedirectResponse
    {
        Gate::authorize('update', $project);
        $input = $request->validate(['paper_ids' => 'required|array|min:1|max:'.(int) config('ai.max_papers', 20), 'paper_ids.*' => 'required|integer|distinct']);
        $run = $this->states->select($project, array_values(array_map('intval', $input['paper_ids'])));
        RunResearchWorkflow::dispatch($run->id);

        return to_route('projects.show', $project)->with('status', 'Pemilihan artikel tersimpan. Analisis masuk antrean.');
    }

    public function approve(ResearchProject $project, Request $request): RedirectResponse
    {
        Gate::authorize('update', $project);
        $request->validate(['approval' => 'accepted']);
        $this->states->approve($project);

        return to_route('projects.reports', $project)->with('status', 'Persetujuan manusia tersimpan. Hasil riset siap diekspor.');
    }

    public function cancel(ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->states->cancel($project);

        return to_route('projects.show', $project)->with('status', 'Alur dibatalkan. Hasil tahap yang selesai tetap tersimpan.');
    }
}
