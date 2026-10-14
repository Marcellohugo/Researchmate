<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use RuntimeException;

class ReportDocumentRenderer
{
    /**
     * @param  array{title: string, subtitle?: string, sections: list<array{heading: string, paragraphs: list<string>, table?: array{headers: list<string>, rows: list<list<string>>}}> }  $report
     */
    public function render(array $report, string $format): string
    {
        array_walk_recursive($report, static function (&$value): void {
            if (is_string($value)) {
                $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($value, 'UTF-8')) ?? '';
            }
        });

        return match ($format) {
            'docx' => $this->docx($report),
            'pdf' => $this->pdf($report),
            default => throw new InvalidArgumentException('Format laporan tidak didukung.'),
        };
    }

    /**
     * @param  array{title: string, subtitle?: string, sections: list<array{heading: string, paragraphs: list<string>, table?: array{headers: list<string>, rows: list<list<string>>}}> }  $report
     */
    private function docx(array $report): string
    {
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);
        $path = tempnam(sys_get_temp_dir(), 'researchmate-report-');
        if ($path === false) {
            Settings::setOutputEscapingEnabled($escaping);
            throw new RuntimeException('Tidak dapat membuat berkas laporan sementara.');
        }

        try {
            $word = new PhpWord;
            $word->setDefaultFontName('Times New Roman');
            $word->setDefaultFontSize(11);
            $word->setDefaultParagraphStyle(['spaceAfter' => 120, 'lineHeight' => 1.15]);
            $word->addTitleStyle(0, ['name' => 'Times New Roman', 'size' => 20, 'bold' => true, 'color' => '000000'], ['spaceAfter' => 180, 'keepNext' => true]);
            $word->addTitleStyle(1, ['name' => 'Times New Roman', 'size' => 13, 'bold' => true, 'color' => '000000'], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true]);
            $section = $word->addSection([
                'paperSize' => 'A4',
                'marginLeft' => Converter::cmToTwip(2.5),
                'marginRight' => Converter::cmToTwip(2.5),
                'marginTop' => Converter::cmToTwip(2.5),
                'marginBottom' => Converter::cmToTwip(2.5),
            ]);
            $section->addFooter()->addPreserveText('Halaman {PAGE} dari {NUMPAGES}', ['size' => 9, 'color' => '000000'], ['alignment' => 'center', 'spaceAfter' => 0]);
            $section->addTitle($report['title'], 0);
            if (($report['subtitle'] ?? '') !== '') {
                $section->addText($report['subtitle'], ['color' => '000000'], ['spaceAfter' => 180]);
            }
            foreach ($report['sections'] as $item) {
                $section->addTitle($item['heading'], 1);
                foreach ($item['paragraphs'] as $paragraph) {
                    $section->addText($paragraph);
                }
                if (! isset($item['table']) || $item['table']['headers'] === []) {
                    continue;
                }
                $table = $section->addTable([
                    'borderSize' => 4,
                    'borderColor' => 'D9D9D9',
                    'cellMargin' => 90,
                    'layout' => 'fixed',
                ]);
                $widths = $this->columnWidths($item['table']);
                $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
                foreach ($item['table']['headers'] as $index => $header) {
                    $table->addCell($widths[$index], ['bgColor' => 'F2F2F2', 'valign' => 'center'])->addText($header, ['size' => 9, 'bold' => true, 'color' => '000000'], ['spaceAfter' => 0, 'lineHeight' => 1.1]);
                }
                foreach ($item['table']['rows'] as $row) {
                    $table->addRow();
                    foreach ($item['table']['headers'] as $index => $header) {
                        $table->addCell($widths[$index], ['valign' => 'center'])->addText($row[$index] ?? '', ['size' => 9, 'color' => '000000'], ['spaceAfter' => 0, 'lineHeight' => 1.1]);
                    }
                }
                $section->addTextBreak(1, null, ['spaceAfter' => 0]);
            }
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Tidak dapat membaca berkas laporan.');
            }

            return $bytes;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
            unlink($path);
        }
    }

    /**
     * @param  array{title: string, subtitle?: string, sections: list<array{heading: string, paragraphs: list<string>, table?: array{headers: list<string>, rows: list<list<string>>}}> }  $report
     */
    private function pdf(array $report): string
    {
        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.$this->escape($report['title']).'</title><style>
            @page { size: A4; margin: 25mm; }
            body { font-family: "DejaVu Serif", serif; font-size: 11pt; line-height: 1.35; color: #000; }
            h1 { font-size: 20pt; margin: 0 0 10pt; font-weight: bold; }
            h2 { font-size: 13pt; margin: 16pt 0 7pt; font-weight: bold; }
            h1, h2 { page-break-after: avoid; }
            p { margin: 0 0 8pt; orphans: 2; widows: 2; }
            .subtitle { margin-bottom: 12pt; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 6pt 0 12pt; font-size: 9pt; line-height: 1.2; }
            thead { display: table-header-group; page-break-after: avoid; }
            tr { page-break-inside: avoid; }
            th, td { border: 0.5pt solid #d9d9d9; padding: 5pt; vertical-align: middle; text-align: left; overflow-wrap: break-word; }
            th { background: #f2f2f2; font-weight: bold; }
        </style></head><body><h1>'.$this->escape($report['title']).'</h1>';
        if (($report['subtitle'] ?? '') !== '') {
            $html .= '<p class="subtitle">'.$this->escape($report['subtitle']).'</p>';
        }
        foreach ($report['sections'] as $section) {
            $html .= '<h2>'.$this->escape($section['heading']).'</h2>';
            foreach ($section['paragraphs'] as $paragraph) {
                $html .= '<p>'.nl2br($this->escape($paragraph)).'</p>';
            }
            if (! isset($section['table']) || $section['table']['headers'] === []) {
                continue;
            }
            $widths = $this->columnWidths($section['table']);
            $html .= '<table><thead><tr>';
            foreach ($section['table']['headers'] as $index => $header) {
                $html .= '<th style="width: '.($widths[$index] / 9072 * 100).'%">'.$this->escape($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($section['table']['rows'] as $row) {
                $html .= '<tr>';
                foreach ($section['table']['headers'] as $index => $header) {
                    $html .= '<td>'.nl2br($this->escape($row[$index] ?? '')).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</body></html>';
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Serif');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Serif');
        $pdf->getCanvas()->page_text(230, 797, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 9);

        return $pdf->output();
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<string>>}  $table
     * @return list<int>
     */
    private function columnWidths(array $table): array
    {
        $weights = [];
        foreach ($table['headers'] as $index => $header) {
            $length = mb_strlen($header);
            foreach ($table['rows'] as $row) {
                $length = max($length, mb_strlen($row[$index] ?? ''));
            }
            $weights[] = max(12, min(40, $length));
        }
        $total = array_sum($weights);

        return array_map(fn (int $weight): int => (int) round(9072 * $weight / $total), $weights);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
