<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Claim;
use App\Models\Document;
use App\Models\PaperAnalysis;
use App\Models\ResearchMessage;

class EvidenceInvalidationService
{
    public function invalidate(Document $document): void
    {
        Claim::query()->where('project_id', $document->project_id)
            ->whereHas('evidence', fn ($query) => $query->where('document_id', $document->id))
            ->update(['verification_status' => 'needs_human_review']);
        if ($document->paper_id !== null) {
            foreach (PaperAnalysis::query()->where('project_id', $document->project_id)->where('paper_id', $document->paper_id)->get() as $analysis) {
                $data = $analysis->structured_analysis;
                $data['analysis_status'] = 'needs_human_review';
                $analysis->update(['evidence_level' => 'needs_human_review', 'structured_analysis' => $data]);
            }
            $document->project->papers()->updateExistingPivot($document->paper_id, ['analysis_status' => 'pending']);
        }
        foreach (ResearchMessage::query()->where('project_id', $document->project_id)->where('role', 'assistant')->get() as $message) {
            foreach ($message->source_references ?? [] as $reference) {
                if ((int) ($reference['document_id'] ?? 0) === $document->id) {
                    $message->update(['evidence_status' => 'needs_human_review', 'error_message' => 'Dokumen sumber dihapus atau diindeks ulang. Kutipan historis perlu diverifikasi kembali.']);
                    break;
                }
            }
        }
        $document->project->gaps()->update(['review_status' => 'needs_human_review']);
    }
}
