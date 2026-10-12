<?php

declare(strict_types=1);

namespace App\Services\AI;

interface AgentInterface
{
    public function name(): string;

    /** @return list<string> */
    public function permissions(): array;

    public function execute(AgentContext $context): AgentResult;
}
