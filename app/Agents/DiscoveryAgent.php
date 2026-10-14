<?php

declare(strict_types=1);

namespace App\Agents;

use App\Models\Paper;
use App\Services\Academic\ProjectPaperMetadata;
use App\Services\AI\AbstractAgent;
use App\Services\AI\AgentContext;
use App\Services\AI\AgentResult;

class DiscoveryAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'discovery';
    }

    public function permissions(): array
    {
        return ['search_openalex', 'search_project_documents', 'normalize_doi'];
    }

    protected function schema(): array
    {
        return $this->objectSchema([
            'discovered_paper_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'ranking_notes' => ['type' => 'string'], 'missing_metadata' => $this->textList(),
        ]);
    }

    public function execute(AgentContext $context): AgentResult
    {
        $messages = $this->messages($context, $context->input);
        $permissions = $context->project->research_mode === 'offline' ? ['search_project_documents', 'normalize_doi'] : $this->permissions();
        $searched = false;
        $lookup = $context->project->research_mode === 'offline' ? 'search_project_documents' : 'search_openalex';
        for ($turn = 0; $turn < 4; $turn++) {
            $message = $this->ai->chat($messages, ['tools' => $this->tools->definitions($permissions)]);
            $messages[] = $message + ['role' => 'assistant'];
            $calls = $message['tool_calls'] ?? [];
            if ($calls === []) {
                break;
            }
            foreach (array_slice($calls, 0, 4) as $call) {
                $name = $call['function']['name'] ?? '';
                $arguments = $call['function']['arguments'] ?? [];
                if (! is_string($name) || ! is_array($arguments) || ! in_array($name, $permissions, true)) {
                    throw new \RuntimeException('Permintaan alat agen penelusuran tidak valid.');
                }
                $result = $this->tools->execute($this, $name, $arguments, $context);
                $toolMessage = ['role' => 'tool', 'tool_name' => $name, 'content' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                if (isset($call['id'])) {
                    $toolMessage['tool_call_id'] = $call['id'];
                }
                $messages[] = $toolMessage;
                $searched = $searched || $name === $lookup;
            }
            if ($searched) {
                break;
            }
        }
        if (! $searched) {
            throw new \RuntimeException('Model tidak meminta alat penelusuran. Gunakan model yang mendukung tool calling, lalu lanjutkan alur.');
        }
        $metadata = app(ProjectPaperMetadata::class);
        $papers = $context->project->papers()->limit((int) config('ai.max_papers', 20))->get()
            ->map(fn (Paper $paper): Paper => $metadata->apply($paper, $context->project))
            ->filter(fn (Paper $paper): bool => $paper->publication_year === null
                || (($context->project->year_from === null || $paper->publication_year >= $context->project->year_from)
                    && ($context->project->year_to === null || $paper->publication_year <= $context->project->year_to)))->values();
        $messages[] = ['role' => 'user', 'content' => 'Tulis hasil JSON. Pilih hanya ID dari artikel yang benar-benar tersedia berikut: '.$papers->map(fn ($paper) => $paper->only(['id', 'title', 'abstract', 'publication_year', 'doi']))->toJson()];
        $output = $this->ai->structuredChat($messages, $this->schema());
        $ids = array_map('intval', $output['discovered_paper_ids']);
        if (array_diff($ids, $papers->modelKeys()) !== []) {
            throw new \RuntimeException('Agen mengembalikan artikel yang tidak ditemukan dalam proyek.');
        }

        return new AgentResult($output);
    }
}
