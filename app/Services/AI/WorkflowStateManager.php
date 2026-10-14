<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ResearchProject;
use App\Models\ResearchRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowStateManager
{
    public const STAGES = ['planning', 'discovering', 'analyzing', 'synthesizing', 'identifying_gaps', 'verifying'];

    public function start(ResearchProject $project): ResearchRun
    {
        return DB::transaction(function () use ($project): ResearchRun {
            $locked = ResearchProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = ResearchRun::query()->where('project_id', $project->id)->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['workflow' => 'Alur proyek ini belum selesai. Gunakan tombol lanjutkan atau batalkan alur terlebih dahulu.']);
            }
            if ($locked->documents()->whereIn('extraction_status', ['queued', 'processing', 'pending'])->exists()) {
                throw ValidationException::withMessages(['workflow' => 'Tunggu pengindeksan PDF selesai sebelum memulai alur riset.']);
            }
            $project->update(['status' => 'in_progress']);

            return ResearchRun::query()->create(['project_id' => $project->id, 'status' => 'pending', 'current_stage' => 'planning', 'workflow_state' => [
                'tool_call_count' => 0, 'revision_count' => 0,
                'project_snapshot' => $locked->only(['title', 'research_question', 'research_mode']),
            ], 'retry_count' => 0]);
        });
    }

    public function resume(ResearchProject $project): ResearchRun
    {
        return DB::transaction(function () use ($project): ResearchRun {
            ResearchProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $run = ResearchRun::query()->where('project_id', $project->id)->latest('id')->lockForUpdate()->firstOrFail();
            if (in_array($run->status, ['completed', 'cancelled', 'awaiting_paper_selection', 'awaiting_approval'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Tahap ini memerlukan tindakan pada checkpoint atau sudah selesai.']);
            }
            if ($run->status === 'failed') {
                $run->update(['status' => $run->current_stage, 'retry_count' => 0]);
            }

            return $run;
        });
    }

    /** @param list<int> $paperIds */
    public function select(ResearchProject $project, array $paperIds): ResearchRun
    {
        return DB::transaction(function () use ($project, $paperIds): ResearchRun {
            ResearchProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $run = ResearchRun::query()->where('project_id', $project->id)->latest('id')->lockForUpdate()->firstOrFail();
            if ($run->status !== 'awaiting_paper_selection') {
                throw ValidationException::withMessages(['workflow' => 'Pemilihan artikel hanya tersedia setelah penelusuran selesai.']);
            }
            $paperIds = array_values(array_unique($paperIds));
            $owned = $project->papers()->whereIn('papers.id', $paperIds)->pluck('papers.id')->all();
            if ($paperIds === [] || count($paperIds) > min($project->target_papers, (int) config('ai.max_papers', 20)) || count($owned) !== count($paperIds)) {
                throw ValidationException::withMessages(['paper_ids' => 'Pilih artikel dari proyek ini sesuai batas jumlah artikel.']);
            }
            $project->papers()->newPivotStatement()->where('project_id', $project->id)->update(['is_selected' => false]);
            foreach ($paperIds as $id) {
                $project->papers()->updateExistingPivot($id, ['is_selected' => true, 'analysis_status' => 'pending']);
            }
            $state = $run->workflow_state ?? [];
            $state['selected_paper_ids'] = $paperIds;
            $state['paper_selection_at'] = now()->toIso8601String();
            $run->update(['status' => 'analyzing', 'current_stage' => 'analyzing', 'workflow_state' => $state]);

            return $run;
        });
    }

    public function approve(ResearchProject $project): ResearchRun
    {
        return DB::transaction(function () use ($project): ResearchRun {
            ResearchProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $run = ResearchRun::query()->where('project_id', $project->id)->latest('id')->lockForUpdate()->firstOrFail();
            if ($run->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['workflow' => 'Hasil belum berada pada tahap persetujuan.']);
            }
            $state = $run->workflow_state ?? [];
            $state['approved_by'] = $project->user_id;
            $state['approved_at'] = now()->toIso8601String();
            $run->update(['status' => 'completed', 'finished_at' => now(), 'workflow_state' => $state]);
            $project->update(['status' => 'completed']);

            return $run;
        });
    }

    public function cancel(ResearchProject $project): void
    {
        DB::transaction(function () use ($project): void {
            ResearchProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $run = ResearchRun::query()->where('project_id', $project->id)->latest('id')->lockForUpdate()->firstOrFail();
            if (in_array($run->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Alur ini sudah selesai.']);
            }
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);
            $project->update(['status' => 'draft']);
        });
    }
}
