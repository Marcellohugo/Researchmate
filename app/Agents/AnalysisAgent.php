<?php

declare(strict_types=1);

namespace App\Agents;

use App\Models\AgentStep;
use App\Models\PaperAnalysis;
use App\Models\PaperChunk;
use App\Services\AI\AbstractAgent;
use App\Services\AI\AgentContext;
use App\Services\AI\AgentResult;
use Illuminate\Support\Facades\DB;

class AnalysisAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'analysis';
    }

    public function permissions(): array
    {
        return ['retrieve_document_chunks', 'save_paper_analysis'];
    }

    protected function schema(): array
    {
        $nullable = ['type' => ['string', 'null']];

        return $this->objectSchema([
            'title' => ['type' => 'string'], 'research_objective' => $nullable, 'methodology' => $nullable,
            'research_design' => $nullable, 'sample' => $nullable, 'variables' => $nullable,
            'main_findings' => $nullable, 'limitations' => $nullable, 'recommendations' => $nullable,
            'evidence_references' => $this->evidenceSchema(),
            'source_access_level' => ['type' => 'string', 'enum' => ['full_text', 'abstract_only']],
            'analysis_status' => ['type' => 'string', 'enum' => ['supported', 'insufficient_evidence', 'not_reported']],
        ]);
    }

    public function execute(AgentContext $context): AgentResult
    {
        $analyses = [];
        $papers = $context->project->papers()->wherePivot('is_selected', true)->get();
        if ($papers->isEmpty()) {
            throw new \RuntimeException('Pilih setidaknya satu artikel sebelum analisis.');
        }
        foreach ($papers as $paper) {
            $context->run->refresh();
            if ($context->run->status === 'cancelled') {
                break;
            }
            $stepName = 'analysis:paper:'.$paper->id;
            $savedStep = AgentStep::query()->where('run_id', $context->run->id)->where('step_name', $stepName)->where('status', 'completed')->first();
            if ($savedStep !== null) {
                $analyses[] = $savedStep->output_payload;

                continue;
            }
            $chunks = PaperChunk::query()->whereHas('document', fn ($query) => $query->where('project_id', $context->project->id)->where('paper_id', $paper->id)->where('extraction_status', 'indexed'))
                ->orderBy('chunk_index')->limit(12)->get(['id', 'document_id', 'page_number', 'content']);
            $source = $chunks->isNotEmpty() ? 'full_text' : 'abstract_only';
            $input = ['paper' => $paper->only(['id', 'title', 'abstract', 'authors', 'publication_year']), 'source_access_level' => $source, 'chunks' => $chunks->toArray()];
            $analysis = $this->generate($context, $input)->output;
            $analysis['source_access_level'] = $source;
            $analysis['title'] = $paper->title;
            $references = array_values(array_filter($analysis['evidence_references'], fn ($reference) => ($reference['paper_id'] ?? null) === $paper->id));
            $wrongPaper = array_values(array_filter($analysis['evidence_references'], fn ($reference) => ($reference['paper_id'] ?? null) !== $paper->id));
            $evidence = $this->tools->verifyEvidence($context, $references);
            $evidence['invalid'] = array_merge($evidence['invalid'], $wrongPaper);
            $analysis['evidence_references'] = $evidence['valid'];
            $analysis['invalid_references'] = $evidence['invalid'];
            $analysis['analysis_status'] = $evidence['valid'] === [] || $evidence['invalid'] !== [] ? 'insufficient_evidence' : 'supported';
            $analysis['paper_id'] = $paper->id;
            DB::transaction(function () use ($context, $paper, $analysis, $stepName): void {
                PaperAnalysis::query()->updateOrCreate(['project_id' => $context->project->id, 'paper_id' => $paper->id], ['structured_analysis' => $analysis, 'evidence_level' => $analysis['source_access_level'], 'analysis_model' => config('ai.chat_model')]);
                AgentStep::query()->updateOrCreate(['run_id' => $context->run->id, 'step_name' => $stepName], ['agent_name' => $this->name(), 'status' => 'completed', 'output_payload' => $analysis, 'completed_at' => now()]);
            });
            $analyses[] = $analysis;
        }

        return new AgentResult(['analyses' => $analyses]);
    }
}
