<?php

namespace Tests\Feature;

use App\Services\Documents\DocumentChunkerService;
use App\Services\Documents\PdfParserService;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    public function test_chunking_preserves_original_page_and_overlap_without_infinite_loop(): void
    {
        config(['ai.chunk_size' => 100, 'ai.chunk_overlap' => 20]);
        $text = str_repeat('abcdefghij', 25);
        $chunks = app(DocumentChunkerService::class)->chunk([['page_number' => 3, 'content' => $text]]);
        $this->assertCount(3, $chunks);
        $this->assertSame(3, $chunks[2]['page_number']);
        $this->assertSame(mb_substr($chunks[0]['content'], -20), mb_substr($chunks[1]['content'], 0, 20));
        $this->assertSame([0, 1, 2], array_column($chunks, 'chunk_index'));
    }

    public function test_real_pdf_parser_preserves_page_and_reports_scanned_or_blank_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'researchmate-pdf-');
        try {
            file_put_contents($path, PdfFixture::make());
            $pages = app(PdfParserService::class)->extract($path);
            $this->assertSame(1, $pages[0]['page_number']);
            $this->assertStringContainsString('Teks bukti riset', $pages[0]['content']);
            file_put_contents($path, PdfFixture::make(''));
            $this->expectExceptionMessage('OCR');
            app(PdfParserService::class)->extract($path);
        } finally {
            unlink($path);
        }
    }

    public function test_chunking_preserves_detected_section_across_pages(): void
    {
        $chunks = app(DocumentChunkerService::class)->chunk([
            ['page_number' => 1, 'content' => "2. Methods\nData penelitian."],
            ['page_number' => 2, 'content' => 'Lanjutan metode.'],
        ]);
        $this->assertSame('Methods', $chunks[0]['section_name']);
        $this->assertSame('Methods', $chunks[1]['section_name']);
        $this->assertSame(2, $chunks[1]['page_number']);
    }
}
