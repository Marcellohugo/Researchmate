<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\RunResearchWorkflow;
use App\Models\AgentStep;
use App\Models\Claim;
use App\Models\PaperAnalysis;
use App\Models\ResearchGap;
use App\Models\ResearchRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AgentOrchestrator
{
    public function __construct(private AgentRegistry $agents, private ToolRegistry $tools) {}

    public function run(ResearchRun $run): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $lock = Cache::lock('research-run:'.$run->id, 1860);
        $acquired = $postgres ? (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [-$run->id])?->acquired : $lock->get();
        if (! $acquired) {
            return;
        }
        $next = false;
        try {
            $run->refresh();
            if (in_array($run->status, ['awaiting_paper_selection', 'awaiting_approval', 'completed', 'cancelled'], true)) {
                return;
            }
            if ($run->status === 'failed' && $run->retry_count > (int) config('ai.max_retries', 2)) {
                return;
            }
            $stage = $run->current_stage ?: 'planning';
            if (! in_array($stage, WorkflowStateManager::STAGES, true)) {
                throw new \RuntimeException('Status alur tersimpan tidak valid.');
            }
            $project = $run->project;
            $agent = $this->agents->get($stage);
            $state = $run->workflow_state ?? [];
            $revision = (int) ($state['revision_count'] ?? 0);
            $stepName = $stage.':'.$revision;
            $step = AgentStep::query()->firstOrCreate(['run_id' => $run->id, 'step_name' => $stepName], ['agent_name' => $agent->name(), 'status' => 'pending']);
            $started = microtime(true);
            $run->update(['status' => $stage, 'started_at' => $run->started_at ?? now()]);
            $input = match ($stage) {
                'planning', 'discovering' => ['research_plan' => $run->research_plan ?? []],
                'analyzing' => [],
                'synthesizing' => array_intersect_key($state, array_flip(['analyzing', 'revision_feedback'])),
                'identifying_gaps' => array_intersect_key($state, array_flip(['analyzing', 'synthesizing', 'revision_feedback'])),
                'verifying' => array_intersect_key($state, array_flip(['analyzing', 'synthesizing', 'identifying_gaps'])),
            };
            if (isset($input['analyzing']['analyses'])) {
                $input['analyzing']['analyses'] = array_map(
                    fn (array $analysis): array => array_diff_key($analysis, ['invalid_references' => true]) + ['invalid_reference_count' => count($analysis['invalid_references'] ?? [])],
                    $input['analyzing']['analyses'],
                );
            }
            $context = new AgentContext($project, $run, $input);
            try {
                if ($step->status === 'completed') {
                    $output = $step->output_payload ?? [];
                } else {
                    $step->update(['status' => 'running', 'input_payload' => $input, 'started_at' => now(), 'completed_at' => null, 'duration_ms' => null, 'error_message' => null]);
                    $output = $agent->execute($context)->output;
                }
                $next = DB::transaction(function () use ($run, $step, $stage, $output, $context, $started): bool {
                    $locked = ResearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                    $run->setRawAttributes($locked->getAttributes(), true);
                    if ($run->status === 'cancelled') {
                        $step->update(['status' => 'cancelled', 'completed_at' => now()]);

                        return false;
                    }
                    $this->persist($stage, $output, $context);
                    $state = $run->workflow_state ?? [];
                    $state[$stage] = $output;
                    $status = match ($stage) {
                        'planning' => 'discovering', 'discovering' => 'awaiting_paper_selection',
                        'analyzing' => 'synthesizing', 'synthesizing' => 'identifying_gaps', 'identifying_gaps' => 'verifying',
                        'verifying' => 'awaiting_approval',
                    };
                    if ($stage === 'verifying' && ($output['revision_requested'] ?? false)) {
                        $revision = (int) ($state['revision_count'] ?? 0);
                        if ($revision < min(2, (int) config('ai.max_retries', 2))) {
                            $state['revision_count'] = $revision + 1;
                            $state['revision_feedback'] = $output['feedback'] ?? 'Lengkapi atau hapus klaim tanpa bukti.';
                            $status = 'synthesizing';
                        } else {
                            $state['revision_limit_reached'] = true;
                        }
                    }
                    $step->update(['status' => 'completed', 'output_payload' => $output, 'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
                    $run->update(['status' => $status, 'current_stage' => $status, 'workflow_state' => $state, 'retry_count' => 0] + ($stage === 'planning' ? ['research_plan' => $output] : []));

                    return in_array($status, WorkflowStateManager::STAGES, true);
                });
            } catch (\Throwable $exception) {
                $run->refresh();
                if ($run->status !== 'cancelled') {
                    $state = $run->workflow_state ?? [];
                    $state['error'] = mb_substr($exception->getMessage(), 0, 2000);
                    $failures = $state['failures'] ?? [];
                    $failures[] = ['stage' => $stage, 'error' => $state['error'], 'at' => now()->toIso8601String(), 'attempt' => $run->retry_count + 1];
                    $state['failures'] = array_slice($failures, -30);
                    $run->update(['status' => 'failed', 'retry_count' => $run->retry_count + 1, 'workflow_state' => $state]);
                    $step->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
                } else {
                    $step->update(['status' => 'cancelled', 'completed_at' => now()]);
                }
                throw $exception;
            }
        } finally {
            if ($postgres) {
                DB::selectOne('SELECT pg_advisory_unlock(?)', [-$run->id]);
            } else {
                $lock->release();
            }
        }
        if ($next) {
            RunResearchWorkflow::dispatch($run->id);
        }
    }

    /** @param array<string, mixed> $output */
    private function persist(string $stage, array $output, AgentContext $context): void
    {
        $project = $context->project;
        if ($stage === 'analyzing') {
            foreach ($output['analyses'] as $analysis) {
                PaperAnalysis::query()->updateOrCreate(['project_id' => $project->id, 'paper_id' => $analysis['paper_id']], ['structured_analysis' => $analysis, 'evidence_level' => $analysis['source_access_level'], 'analysis_model' => config('ai.chat_model')]);
                $project->papers()->updateExistingPivot($analysis['paper_id'], ['analysis_status' => $analysis['analysis_status']]);
            }
        }
        if ($stage === 'synthesizing') {
            Claim::query()->where('run_id', $context->run->id)->delete();
            foreach ($output['claims'] as $item) {
                $evidence = $this->tools->verifyEvidence($context, $item['evidence_references']);
                $claim = Claim::query()->create(['project_id' => $project->id, 'run_id' => $context->run->id, 'claim_text' => $item['claim_text'], 'verification_status' => $evidence['valid'] === [] ? 'unsupported' : ($evidence['invalid'] !== [] ? 'insufficient_evidence' : 'needs_human_review')]);
                foreach ($evidence['valid'] as $reference) {
                    $claim->evidence()->create(['paper_id' => $reference['paper_id'], 'document_id' => $reference['document_id'], 'chunk_id' => $reference['chunk_id'], 'evidence_text' => $reference['quote'], 'support_type' => $reference['chunk_id'] === null ? 'abstract' : 'document']);
                }
            }
        }
        if ($stage === 'identifying_gaps') {
            ResearchGap::query()->where('run_id', $context->run->id)->delete();
            foreach ($output['gaps'] as $item) {
                $evidence = $this->tools->verifyEvidence($context, $item['evidence_references']);
                $ownedIds = $project->papers()->whereIn('papers.id', $item['supporting_paper_ids'])->pluck('papers.id')->all();
                $referencedIds = array_values(array_unique(array_column($evidence['valid'], 'paper_id')));
                $supportedIds = array_values(array_intersect($ownedIds, $referencedIds));
                $missingSupport = count($supportedIds) !== count(array_unique($item['supporting_paper_ids'])) || array_diff($referencedIds, $ownedIds) !== [];
                ResearchGap::query()->create([
                    'project_id' => $project->id, 'run_id' => $context->run->id, 'title' => $item['gap_title'], 'category' => $item['gap_category'], 'description' => $item['gap_description'],
                    'rationale' => $item['rationale'], 'proposed_question' => $item['proposed_research_question'], 'suggested_methodology' => $item['suggested_methodology'],
                    'supporting_evidence' => ['references' => $evidence['valid'], 'invalid_references' => $evidence['invalid'], 'paper_ids' => $supportedIds, 'limitations' => $item['limitations']],
                    'review_status' => $evidence['valid'] === [] || $evidence['invalid'] !== [] || $missingSupport ? 'insufficient_evidence' : 'needs_human_review',
                ]);
            }
        }
        if ($stage === 'verifying') {
            foreach ($output['checks'] as $check) {
                Claim::query()->where('run_id', $context->run->id)->whereKey($check['claim_id'])->update(['verification_status' => $check['status']]);
            }
            foreach ($output['metadata_checks'] as $check) {
                $project->papers()->where('papers.id', $check['paper_id'])->update(['metadata_verification_status' => $check['status']]);
            }
        }
    }
}
