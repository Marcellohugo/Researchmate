<?php

namespace Tests\Feature;

use App\Models\ResearchProject;
use App\Services\Academic\CrossrefService;
use App\Services\Academic\OpenAlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcademicServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_openalex_normalizes_real_response_caches_and_deduplicates_metadata(): void
    {
        Http::fake(['api.openalex.org/*' => Http::response(['results' => [['id' => 'https://openalex.org/W123', 'title' => 'Artikel fixture respons API', 'doi' => 'https://doi.org/10.1234/TEST', 'publication_year' => 2024, 'authorships' => [['author' => ['display_name' => 'Penulis Fixture']]], 'abstract_inverted_index' => ['Argumen' => [0], 'kritis' => [1]], 'primary_location' => ['source' => ['display_name' => 'Jurnal Fixture']]]]])]);
        $service = app(OpenAlexService::class);
        $records = $service->search('AI students', 2020, 2025, 5);
        $this->assertSame('10.1234/test', $records[0]['doi']);
        $this->assertSame('Argumen kritis', $records[0]['abstract']);
        $project = ResearchProject::factory()->create(['research_mode' => 'online']);
        $service->save($project, $records);
        $service->save($project, $service->search('AI students', 2020, 2025, 5));
        $this->assertDatabaseCount('papers', 1);
        $this->assertDatabaseCount('project_papers', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['filter'] === 'from_publication_date:2020-01-01,to_publication_date:2025-12-31');
    }

    public function test_openalex_failure_is_visible_and_does_not_create_papers(): void
    {
        Http::fake(['api.openalex.org/*' => Http::response([], 429)]);
        $project = ResearchProject::factory()->create(['research_mode' => 'online']);
        $this->actingAs($project->user)->post(route('projects.literature.search', $project), ['query' => 'AI'])->assertSessionHasErrors('query');
        $this->assertDatabaseCount('papers', 0);
    }

    public function test_offline_search_controller_never_calls_academic_api(): void
    {
        Http::preventStrayRequests();
        $project = ResearchProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.literature.search', $project), ['query' => 'AI'])->assertSessionHasErrors('query');
        Http::assertNothingSent();
    }

    public function test_crossref_uses_fixed_host_and_checks_returned_doi(): void
    {
        Http::fake(['api.crossref.org/*' => Http::response(['message' => ['DOI' => '10.1234/test', 'title' => ['Judul fixture']]])]);
        $result = app(CrossrefService::class)->metadata('https://doi.org/10.1234/TEST');
        $this->assertSame('10.1234/test', $result['DOI']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.crossref.org/works/10.1234%2Ftest');
        $this->assertNull(OpenAlexService::normalizeDoi('https://evil.example/metadata'));
        $this->assertNull(OpenAlexService::normalizeDoi("10.1234/test\ninvalid"));
    }

    public function test_openalex_key_is_sent_only_in_authorization_header(): void
    {
        config(['ai.openalex_key' => 'fixture-key']);
        Http::fake(['api.openalex.org/*' => Http::response(['results' => []])]);
        app(OpenAlexService::class)->search('AI');
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fixture-key') && ! str_contains($request->url(), 'fixture-key'));
    }
}
