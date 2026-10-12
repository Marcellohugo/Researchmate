<?php

declare(strict_types=1);

namespace App\Services\Documents;

use InvalidArgumentException;

class DocumentChunkerService
{
    /**
     * @param  list<array{page_number: int, content: string}>  $pages
     * @return list<array{chunk_index: int, page_number: int, section_name: ?string, content: string}>
     */
    public function chunk(array $pages): array
    {
        $size = (int) config('ai.chunk_size', 1200);
        $overlap = (int) config('ai.chunk_overlap', 200);
        if ($size < 100 || $overlap < 0 || $overlap >= $size) {
            throw new InvalidArgumentException('Ukuran potongan harus minimal 100; overlap harus lebih kecil daripada ukuran potongan.');
        }
        $chunks = [];
        $section = null;
        foreach ($pages as $page) {
            $text = trim($page['content']);
            $length = mb_strlen($text);
            $start = 0;
            while ($start < $length) {
                $end = min($start + $size, $length);
                if ($end < $length) {
                    $window = mb_substr($text, $start, $size);
                    $boundary = mb_strrpos($window, "\n\n");
                    $boundary = $boundary !== false ? $boundary : mb_strrpos($window, ' ');
                    if ($boundary !== false && $boundary > (int) ($size * 0.6)) {
                        $end = $start + $boundary;
                    }
                }
                $content = trim(mb_substr($text, $start, $end - $start));
                if ($content !== '') {
                    if (preg_match('/(?:^|\n)(?:\d+[.)]?\s+)?(Abstract|Abstrak|Introduction|Pendahuluan|Methods?|Metodologi|Metode|Results?|Hasil|Discussion|Pembahasan|Conclusion|Kesimpulan|Limitations|Keterbatasan)\s*(?:\n|$)/iu', $content, $heading) === 1) {
                        $section = $heading[1];
                    }
                    $chunks[] = ['chunk_index' => count($chunks), 'page_number' => $page['page_number'], 'section_name' => $section, 'content' => $content];
                }
                if ($end >= $length) {
                    break;
                }
                $start = max($start + 1, $end - $overlap);
            }
        }

        return $chunks;
    }
}
