<?php

declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\AbstractAgent;

class SynthesisAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'synthesis';
    }

    public function permissions(): array
    {
        return ['get_literature_matrix', 'verify_claim_evidence'];
    }

    protected function schema(): array
    {
        return $this->objectSchema([
            'common_topics' => $this->textList(), 'methodology_groups' => $this->textList(),
            'consistent_findings' => $this->textList(), 'inconsistent_findings' => $this->textList(),
            'synthesis' => ['type' => 'string'],
            'claims' => ['type' => 'array', 'items' => $this->objectSchema(['claim_text' => ['type' => 'string'], 'evidence_references' => $this->evidenceSchema()])],
        ]);
    }
}
