<?php

declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\AbstractAgent;

class ResearchGapAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'research-gap';
    }

    public function permissions(): array
    {
        return ['get_literature_matrix', 'save_research_gap', 'verify_claim_evidence'];
    }

    protected function schema(): array
    {
        return $this->objectSchema(['gaps' => ['type' => 'array', 'maxItems' => 12, 'items' => $this->objectSchema([
            'gap_title' => ['type' => 'string', 'maxLength' => 255],
            'gap_category' => ['type' => 'string', 'enum' => ['methodological', 'population', 'contextual', 'theoretical', 'empirical', 'contradictory']],
            'gap_description' => ['type' => 'string'], 'supporting_paper_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'evidence_references' => $this->evidenceSchema(), 'rationale' => ['type' => 'string'],
            'proposed_research_question' => ['type' => 'string'], 'suggested_methodology' => ['type' => ['string', 'null']],
            'limitations' => ['type' => 'string'], 'review_status' => ['type' => 'string', 'enum' => ['needs_human_review', 'insufficient_evidence']],
        ])]]);
    }
}
