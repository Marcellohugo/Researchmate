<?php

namespace Tests\Feature;

use App\Models\Paper;
use App\Services\Academic\CitationFormatter;
use Tests\TestCase;

class CitationFormatterTest extends TestCase
{
    public function test_formats_apa_and_ieee_without_inventing_missing_metadata(): void
    {
        $paper = new Paper(['title' => 'AI and critical thinking', 'authors' => ['Smith, Jane', 'Budi'], 'publication_year' => 2026, 'doi' => 'https://doi.org/10.1234/Study', 'journal_name' => 'Journal of Learning']);
        $paper->forceFill(['volume' => '12', 'issue' => '2', 'pages' => '10–24']);
        $formatter = new CitationFormatter;

        $this->assertSame('Smith, J., & Budi (2026). AI and critical thinking. Journal of Learning, 12(2), 10–24. https://doi.org/10.1234/study', $formatter->format($paper));
        $this->assertSame('J. Smith and Budi, “AI and critical thinking,” Journal of Learning, vol. 12, no. 2, pp. 10–24, 2026. doi: 10.1234/study.', $formatter->format($paper, 'ieee'));
        $incomplete = $formatter->format(new Paper(['title' => 'Artikel tanpa metadata']));
        foreach (['[Penulis belum tersedia]', '[Tahun belum tersedia]', '[Jurnal belum tersedia]', '[DOI/URL belum tersedia]'] as $label) {
            $this->assertStringContainsString($label, $incomplete);
        }
    }

    public function test_accepts_structured_authors_and_safe_source_urls_only(): void
    {
        $formatter = new CitationFormatter;
        $paper = new Paper(['title' => 'Artikel', 'authors' => [['family' => 'van Dijk', 'given' => 'Anna Maria'], ['display_name' => 'Budi']], 'source_url' => 'https://journal.example/article']);

        $this->assertStringContainsString('van Dijk, A. M., & Budi', $formatter->format($paper));
        $this->assertStringContainsString('https://journal.example/article', $formatter->format($paper));
        $paper->source_url = 'https://secret:password@journal.example/article';
        $this->assertStringContainsString('[DOI/URL belum tersedia]', $formatter->format($paper));
        $paper->source_url = 'javascript:alert(1)';
        $this->assertStringNotContainsString('javascript:', $formatter->format($paper));
    }

    public function test_large_author_lists_follow_style_limits(): void
    {
        $paper = new Paper(['title' => 'Artikel', 'authors' => array_map(fn (int $id): string => 'Author'.$id.', Jane', range(1, 21))]);
        $formatter = new CitationFormatter;

        $this->assertStringContainsString('Author19, J., … Author21, J.', $formatter->format($paper));
        $this->assertStringNotContainsString('Author20', $formatter->format($paper));
        $this->assertStringStartsWith('J. Author1 et al.', $formatter->format($paper, 'ieee'));
    }
}
