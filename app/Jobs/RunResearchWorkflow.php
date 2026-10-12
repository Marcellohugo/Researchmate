<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ResearchRun;
use App\Services\AI\AgentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunResearchWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public int $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $run = ResearchRun::query()->find($this->runId);
        if ($run !== null) {
            $orchestrator->run($run);
        }
    }
}
