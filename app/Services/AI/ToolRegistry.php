<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AgentStep;
use App\Models\PaperAnalysis;
use App\Models\PaperChunk;
use App\Models\ResearchGap;
use App\Services\Academic\CrossrefService;
use App\Services\Academic\OpenAlexService;
use App\Services\Documents\RetrievalService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ToolRegistry
{
    public function __construct(private OpenAlexService $openalex, private CrossrefService $crossref, private RetrievalService $retrieval) {}

    /** @return array<string, array<string, mixed>> */
    private function specifications(): array
    {
        return [
            'search_openalex' => ['description' => 'Cari metadata artikel nyata di OpenAlex, khusus mode daring.', 'rules' => ['query' => 'required|string|max:500'], 'properties' => ['query' => ['type' => 'string']]],
            'get_paper_metadata' => ['description' => 'Periksa DOI melalui Crossref, khusus mode daring.', 'rules' => ['doi' => 'required|string|max:255'], 'properties' => ['doi' => ['type' => 'string']]],
            'normalize_doi' => ['description' => 'Normalisasi DOI tanpa akses internet.', 'rules' => ['doi' => 'required|string|max:255'], 'properties' => ['doi' => ['type' => 'string']]],
            'search_project_documents' => ['description' => 'Daftar artikel dan dokumen lokal hanya dari proyek ini.', 'rules' => ['query' => 'required|string|max:500'], 'properties' => ['query' => ['type' => 'string']]],
            'retrieve_document_chunks' => ['description' => 'Cari bagian dokumen proyek untuk mendukung analisis.', 'rules' => ['query' => 'required|string|max:1000'], 'properties' => ['query' => ['type' => 'string']]],
            'get_literature_matrix' => ['description' => 'Ambil analisis artikel terpilih yang sudah disimpan.', 'rules' => [], 'properties' => []],
            'verify_claim_evidence' => ['description' => 'Periksa kutipan dan kepemilikan sumber bukti.', 'rules' => ['references' => 'present|array|max:30'] + $this->referenceRules('references'), 'properties' => ['references' => $this->referenceSchema()]],
            'save_paper_analysis' => ['description' => 'Simpan analisis dengan bukti tervalidasi pada artikel proyek.', 'rules' => ['paper_id' => 'required|integer', 'analysis' => 'required|array', 'analysis.evidence_references' => 'present|array|max:30', 'analysis.source_access_level' => 'required|in:full_text,abstract_only'] + $this->referenceRules('analysis.evidence_references'), 'properties' => ['paper_id' => ['type' => 'integer'], 'analysis' => ['type' => 'object']]],
            'save_research_gap' => ['description' => 'Simpan kandidat celah dengan referensi bukti proyek.', 'rules' => ['gap_title' => 'required|string|max:255', 'gap_category' => 'required|in:methodological,population,contextual,theoretical,empirical,contradictory', 'gap_description' => 'required|string|max:10000', 'evidence_references' => 'present|array|max:30'] + $this->referenceRules('evidence_references'), 'properties' => ['gap_title' => ['type' => 'string'], 'gap_category' => ['type' => 'string'], 'gap_description' => ['type' => 'string'], 'evidence_references' => $this->referenceSchema()]],
        ];
    }

    /** @return array<string, string> */
    private function referenceRules(string $field): array
    {
        return [$field.'.*' => 'required|array', $field.'.*.paper_id' => 'required|integer|min:1', $field.'.*.chunk_id' => 'nullable|integer|min:1', $field.'.*.quote' => 'required|string|min:8|max:5000'];
    }

    /** @return array<string, mixed> */
    private function referenceSchema(): array
    {
        return ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => ['paper_id' => ['type' => 'integer'], 'chunk_id' => ['type' => ['integer', 'null']], 'quote' => ['type' => 'string']], 'required' => ['paper_id', 'chunk_id', 'quote'], 'additionalProperties' => false]];
    }

    /** @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public function definitions(array $permissions): array
    {
        $definitions = [];
        foreach ($this->specifications() as $name => $spec) {
            if (in_array($name, $permissions, true)) {
                $definitions[] = ['type' => 'function', 'function' => [
                    'name' => $name, 'description' => $spec['description'],
                    'parameters' => ['type' => 'object', 'properties' => $spec['properties'] === [] ? new \stdClass : $spec['properties'], 'required' => array_keys($spec['properties']), 'additionalProperties' => false],
                ]];
            }
        }

        return $definitions;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(AgentInterface $agent, string $name, array $arguments, AgentContext $context): array
    {
        $spec = $this->specifications()[$name] ?? null;
        if ($spec === null || ! in_array($name, $agent->permissions(), true)) {
            throw new \RuntimeException('Alat tidak dikenal atau tidak diizinkan untuk agen ini.');
        }
        Validator::make($arguments, $spec['rules'])->validate();
        if (array_diff(array_keys($arguments), array_keys($spec['properties'])) !== []) {
            throw new \RuntimeException('Argumen alat di luar skema tidak diizinkan.');
        }
        $context->project->refresh();
        if ($context->project->research_mode === 'offline' && in_array($name, ['search_openalex', 'get_paper_metadata'], true)) {
            throw new \RuntimeException('Mode luring tidak mengizinkan akses API akademik.');
        }
        $context->run->refresh();
        if ($context->run->status === 'cancelled') {
            throw new \RuntimeException('Alur dibatalkan; eksekusi alat dihentikan.');
        }
        $state = $context->run->workflow_state ?? [];
        $count = (int) ($state['tool_call_count'] ?? 0);
        if ($count >= (int) config('ai.max_tool_calls', 40)) {
            throw new \RuntimeException('Batas eksekusi alat untuk alur ini tercapai.');
        }
        $state['tool_call_count'] = $count + 1;
        $context->run->update(['workflow_state' => $state]);
        $started = microtime(true);
        $step = AgentStep::query()->create([
            'run_id' => $context->run->id, 'agent_name' => $agent->name(), 'step_name' => 'tool:'.$name.':'.Str::uuid(),
            'status' => 'running', 'input_payload' => $arguments, 'started_at' => now(),
        ]);
        try {
            $result = match ($name) {
                'search_openalex' => $this->search($context, (string) $arguments['query']),
                'get_paper_metadata' => ['metadata' => $this->crossref->metadata((string) $arguments['doi'])],
                'normalize_doi' => ['doi' => OpenAlexService::normalizeDoi((string) $arguments['doi'])],
                'search_project_documents' => ['papers' => $context->project->papers()->whereHas('documents', fn ($query) => $query->where('project_id', $context->project->id))->limit((int) config('ai.max_papers', 20))->get()->map(fn ($paper) => $paper->only(['id', 'title', 'abstract', 'authors', 'publication_year', 'doi']))->all()],
                'retrieve_document_chunks' => ['chunks' => $this->retrieval->retrieve($context->project, (string) $arguments['query'])],
                'get_literature_matrix' => ['analyses' => PaperAnalysis::query()->where('project_id', $context->project->id)->where('run_id', $context->run->id)->get()->toArray()],
                'verify_claim_evidence' => $this->verifyEvidence($context, $arguments['references']),
                'save_paper_analysis' => $this->saveAnalysis($context, $arguments),
                'save_research_gap' => $this->saveGap($context, $arguments),
                default => throw new \RuntimeException('Implementasi alat tidak tersedia.'),
            };
            $step->update(['status' => 'completed', 'output_payload' => $result, 'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

            return $result;
        } catch (\Throwable $exception) {
            $step->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function search(AgentContext $context, string $query): array
    {
        $records = $this->openalex->search($query, $context->project->year_from, $context->project->year_to, (int) config('ai.max_papers', 20));

        return ['paper_ids' => $this->openalex->save($context->project, $records), 'papers' => $records];
    }

    /** @param list<array<string, mixed>> $references
     * @return array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     */
    public function verifyEvidence(AgentContext $context, array $references): array
    {
        $valid = [];
        $invalid = [];
        foreach ($references as $reference) {
            $paper = $context->project->papers()->wherePivot('is_selected', true)->where('papers.id', $reference['paper_id'] ?? 0)->first();
            $quote = $reference['quote'] ?? '';
            $chunk = isset($reference['chunk_id']) ? PaperChunk::query()->whereHas('document', fn ($query) => $query->where('project_id', $context->project->id)->where('paper_id', $reference['paper_id'] ?? 0))->whereKey($reference['chunk_id'])->first() : null;
            $source = $chunk->content ?? ($paper->abstract ?? '');
            if ($paper !== null && is_string($quote) && mb_strlen(trim($quote)) >= 8 && str_contains($this->clean($source), $this->clean($quote)) && (! isset($reference['chunk_id']) || $chunk !== null)) {
                $valid[] = ['paper_id' => $paper->id, 'chunk_id' => $chunk?->id, 'document_id' => $chunk?->document_id, 'page_number' => $chunk?->page_number, 'quote' => $quote];
            } else {
                $invalid[] = $reference;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    private function clean(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function saveAnalysis(AgentContext $context, array $arguments): array
    {
        $paper = $context->project->papers()->wherePivot('is_selected', true)->where('papers.id', $arguments['paper_id'])->firstOrFail();
        $analysis = $arguments['analysis'];
        $references = array_values(array_filter($analysis['evidence_references'], fn ($reference) => $reference['paper_id'] === $paper->id));
        $wrongPaper = array_values(array_filter($analysis['evidence_references'], fn ($reference) => $reference['paper_id'] !== $paper->id));
        $evidence = $this->verifyEvidence($context, $references);
        $evidence['invalid'] = array_merge($evidence['invalid'], $wrongPaper);
        $analysis['source_access_level'] = $context->project->documents()->where('paper_id', $paper->id)->where('extraction_status', 'indexed')->exists() ? 'full_text' : 'abstract_only';
        $analysis['evidence_references'] = $evidence['valid'];
        $analysis['analysis_status'] = $evidence['invalid'] !== [] || $evidence['valid'] === [] ? 'insufficient_evidence' : 'supported';
        $saved = PaperAnalysis::query()->updateOrCreate(['project_id' => $context->project->id, 'paper_id' => $paper->id, 'run_id' => $context->run->id], [
            'structured_analysis' => $analysis, 'evidence_level' => $analysis['source_access_level'] ?? 'abstract_only', 'analysis_model' => config('ai.chat_model'),
        ]);

        return ['analysis_id' => $saved->id, 'evidence_status' => $analysis['analysis_status']];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function saveGap(AgentContext $context, array $arguments): array
    {
        $evidence = $this->verifyEvidence($context, $arguments['evidence_references']);
        $gap = ResearchGap::query()->updateOrCreate(['project_id' => $context->project->id, 'run_id' => $context->run->id, 'title' => $arguments['gap_title']], [
            'category' => $arguments['gap_category'], 'description' => $arguments['gap_description'],
            'rationale' => $arguments['rationale'] ?? null, 'proposed_question' => $arguments['proposed_research_question'] ?? null,
            'suggested_methodology' => $arguments['suggested_methodology'] ?? null,
            'supporting_evidence' => ['references' => $evidence['valid'], 'invalid_references' => $evidence['invalid'], 'paper_ids' => array_values(array_unique(array_column($evidence['valid'], 'paper_id'))), 'limitations' => $arguments['limitations'] ?? null],
            'review_status' => $evidence['valid'] === [] || $evidence['invalid'] !== [] ? 'insufficient_evidence' : 'needs_human_review',
        ]);

        return ['gap_id' => $gap->id, 'review_status' => $gap->review_status];
    }
}
