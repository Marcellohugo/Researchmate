<?php

declare(strict_types=1);

namespace App\Agents;

use App\Models\Claim;
use App\Services\Academic\ProjectPaperMetadata;
use App\Services\AI\AbstractAgent;
use App\Services\AI\AgentContext;
use App\Services\AI\AgentResult;

class VerificationAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'verification';
    }

    public function permissions(): array
    {
        return ['get_paper_metadata', 'verify_claim_evidence'];
    }

    protected function schema(): array
    {
        return $this->objectSchema([
            'checks' => ['type' => 'array', 'items' => $this->objectSchema([
                'claim_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['verified', 'unsupported', 'insufficient_evidence', 'needs_human_review']],
                'feedback' => ['type' => 'string'],
            ])],
            'revision_requested' => ['type' => 'boolean'], 'feedback' => ['type' => 'string'],
        ]);
    }

    public function execute(AgentContext $context): AgentResult
    {
        $claims = Claim::query()->where('run_id', $context->run->id)->with('evidence')->get();
        $synthesis = $context->input['synthesizing'] ?? [];
        unset($synthesis['claims']);
        $result = $this->generate($context, [
            'claims' => $claims->map(fn ($claim) => [
                'id' => $claim->id, 'claim_text' => $claim->claim_text,
                'evidence' => $claim->evidence->map(fn ($evidence) => $evidence->only(['paper_id', 'chunk_id', 'evidence_text']))->all(),
            ])->all(),
            'analyses' => $context->input['analyzing']['analyses'] ?? [],
            'synthesis' => $synthesis,
            'gaps' => $context->input['identifying_gaps'] ?? [],
        ])->output;
        $checks = [];
        foreach ($result['checks'] as $check) {
            $checks[$check['claim_id']] = $check;
        }
        $originalReferences = [];
        foreach ($context->input['synthesizing']['claims'] ?? [] as $item) {
            $originalReferences[$item['claim_text']] = array_merge($originalReferences[$item['claim_text']] ?? [], $item['evidence_references']);
        }
        $validated = [];
        foreach ($claims as $claim) {
            $refs = $originalReferences[$claim->claim_text] ?? array_values($claim->evidence->map(fn ($evidence) => ['paper_id' => $evidence->paper_id, 'chunk_id' => $evidence->chunk_id, 'quote' => $evidence->evidence_text])->all());
            $evidence = $this->tools->verifyEvidence($context, array_values($refs));
            $check = $checks[$claim->id] ?? null;
            $status = $evidence['valid'] === [] ? 'unsupported' : ($evidence['invalid'] !== [] ? 'insufficient_evidence' : ($check['status'] ?? 'needs_human_review'));
            $validated[] = ['claim_id' => $claim->id, 'status' => $status, 'feedback' => $check['feedback'] ?? 'Pemeriksaan model tidak mencakup klaim ini.'];
        }
        $metadata = [];
        $projectMetadata = app(ProjectPaperMetadata::class);
        foreach ($context->project->papers()->wherePivot('is_selected', true)->get() as $paper) {
            $paper = $projectMetadata->apply($paper, $context->project);
            $status = $paper->metadata_source === 'user_reviewed' ? 'metadata_human_reviewed' : 'metadata_unverified';
            $context->project->refresh();
            if ($context->project->research_mode === 'online' && $paper->doi !== null) {
                try {
                    $toolResult = $this->tools->execute($this, 'get_paper_metadata', ['doi' => $paper->doi], $context);
                    $record = $toolResult['metadata'];
                    $title = $record['title'][0] ?? '';
                    similar_text(mb_strtolower($paper->title), mb_strtolower(is_string($title) ? $title : ''), $similarity);
                    $status = $record !== null && $similarity >= 80 ? 'verified' : 'needs_human_review';
                } catch (\Throwable) {
                    $status = $paper->metadata_source === 'user_reviewed' ? 'metadata_human_reviewed' : 'metadata_unverified';
                }
            }
            $metadata[] = ['paper_id' => $paper->id, 'status' => $status];
        }
        $result['checks'] = $validated;
        $result['metadata_checks'] = $metadata;
        $failedChecks = array_filter($validated, fn ($check) => in_array($check['status'], ['unsupported', 'insufficient_evidence'], true));
        $result['revision_requested'] = $result['revision_requested'] || $failedChecks !== [];
        if ($failedChecks !== []) {
            $result['model_feedback'] = $result['feedback'];
            $result['feedback'] = "Perbaiki atau hapus klaim berikut yang belum lolos verifikasi:\n".implode("\n", array_map(
                fn ($check) => 'Klaim #'.$check['claim_id'].' ['.$check['status'].'] '.$claims->firstWhere('id', $check['claim_id'])->claim_text.': '.$check['feedback'],
                $failedChecks,
            ));
        }

        return new AgentResult($result);
    }
}
