<?php

declare(strict_types=1);

namespace App\Services\Documents;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfParserService
{
    /** @return list<array{page_number: int, content: string}> */
    public function extract(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Berkas PDF tidak ditemukan di penyimpanan privat.');
        }
        try {
            $document = (new Parser)->parseFile($absolutePath);
            $pages = [];
            foreach ($document->getPages() as $index => $page) {
                $content = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $page->getText());
                $content = preg_replace('/[\h]+/u', ' ', $content) ?? $content;
                $content = trim(preg_replace('/\n{3,}/u', "\n\n", $content) ?? $content);
                $pages[] = ['page_number' => $index + 1, 'content' => $content];
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('PDF tidak dapat dibaca; berkas mungkin rusak atau terenkripsi.', 0, $exception);
        }
        if (trim(implode('', array_column($pages, 'content'))) === '') {
            throw new RuntimeException('PDF tidak memiliki teks yang dapat diekstrak. PDF hasil pemindaian memerlukan OCR; OCR belum tersedia.');
        }

        return $pages;
    }
}
