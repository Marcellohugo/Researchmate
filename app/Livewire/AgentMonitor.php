<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ResearchProject;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class AgentMonitor extends Component
{
    public ResearchProject $project;

    public function mount(ResearchProject $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
    }

    public function render(): View
    {
        Gate::authorize('view', $this->project);
        $run = $this->project->runs()->latest('id')->first();

        return view('livewire.agent-monitor', ['run' => $run, 'steps' => $run?->steps()->orderBy('id')->get() ?? collect(), 'agents' => ['planner' => 'Perencana Riset', 'discovery' => 'Penelusuran Literatur', 'analysis' => 'Analisis Dokumen', 'synthesis' => 'Sintesis Literatur', 'research-gap' => 'Kandidat Celah Riset', 'verification' => 'Verifikasi Bukti']]);
    }
}
