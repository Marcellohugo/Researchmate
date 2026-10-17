<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Agents\DiscoveryAgent;
use App\Models\Paper;
use App\Models\ResearchProject;
use App\Services\AI\AgentContext;
use App\Services\AI\ToolRegistry;
use App\Services\Documents\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_uses_topic_retrieval_to_rank_only_project_papers(): void
    {
        $project = ResearchProject::factory()->create(['topic' => 'Pendidikan']);
        $papers = [];
        foreach ([0.2, 0.9] as $index => $score) {
            $paper = Paper::query()->create(['title' => 'Artikel '.$index]);
            $project->papers()->attach($paper->id);
            $document = $project->documents()->create([
                'paper_id' => $paper->id, 'original_filename' => $index.'.pdf', 'storage_path' => 'test/'.$index.'.pdf',
                'mime_type' => 'application/pdf', 'file_size' => 10, 'file_hash' => hash('sha256', (string) $index), 'extraction_status' => 'indexed',
            ]);
            $document->chunks()->create(['chunk_index' => 0, 'content' => 'Isi artikel.', 'embedding' => '[1,0]', 'embedding_model' => config('ai.embed_model'), 'embedding_dimensions' => 2]);
            $papers[] = $paper;
        }
        $foreign = Paper::query()->create(['title' => 'Artikel pengguna lain']);
        $this->mock(RetrievalService::class, function ($mock) use ($project, $papers): void {
            $mock->shouldReceive('retrieve')->once()->withArgs(fn ($p, $query) => $p->id === $project->id && $query === 'AI di kampus')
                ->andReturn([['paper_id' => $papers[0]->id, 'score' => 0.2], ['paper_id' => $papers[1]->id, 'score' => 0.9]]);
        });
        $run = $project->runs()->create(['status' => 'discovering']);
        $result = app(ToolRegistry::class)->execute(app(DiscoveryAgent::class), 'search_project_documents', ['query' => 'AI di kampus'], new AgentContext($project, $run));

        $this->assertSame([$papers[1]->id, $papers[0]->id], array_column($result['papers'], 'id'));
        $this->assertSame(0.9, $result['papers'][0]['topic_similarity']);
        $this->assertNotContains($foreign->id, array_column($result['papers'], 'id'));
    }
}
