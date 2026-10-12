<?php

declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\AbstractAgent;

class PlannerAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'planner';
    }

    protected function schema(): array
    {
        return $this->objectSchema([
            'research_question' => ['type' => 'string'], 'research_objectives' => $this->textList(),
            'main_keywords' => $this->textList(), 'alternative_keywords' => $this->textList(), 'search_queries' => $this->textList(),
            'inclusion_criteria' => $this->textList(), 'exclusion_criteria' => $this->textList(),
            'recommended_paper_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('ai.max_papers', 20)],
            'planning_notes' => ['type' => 'string'],
        ]);
    }

    protected function rules(): array
    {
        return ['research_question' => 'required|string', 'main_keywords' => 'required|array|min:1', 'search_queries' => 'required|array|min:1', 'recommended_paper_count' => 'required|integer|min:1|max:'.(int) config('ai.max_papers', 20)];
    }
}
