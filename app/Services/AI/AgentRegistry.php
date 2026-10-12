<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Agents\AnalysisAgent;
use App\Agents\DiscoveryAgent;
use App\Agents\PlannerAgent;
use App\Agents\ResearchGapAgent;
use App\Agents\SynthesisAgent;
use App\Agents\VerificationAgent;

class AgentRegistry
{
    public function get(string $stage): AgentInterface
    {
        $class = match ($stage) {
            'planning' => PlannerAgent::class, 'discovering' => DiscoveryAgent::class,
            'analyzing' => AnalysisAgent::class, 'synthesizing' => SynthesisAgent::class,
            'identifying_gaps' => ResearchGapAgent::class, 'verifying' => VerificationAgent::class,
            default => throw new \InvalidArgumentException('Tahap agen tidak dikenal.'),
        };

        return app($class);
    }
}
