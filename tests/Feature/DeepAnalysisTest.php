<?php

namespace Tests\Feature;

use App\Agents\AnalysisAgent;
use App\Models\AgentStep;
use App\Models\Document;
use App\Models\Paper;
use App\Models\ResearchProject;
use App\Services\AI\AgentContext;
use App\Services\AI\AnalysisPendingException;
use App\Services\AI\SenopatiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayFixture;
use Tests\TestCase;

class DeepAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_article_batches_are_checkpointed_and_late_findings_reach_final_analysis(): void
    {
        [$context, $paper, $document] = $this->source(49);
        $inputs = [];
        $this->fakeAnalysis($inputs);

        $output = $this->finish($context);
        $analysis = $output['analyses'][0];
        $extracts = array_values(array_filter($inputs, fn (array $input): bool => $input['mode'] === 'extract'));
        $this->assertSame($document->chunks()->orderBy('chunk_index')->pluck('id')->all(), array_merge(...array_map(fn (array $input): array => array_column($input['chunks'], 'id'), $extracts)));
        foreach ($inputs as $input) {
            $this->assertLessThanOrEqual(12, count($input['chunks'] ?? []));
            $this->assertLessThanOrEqual(3, count($input['parts'] ?? []));
        }
        $this->assertSame(49, $analysis['source_chunk_count']);
        $this->assertSame(49, $analysis['analyzed_chunk_count']);
        $this->assertSame(5, $analysis['analysis_batch_count']);
        $this->assertSame(2, $analysis['aggregation_passes']);
        $this->assertSame('full_text_batches', $analysis['coverage_strategy']);
        $this->assertStringContainsString('Temuan penting pada halaman 49.', $analysis['main_findings']);
        $this->assertSame('supported', $analysis['analysis_status']);
        $this->assertLessThanOrEqual(30, count($analysis['evidence_references']));
        $this->assertDatabaseHas('paper_analyses', ['run_id' => $context->run->id, 'paper_id' => $paper->id]);
        $this->assertSame(8, AgentStep::query()->where('step_name', 'like', 'analysis:paper:'.$paper->id.':%')->count());
        $requests = count($inputs);
        $this->assertEquals($output, app(AnalysisAgent::class)->execute($context)->output);
        $this->assertCount($requests, $inputs);
    }

    public function test_retry_reuses_successful_batches_and_retries_only_failed_generation(): void
    {
        [$context, $paper] = $this->source(13);
        $inputs = [];
        $failed = false;
        $this->fakeAnalysis($inputs, function (array $input) use (&$failed): bool {
            if ($input['mode'] === 'extract' && $input['batch_number'] === 2 && ! $failed) {
                $failed = true;

                return true;
            }

            return false;
        });
        $this->pending($context);
        try {
            app(AnalysisAgent::class)->execute($context);
            $this->fail('Kegagalan gateway harus dikembalikan.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 429', $exception->getMessage());
        }
        $this->assertSame(1, AgentStep::query()->where('run_id', $context->run->id)->count());
        $analysis = $this->finish($context)['analyses'][0];
        $this->assertSame($paper->id, $analysis['paper_id']);
        $this->assertSame([1, 2, 2], array_column(array_filter($inputs, fn (array $input): bool => $input['mode'] === 'extract'), 'batch_number'));
        $this->assertDatabaseCount('paper_analyses', 1);
    }

    public function test_cancellation_during_generation_does_not_save_a_batch_or_paper(): void
    {
        [$context] = $this->source(13);
        $inputs = [];
        $this->fakeAnalysis($inputs, function () use ($context): bool {
            $context->run->update(['status' => 'cancelled']);

            return false;
        });

        $this->assertSame(['analyses' => []], app(AnalysisAgent::class)->execute($context)->output);
        $this->assertDatabaseCount('agent_steps', 0);
        $this->assertDatabaseCount('paper_analyses', 0);
        Http::assertSentCount(1);
    }

    public function test_retry_reuses_completed_hierarchical_merges(): void
    {
        [$context] = $this->source(49);
        $inputs = [];
        $mergeAttempts = 0;
        $this->fakeAnalysis($inputs, function (array $input) use (&$mergeAttempts): bool {
            if ($input['mode'] === 'aggregate') {
                $mergeAttempts++;

                return $mergeAttempts === 3;
            }

            return false;
        });
        for ($iteration = 0; $iteration < 10; $iteration++) {
            try {
                app(AnalysisAgent::class)->execute($context);
                $this->fail('Agregasi terakhir fixture harus gagal satu kali.');
            } catch (AnalysisPendingException) {
                continue;
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('HTTP 429', $exception->getMessage());
                break;
            }
        }
        $this->assertCount(8, $inputs);
        $this->assertDatabaseCount('paper_analyses', 0);
        $output = $this->finish($context);
        $this->assertCount(9, $inputs);
        $this->assertSame(4, $mergeAttempts);
        $this->assertStringContainsString('halaman 49', $output['analyses'][0]['main_findings']);
    }

    public function test_batch_cannot_cite_text_from_a_chunk_not_in_that_batch(): void
    {
        [$context, $paper, $document] = $this->source(13);
        $outside = $document->chunks()->orderByDesc('chunk_index')->firstOrFail();
        $inputs = [];
        $this->fakeAnalysis($inputs, transform: function (array $output, array $input) use ($outside, $paper): array {
            if ($input['mode'] === 'extract' && $input['batch_number'] === 1) {
                $output['evidence_references'][] = ['paper_id' => $paper->id, 'chunk_id' => $outside->id, 'quote' => $outside->content];
            }

            return $output;
        });

        $analysis = $this->finish($context)['analyses'][0];
        $this->assertSame('insufficient_evidence', $analysis['analysis_status']);
        $this->assertSame(1, $analysis['rejected_reference_count']);
        $this->assertSame($outside->id, $analysis['invalid_references'][0]['chunk_id']);
    }

    public function test_aggregate_cannot_add_a_quote_omitted_from_verified_batch_outputs(): void
    {
        [$context, $paper, $document] = $this->source(13);
        $omitted = $document->chunks()->orderBy('chunk_index')->firstOrFail();
        $inputs = [];
        $this->fakeAnalysis($inputs, transform: function (array $output, array $input) use ($omitted, $paper): array {
            if ($input['mode'] === 'aggregate') {
                $output['evidence_references'][] = ['paper_id' => $paper->id, 'chunk_id' => $omitted->id, 'quote' => $omitted->content];
            }

            return $output;
        });

        $analysis = $this->finish($context)['analyses'][0];
        $this->assertSame('insufficient_evidence', $analysis['analysis_status']);
        $this->assertSame(1, $analysis['rejected_reference_count']);
        $this->assertNotContains($omitted->id, array_column($analysis['evidence_references'], 'chunk_id'));
    }

    public function test_abstract_only_article_stays_a_single_generation(): void
    {
        [$context, $paper] = $this->source(0);
        $inputs = [];
        $this->fakeAnalysis($inputs);

        $analysis = app(AnalysisAgent::class)->execute($context)->output['analyses'][0];
        $this->assertSame('abstract_only', $analysis['coverage_strategy']);
        $this->assertSame(0, $analysis['analyzed_chunk_count']);
        $this->assertSame($paper->abstract, $analysis['evidence_references'][0]['quote']);
        $this->assertNull($analysis['evidence_references'][0]['chunk_id']);
        Http::assertSentCount(1);
    }

    public function test_second_paper_yields_after_first_paper_checkpoint(): void
    {
        [$context] = $this->source(1);
        $second = Paper::query()->create(['title' => 'Artikel kedua', 'abstract' => 'Temuan penting dari abstrak artikel kedua.']);
        $context->project->papers()->attach($second->id, ['is_selected' => true]);
        $inputs = [];
        $this->fakeAnalysis($inputs);

        $this->pending($context);
        $this->assertDatabaseCount('paper_analyses', 1);
        Http::assertSentCount(1);
        $output = app(AnalysisAgent::class)->execute($context)->output;
        $this->assertCount(2, $output['analyses']);
        Http::assertSentCount(2);
    }

    /** @return array{AgentContext, Paper, Document} */
    private function source(int $count): array
    {
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $paper = Paper::query()->create(['title' => 'Artikel panjang', 'abstract' => 'Temuan penting dari abstrak artikel panjang.']);
        $project->papers()->attach($paper->id, ['is_selected' => true]);
        $run = $project->runs()->create(['status' => 'analyzing', 'current_stage' => 'analyzing']);
        $document = $project->documents()->create([
            'paper_id' => $paper->id, 'original_filename' => 'artikel.pdf', 'storage_path' => 'testing/artikel.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 100, 'file_hash' => hash('sha256', (string) $project->id),
            'extraction_status' => 'indexed',
        ]);
        for ($index = 0; $index < $count; $index++) {
            $document->chunks()->create(['chunk_index' => $index, 'page_number' => $index + 1, 'content' => 'Temuan penting pada halaman '.($index + 1).'.']);
        }

        return [new AgentContext($project, $run), $paper, $document];
    }

    private function fakeAnalysis(array &$inputs, ?callable $fail = null, ?callable $transform = null): void
    {
        Http::fake([SenopatiService::ENDPOINT => function (Request $request) use (&$inputs, $fail, $transform) {
            $input = json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['data'];
            $inputs[] = $input;
            if ($fail !== null && $fail($input)) {
                return Http::response([], 429);
            }
            if ($input['mode'] === 'extract') {
                $chunk = $input['chunks'] === [] ? null : end($input['chunks']);
                $references = [['paper_id' => $input['paper']['id'], 'chunk_id' => $chunk['id'] ?? null, 'quote' => $chunk['content'] ?? $input['paper']['abstract']]];
                $findings = $references[0]['quote'];
            } else {
                $references = array_slice(array_merge(...array_column($input['parts'], 'evidence_references')), 0, 30);
                $references = array_map(fn (array $reference): array => array_intersect_key($reference, array_flip(['paper_id', 'chunk_id', 'quote'])), $references);
                $findings = implode(' ', array_column($input['parts'], 'main_findings'));
            }
            $output = ['title' => $input['paper']['title'], 'research_objective' => null, 'methodology' => null,
                'research_design' => null, 'sample' => null, 'variables' => null, 'main_findings' => $findings,
                'limitations' => null, 'recommendations' => null, 'evidence_references' => $references,
                'source_access_level' => $input['source_access_level'], 'analysis_status' => 'supported'];
            if ($transform !== null) {
                $output = $transform($output, $input);
            }

            return Http::response(GatewayFixture::chat(json_encode($output, JSON_THROW_ON_ERROR)), 200);
        }]);
    }

    private function pending(AgentContext $context): void
    {
        try {
            app(AnalysisAgent::class)->execute($context);
            $this->fail('Bagian berikutnya harus dijadwalkan pada job baru.');
        } catch (AnalysisPendingException) {
            $this->assertTrue(true);
        }
    }

    private function finish(AgentContext $context): array
    {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $count = Http::recorded()->count();
            try {
                $result = app(AnalysisAgent::class)->execute($context);
                $this->assertLessThanOrEqual(1, Http::recorded()->count() - $count);

                return $result->output;
            } catch (AnalysisPendingException) {
                $this->assertSame(1, Http::recorded()->count() - $count);
            }
        }
        $this->fail('Analisis tidak selesai dalam batas fixture.');
    }
}
