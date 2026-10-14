<?php

declare(strict_types=1);

namespace App\Agents;

use App\Models\AgentStep;
use App\Models\PaperAnalysis;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Services\Academic\ProjectPaperMetadata;
use App\Services\AI\AbstractAgent;
use App\Services\AI\AgentContext;
use App\Services\AI\AgentResult;
use App\Services\AI\AnalysisPendingException;
use App\Services\Documents\AnalysisSourceService;
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
        $nullable = ['type' => ['string', 'null'], 'maxLength' => 1000];
        $evidence = $this->evidenceSchema();
        $evidence['items']['properties']['quote']['maxLength'] = 400;

        return $this->objectSchema([
            'title' => ['type' => 'string', 'maxLength' => 1000], 'research_objective' => $nullable, 'methodology' => $nullable,
            'research_design' => $nullable, 'sample' => $nullable, 'variables' => $nullable,
            'main_findings' => $nullable, 'limitations' => $nullable, 'recommendations' => $nullable,
            'evidence_references' => $evidence,
            'source_access_level' => ['type' => 'string', 'enum' => ['full_text', 'abstract_only']],
            'analysis_status' => ['type' => 'string', 'enum' => ['supported', 'insufficient_evidence', 'not_reported']],
        ]);
    }

    public function execute(AgentContext $context): AgentResult
    {
        $analyses = [];
        $generated = false;
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
            $paper = app(ProjectPaperMetadata::class)->apply($paper, $context->project);
            $sources = app(AnalysisSourceService::class);
            $chunks = $sources->forPaper($context->project, $paper);
            $source = $chunks->isNotEmpty() ? 'full_text' : 'abstract_only';
            $metadata = $paper->only(['title', 'authors', 'publication_year', 'doi', 'journal_name', 'source_url', 'volume', 'issue', 'pages', 'metadata_source', 'metadata_verification_status']);
            $signature = hash('sha256', json_encode([$metadata, $paper->abstract, $chunks->toArray()], JSON_THROW_ON_ERROR));
            $input = ['paper' => $paper->only(['id', 'title', 'abstract', 'authors', 'publication_year']), 'source_access_level' => $source, 'source_signature' => $signature];
            $parts = [];
            $batches = $sources->batches($chunks);
            foreach ($batches as $index => $batch) {
                $part = $this->checkpoint($context, $paper->id, $stepName.':batch:'.$index.':'.$signature,
                    $input + ['mode' => 'extract', 'batch_number' => $index + 1, 'batch_count' => count($batches), 'chunks' => $batch->values()->toArray()],
                    array_values(array_map(fn ($chunk): int => $chunk->id, $batch->all())), $generated);
                if ($part === null) {
                    return new AgentResult(['analyses' => $analyses]);
                }
                $parts[] = $part;
            }
            $level = 0;
            while (count($parts) > 1) {
                $merged = [];
                foreach (array_chunk($parts, 3) as $index => $group) {
                    if (count($group) === 1) {
                        $merged[] = $group[0];

                        continue;
                    }
                    $part = $this->checkpoint($context, $paper->id, $stepName.':merge:'.$level.':'.$index.':'.$signature,
                        $input + ['mode' => 'aggregate', 'parts' => array_map(fn (array $part): array => array_diff_key($part, ['invalid_references' => true]), $group)],
                        null, $generated, $group);
                    if ($part === null) {
                        return new AgentResult(['analyses' => $analyses]);
                    }
                    $merged[] = $part;
                }
                $parts = $merged;
                $level++;
            }
            $analysis = $parts[0];
            $analysis['source_access_level'] = $source;
            $analysis['coverage_strategy'] = $source === 'full_text' ? 'full_text_batches' : 'abstract_only';
            $analysis['source_chunk_count'] = $chunks->count();
            $analysis['analyzed_chunk_count'] = $chunks->count();
            $analysis['analysis_batch_count'] = count($batches);
            $analysis['aggregation_passes'] = $level;
            $analysis['title'] = $paper->title;
            $analysis['paper_metadata'] = $metadata;
            $analysis['paper_id'] = $paper->id;
            $saved = DB::transaction(function () use ($context, $paper, $analysis, $stepName): bool {
                ResearchProject::query()->whereKey($context->project->id)->lockForUpdate()->firstOrFail();
                $run = ResearchRun::query()->whereKey($context->run->id)->lockForUpdate()->firstOrFail();
                if ($run->status === 'cancelled') {
                    return false;
                }
                PaperAnalysis::query()->updateOrCreate(['project_id' => $context->project->id, 'paper_id' => $paper->id, 'run_id' => $context->run->id], ['structured_analysis' => $analysis, 'evidence_level' => $analysis['source_access_level'], 'analysis_model' => config('ai.chat_model')]);
                AgentStep::query()->updateOrCreate(['run_id' => $context->run->id, 'step_name' => $stepName], ['agent_name' => $this->name(), 'status' => 'completed', 'output_payload' => $analysis, 'completed_at' => now()]);

                return true;
            });
            if (! $saved) {
                break;
            }
            $analyses[] = $analysis;
        }

        return new AgentResult(['analyses' => $analyses]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>|null  $chunkIds
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>|null
     */
    private function checkpoint(AgentContext $context, int $paperId, string $stepName, array $input, ?array $chunkIds, bool &$generated, array $parts = []): ?array
    {
        $saved = AgentStep::query()->where('run_id', $context->run->id)->where('step_name', $stepName)->where('status', 'completed')->first();
        if ($saved !== null) {
            return $saved->output_payload;
        }
        $context->run->refresh();
        if ($context->run->status === 'cancelled') {
            return null;
        }
        if ($generated) {
            throw new AnalysisPendingException('Checkpoint analisis tersimpan; lanjutkan bagian berikutnya pada job berikutnya.');
        }
        $generated = true;
        $analysis = $this->generate($context, $input)->output;
        $allowed = array_merge(...array_column($parts, 'evidence_references'));
        $references = [];
        $invalid = [];
        foreach ($analysis['evidence_references'] as $reference) {
            $inScope = $reference['paper_id'] === $paperId && ($chunkIds !== null
                ? ($chunkIds === [] ? $reference['chunk_id'] === null : in_array($reference['chunk_id'], $chunkIds, true))
                : count(array_filter($allowed, fn (array $allowed): bool => $allowed['paper_id'] === $reference['paper_id'] && $allowed['chunk_id'] === $reference['chunk_id'] && $allowed['quote'] === $reference['quote'])) > 0);
            if ($inScope) {
                $references[] = $reference;
            } else {
                $invalid[] = $reference;
            }
        }
        $evidence = $this->tools->verifyEvidence($context, $references);
        $invalid = array_merge($invalid, $evidence['invalid']);
        $rejectedCount = count($invalid) + array_sum(array_column($parts, 'rejected_reference_count'));
        $invalid = array_merge($invalid, ...array_column($parts, 'invalid_references'));
        $analysis['evidence_references'] = $evidence['valid'];
        $analysis['invalid_references'] = array_slice($invalid, 0, 30);
        $analysis['rejected_reference_count'] = $rejectedCount;
        $analysis['analysis_status'] = $evidence['valid'] === [] || $rejectedCount > 0 ? 'insufficient_evidence' : 'supported';
        $analysis['source_access_level'] = $input['source_access_level'];

        return DB::transaction(function () use ($context, $paperId, $stepName, $input, $analysis): ?array {
            ResearchProject::query()->whereKey($context->project->id)->lockForUpdate()->firstOrFail();
            $run = ResearchRun::query()->whereKey($context->run->id)->where('project_id', $context->project->id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'cancelled') {
                return null;
            }
            AgentStep::query()->updateOrCreate(['run_id' => $context->run->id, 'step_name' => $stepName], [
                'agent_name' => $this->name(), 'status' => 'completed',
                'input_payload' => ['mode' => $input['mode'], 'paper_id' => $paperId, 'source_signature' => $input['source_signature'], 'chunk_ids' => array_column($input['chunks'] ?? [], 'id')],
                'output_payload' => $analysis, 'completed_at' => now(),
            ]);

            return $analysis;
        });
    }
}
