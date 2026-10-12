<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class AgentResult
{
    /** @param array<string, mixed> $output */
    public function __construct(public array $output) {}
}
