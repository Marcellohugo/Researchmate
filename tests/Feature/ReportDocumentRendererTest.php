<?php

namespace Tests\Feature;

use App\Services\Reports\ReportDocumentRenderer;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PhpOffice\PhpWord\Settings;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class ReportDocumentRendererTest extends TestCase
{
    /**
     * @return array{title: string, subtitle: string, sections: list<array{heading: string, paragraphs: list<string>, table?: array{headers: list<string>, rows: list<list<string>>}}> }
     */
    private function report(): array
    {
        return [
            'title' => 'Laporan AI & kemampuan berpikir',
            'subtitle' => 'Peninjauan koleksi <artikel> oleh peneliti',
            'sections' => [
                ['heading' => 'Sintesis penelitian', 'paragraphs' => ['Temuan A & B memerlukan peninjauan sumber.', '<script>alert("riset")</script>']],
                ['heading' => 'Matriks literatur', 'paragraphs' => ['Bandingkan artikel dalam koleksi.'], 'table' => ['headers' => ['Tahun', 'Artikel'], 'rows' => [['2025', 'AI <pendidikan> & penelitian'], ['2026', 'Keterampilan berpikir kritis']]]],
            ],
        ];
    }

    public function test_docx_has_valid_ooxml_and_preserves_plain_text_without_inserting_markup(): void
    {
        $escaping = Settings::isOutputEscapingEnabled();
        $bytes = app(ReportDocumentRenderer::class)->render($this->report(), 'docx');
        $this->assertStringStartsWith('PK', $bytes);
        $this->assertSame($escaping, Settings::isOutputEscapingEnabled());
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
            $this->assertNotFalse($zip->locateName('word/styles.xml'));
            $this->assertNotFalse($zip->locateName('word/footer1.xml'));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertIsString($xml);
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertStringContainsString('Laporan AI & kemampuan berpikir', $document->textContent);
            $this->assertStringContainsString('<script>alert("riset")</script>', $document->textContent);
            $this->assertStringContainsString('AI <pendidikan> & penelitian', $document->textContent);
            $this->assertSame(1, $xpath->query('//w:pStyle[@w:val="Title"]')->length);
            $this->assertSame(1, $xpath->query('//w:tblHeader')->length);
            $this->assertSame(0, $xpath->query('//w:trHeight')->length);
            $this->assertSame(0, $document->getElementsByTagName('script')->length);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_pdf_is_readable_and_renders_html_like_input_as_text(): void
    {
        $bytes = app(ReportDocumentRenderer::class)->render($this->report(), 'pdf');
        $this->assertStringStartsWith('%PDF-', $bytes);
        $pdf = (new Parser)->parseContent($bytes);
        $text = $pdf->getText();
        $this->assertNotEmpty($pdf->getPages());
        $this->assertStringContainsString('Laporan AI & kemampuan berpikir', $text);
        $this->assertStringContainsString('<script>alert("riset")</script>', $text);
        $this->assertStringContainsString('AI <pendidikan> & penelitian', $text);
        $this->assertStringContainsString('Halaman 1 dari', $text);
    }

    public function test_docx_removes_illegal_xml_control_characters_from_every_report_field(): void
    {
        $report = [
            'title' => "Judul\x1F penelitian",
            'subtitle' => "Subjudul\x1F penelitian",
            'sections' => [[
                'heading' => "Bagian\x1F penelitian",
                'paragraphs' => ["Isi\x1F penelitian"],
                'table' => ['headers' => ["Kolom\x1F penelitian"], 'rows' => [["Sel\x1F penelitian"]]],
            ]],
        ];
        $bytes = app(ReportDocumentRenderer::class)->render($report, 'docx');
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertIsString($xml);
            $this->assertStringNotContainsString("\x1F", $xml);
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
            foreach (['Judul', 'Subjudul', 'Bagian', 'Isi', 'Kolom', 'Sel'] as $label) {
                $this->assertStringContainsString($label.' penelitian', $document->textContent);
            }
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_renderer_rejects_other_formats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ReportDocumentRenderer::class)->render($this->report(), 'html');
    }
}
