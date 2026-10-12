<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Validator;

abstract class AbstractAgent implements AgentInterface
{
    public function __construct(protected SenopatiService $ai, protected ToolRegistry $tools) {}

    /** @return array<string, mixed> */
    abstract protected function schema(): array;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [];
    }

    public function execute(AgentContext $context): AgentResult
    {
        return $this->generate($context, $context->input);
    }

    /** @param array<string, mixed> $input */
    protected function generate(AgentContext $context, array $input): AgentResult
    {
        $output = $this->ai->structuredChat($this->messages($context, $input), $this->schema());
        Validator::make($output, $this->rules())->validate();

        return new AgentResult($output);
    }

    /** @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    protected function messages(AgentContext $context, array $input): array
    {
        $prompt = file_get_contents(resource_path('prompts/agents/'.$this->name().'.md'));
        if ($prompt === false) {
            throw new \RuntimeException('Prompt agen tidak tersedia.');
        }

        return [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => json_encode([
                'project' => $context->project->only(['title', 'research_question', 'objectives', 'keywords', 'year_from', 'year_to', 'research_mode', 'language', 'target_papers', 'settings']),
                'data' => $input,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    protected function objectSchema(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /** @return array<string, mixed> */
    protected function textList(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    /** @return array<string, mixed> */
    protected function evidenceSchema(): array
    {
        return ['type' => 'array', 'maxItems' => 30, 'items' => $this->objectSchema([
            'paper_id' => ['type' => 'integer', 'minimum' => 1],
            'chunk_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'quote' => ['type' => 'string', 'maxLength' => 5000],
        ])];
    }

    public function permissions(): array
    {
        return [];
    }
}
