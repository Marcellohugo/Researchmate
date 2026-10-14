<?php

namespace Tests\Feature;

use App\Models\Paper;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /** @return array<string, array{string, string}> */
    public static function reportFormats(): array
    {
        return ['Word' => ['docx', 'word_report'], 'PDF' => ['pdf', 'pdf_report'], 'Markdown' => ['markdown', 'markdown_report']];
    }

    #[DataProvider('reportFormats')]
    public function test_readable_exports_include_only_the_latest_run_and_reviewed_citations(string $format, string $type): void
    {
        $project = ResearchProject::factory()->create(['title' => 'Kajian AI & pendidikan']);
        $old = $project->runs()->create(['status' => 'completed']);
        $run = $project->runs()->create(['status' => 'awaiting_approval', 'workflow_state' => ['synthesizing' => ['synthesis' => 'Temuan sintesis terbaru.'], 'verifying' => ['feedback' => 'Periksa atribusi temuan.']]]);
        $paper = Paper::query()->create(['title' => 'Judul kanonis', 'authors' => ['Nama Lama'], 'publication_year' => 2010]);
        $otherPaper = Paper::query()->create(['title' => 'Artikel dari run lama']);
        $project->papers()->attach($paper->id);
        $project->papers()->attach($otherPaper->id);
        $project->projectPapers()->where('paper_id', $paper->id)->update(['metadata_overrides' => [
            'title' => 'Studi pendidikan', 'authors' => ['Hugo, Marcello'], 'publication_year' => 2025, 'journal_name' => 'Jurnal Pendidikan',
            'doi' => '10.1234/education', 'metadata_verification_status' => 'metadata_human_reviewed',
        ]]);
        $this->analysis($project, $run, $paper);
        $this->analysis($project, $old, $otherPaper);
        $claim = $run->claims()->create(['project_id' => $project->id, 'claim_text' => 'Klaim perlu ditinjau', 'verification_status' => 'needs_human_review']);
        $claim->evidence()->create(['paper_id' => $paper->id, 'evidence_text' => 'Temuan kutipan', 'support_type' => 'supporting']);
        $this->actingAs($project->user)->get(route('projects.reports', $project))->assertOk()
            ->assertSee('Studi pendidikan')->assertDontSee('Judul kanonis');
        $this->actingAs($project->user)->post(route('projects.reports.export', $project), ['format' => $format, 'citation_style' => 'apa'])
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.reports', $project));
        $artifact = $project->artifacts()->firstOrFail();
        $this->assertSame($type, $artifact->artifact_type);
        $this->assertSame($run->id, $artifact->run_id);
        $bytes = Storage::disk('local')->get($artifact->storage_path);
        if ($format === 'docx') {
            $this->assertStringStartsWith('PK', $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($artifact->storage_path)));
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($xml));
            $text = $doc->textContent;
        } elseif ($format === 'pdf') {
            $this->assertStringStartsWith('%PDF-', $bytes);
            $text = (new Parser)->parseContent($bytes)->getText();
        } else {
            $text = $bytes;
        }
        foreach (['Kajian AI & pendidikan', 'Draf', 'Temuan sintesis terbaru', 'Daftar pustaka', 'Hugo, M.', '2025', '10.1234/education', 'Klaim perlu ditinjau'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringNotContainsString('Artikel dari run lama', $text);
        $this->get(route('projects.reports.download', [$project, $artifact]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('projects.reports.download', [$project, $artifact]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_export_validates_format_and_style_and_requires_verification_checkpoint(): void
    {
        $project = ResearchProject::factory()->create();
        $this->actingAs($project->user)->post(route('projects.reports.export', $project), ['format' => 'html', 'citation_style' => 'unknown'])
            ->assertSessionHasErrors(['format', 'citation_style']);
        $this->post(route('projects.reports.export', $project), ['format' => 'docx'])->assertSessionHasErrors('report');
        $this->assertDatabaseCount('research_artifacts', 0);
    }

    private function analysis(ResearchProject $project, ResearchRun $run, Paper $paper): void
    {
        $project->analyses()->create(['run_id' => $run->id, 'paper_id' => $paper->id, 'analysis_model' => 'test', 'evidence_level' => 'full_text',
            'structured_analysis' => ['methodology' => 'Eksperimen', 'main_findings' => 'Temuan berdasarkan koleksi.', 'analysis_status' => 'supported',
                'source_chunk_count' => 24, 'analyzed_chunk_count' => 24, 'evidence_references' => []]]);
    }
}
