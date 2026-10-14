<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Paper;
use App\Models\ResearchProject;
use App\Services\Documents\AnalysisSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_article_includes_opening_and_late_research_sections_within_budget(): void
    {
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel panjang']);
        $document = $this->document($project, $paper);
        $sections = array_merge(
            array_fill(0, 20, 'Introduction'), array_fill(0, 20, 'Metode'),
            array_fill(0, 20, 'Results'), array_fill(0, 20, 'Pembahasan'),
            array_fill(0, 10, 'Conclusion'), array_fill(0, 10, 'Keterbatasan'),
        );
        $this->chunks($document, $sections);

        $sources = app(AnalysisSourceService::class)->forPaper($project, $paper);

        $this->assertCount(12, $sources);
        $this->assertSame(1, $sources->first()->page_number);
        $this->assertSame(100, $sources->last()->page_number);
        foreach (['Metode', 'Results', 'Pembahasan', 'Conclusion', 'Keterbatasan'] as $section) {
            $this->assertContains($section, $sources->pluck('section_name')->all());
        }
        $this->assertCount(12, array_unique($sources->modelKeys()));
        Http::assertNothingSent();
    }

    public function test_article_without_detected_headings_is_sampled_across_its_length(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel tanpa heading']);
        $document = $this->document($project, $paper);
        $this->chunks($document, array_fill(0, 40, null));

        $sources = app(AnalysisSourceService::class)->forPaper($project, $paper);

        $this->assertCount(12, $sources);
        $this->assertSame(1, $sources->first()->page_number);
        $this->assertSame(40, $sources->last()->page_number);
        $this->assertTrue($sources->contains(fn ($chunk) => $chunk->page_number >= 15 && $chunk->page_number <= 25));
        $this->assertCount(12, array_unique($sources->modelKeys()));
    }

    public function test_short_article_keeps_all_chunks_and_excludes_other_or_unindexed_documents(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel pendek']);
        $document = $this->document($project, $paper);
        $this->chunks($document, ['Abstract', 'Methods', 'Results', 'Conclusion']);
        $this->chunks($this->document(ResearchProject::factory()->create(), $paper), ['Results']);
        $this->chunks($this->document($project, Paper::query()->create(['title' => 'Artikel lain'])), ['Results']);
        $this->chunks($this->document($project, $paper, 'processing'), ['Results']);

        $sources = app(AnalysisSourceService::class)->forPaper($project, $paper);

        $this->assertSame($document->chunks()->orderBy('chunk_index')->pluck('id')->all(), $sources->modelKeys());
        $this->assertSame('Bukti halaman 4.', $sources->last()->content);
    }

    public function test_article_without_indexed_chunks_has_no_full_text_sources(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Abstrak saja']);

        $this->assertCount(0, app(AnalysisSourceService::class)->forPaper($project, $paper));
    }

    private function document(ResearchProject $project, Paper $paper, string $status = 'indexed'): Document
    {
        return Document::query()->create([
            'project_id' => $project->id, 'paper_id' => $paper->id,
            'original_filename' => 'artikel.pdf', 'storage_path' => 'testing/artikel.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 100,
            'file_hash' => hash('sha256', random_bytes(16)), 'extraction_status' => $status,
        ]);
    }

    /** @param list<string|null> $sections */
    private function chunks(Document $document, array $sections): void
    {
        foreach ($sections as $index => $section) {
            $document->chunks()->create([
                'chunk_index' => $index, 'page_number' => $index + 1,
                'section_name' => $section, 'content' => 'Bukti halaman '.($index + 1).'.',
            ]);
        }
    }
}
