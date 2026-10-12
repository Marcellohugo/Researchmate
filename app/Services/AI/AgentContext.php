<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ResearchProject;
use App\Models\ResearchRun;

final readonly class AgentContext
{
    /** @param array<string, mixed> $input */
    public function __construct(public ResearchProject $project, public ResearchRun $run, public array $input = []) {}
}
