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

    public function test_long_article_keeps_every_indexed_chunk_in_source_order(): void
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

        $this->assertCount(100, $sources);
        $this->assertSame(1, $sources->first()->page_number);
        $this->assertSame(100, $sources->last()->page_number);
        foreach (['Metode', 'Results', 'Pembahasan', 'Conclusion', 'Keterbatasan'] as $section) {
            $this->assertContains($section, $sources->pluck('section_name')->all());
        }
        $this->assertSame($document->chunks()->orderBy('chunk_index')->pluck('id')->all(), $sources->modelKeys());
        Http::assertNothingSent();
    }

    public function test_article_without_detected_headings_keeps_all_its_text(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel tanpa heading']);
        $document = $this->document($project, $paper);
        $this->chunks($document, array_fill(0, 40, null));

        $sources = app(AnalysisSourceService::class)->forPaper($project, $paper);

        $this->assertCount(40, $sources);
        $this->assertSame(1, $sources->first()->page_number);
        $this->assertSame(40, $sources->last()->page_number);
        $this->assertTrue($sources->contains(fn ($chunk) => $chunk->page_number >= 15 && $chunk->page_number <= 25));
        $this->assertCount(40, array_unique($sources->modelKeys()));
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

    public function test_combined_documents_cannot_silently_exceed_article_chunk_limit(): void
    {
        config(['ai.max_document_chunks' => 12]);
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel dengan PDF duplikat']);
        $this->chunks($this->document($project, $paper), array_fill(0, 8, 'Results'));
        $this->chunks($this->document($project, $paper), array_fill(0, 5, 'Conclusion'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('melampaui batas 12 potongan');
        app(AnalysisSourceService::class)->forPaper($project, $paper);
    }

    public function test_custom_large_chunks_are_batched_by_character_budget_without_truncation(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel dengan potongan besar']);
        $document = $this->document($project, $paper);
        $this->chunks($document, array_fill(0, 5, null));
        $document->chunks()->update(['content' => str_repeat('a', 6000)]);
        $service = app(AnalysisSourceService::class);
        $chunks = $service->forPaper($project, $paper);
        $batches = $service->batches($chunks);

        $this->assertCount(3, $batches);
        $this->assertSame($chunks->modelKeys(), array_merge(...array_map(fn ($batch): array => $batch->modelKeys(), $batches)));
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(14400, $batch->sum(fn ($chunk): int => mb_strlen($chunk->content)));
        }
        $this->assertSame(30000, $chunks->sum(fn ($chunk): int => mb_strlen($chunk->content)));
    }

    public function test_oversized_legacy_chunk_is_rejected_before_generation_instead_of_truncated(): void
    {
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel dengan potongan terlalu besar']);
        $document = $this->document($project, $paper);
        $this->chunks($document, [null]);
        $document->chunks()->update(['content' => str_repeat('a', 14401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Indeks ulang PDF');
        app(AnalysisSourceService::class)->forPaper($project, $paper);
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
