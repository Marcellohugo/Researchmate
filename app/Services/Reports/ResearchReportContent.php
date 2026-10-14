<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Services\Academic\CitationFormatter;
use App\Services\Academic\ProjectPaperMetadata;
use Illuminate\Support\Str;

class ResearchReportContent
{
    public function __construct(private CitationFormatter $citations, private ProjectPaperMetadata $metadata) {}

    /** @return array{title: string, subtitle: string, sections: list<array{heading: string, paragraphs: list<string>, table?: array{headers: list<string>, rows: list<list<string>>}}> } */
    public function build(ResearchProject $project, ResearchRun $run, string $style = 'apa'): array
    {
        $state = $run->workflow_state ?? [];
        $plan = $run->research_plan ?: ($state['planning'] ?? []);
        $snapshot = $state['project_snapshot'] ?? [];
        $analyses = $project->analyses()->where('run_id', $run->id)->with('paper')->get();
        $claims = $run->claims()->with('evidence.paper')->get();
        $gaps = $run->gaps()->get();
        $paperIds = $analyses->pluck('paper_id')->merge($claims->flatMap(fn ($claim) => $claim->evidence->pluck('paper_id')))->unique()->values();
        $papers = $project->papers()->whereIn('papers.id', $paperIds)->orderBy('papers.id')->get();
        $numberByPaper = [];
        $references = [];
        foreach ($papers as $index => $paper) {
            $numberByPaper[$paper->id] = $index + 1;
            $effective = $this->metadata->apply($paper, $project);
            $references[] = ($style === 'ieee' ? '['.($index + 1).'] ' : '').$this->citations->format($effective, $style);
        }
        if ($style === 'apa') {
            sort($references, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $sections = [[
            'heading' => 'Ringkasan penelitian',
            'paragraphs' => [
                'Pertanyaan riset: '.($snapshot['research_question'] ?? ($plan['research_question'] ?? $project->research_question)),
                'Status laporan: '.($run->status === 'completed' ? 'Disetujui oleh pengguna.' : 'Draf; menunggu persetujuan pengguna.'),
                'Mode literatur: '.(($snapshot['research_mode'] ?? $project->research_mode) === 'offline' ? 'Koleksi PDF proyek.' : 'Penelusuran metadata dan koleksi PDF proyek.'),
                'Koleksi yang dianalisis: '.$analyses->count().' artikel. Gaya daftar pustaka: '.strtoupper($style).'.',
            ],
        ]];
        $planning = [];
        foreach (['research_objectives' => 'Tujuan penelitian', 'main_keywords' => 'Kata kunci', 'search_queries' => 'Penelusuran', 'inclusion_criteria' => 'Kriteria inklusi', 'exclusion_criteria' => 'Kriteria eksklusi', 'planning_notes' => 'Catatan rencana'] as $field => $label) {
            if (! empty($plan[$field])) {
                $planning[] = $label.': '.$this->text($plan[$field]);
            }
        }
        $sections[] = ['heading' => 'Rencana penelitian', 'paragraphs' => $planning ?: ['Rencana belum tersedia.']];
        $synthesis = $state['synthesizing'] ?? [];
        $synthesisText = [$this->text($synthesis['synthesis'] ?? 'Sintesis belum tersedia.')];
        foreach (['common_topics' => 'Topik bersama', 'consistent_findings' => 'Temuan yang sejalan', 'inconsistent_findings' => 'Temuan yang berbeda'] as $field => $label) {
            if (! empty($synthesis[$field])) {
                $synthesisText[] = $label.': '.$this->text($synthesis[$field]);
            }
        }
        $sections[] = ['heading' => 'Sintesis literatur', 'paragraphs' => $synthesisText];
        $matrixRows = [];
        foreach ($analyses as $analysis) {
            $paper = $this->metadata->apply($analysis->paper, $project);
            $data = $analysis->structured_analysis;
            $matrixRows[] = [Str::limit($paper->title, 160), (string) ($paper->publication_year ?? 'Belum tersedia'), Str::limit($this->text($data['methodology'] ?? 'Tidak dilaporkan'), 180), $this->status($data['analysis_status'] ?? 'insufficient_evidence')];
        }
        $sections[] = ['heading' => 'Matriks literatur', 'paragraphs' => $analyses->isEmpty() ? ['Belum ada analisis pada run ini.'] : [],
            'table' => ['headers' => ['Artikel', 'Tahun', 'Metode', 'Status bukti'], 'rows' => $matrixRows]];
        foreach ($analyses as $analysis) {
            $paper = $this->metadata->apply($analysis->paper, $project);
            $data = $analysis->structured_analysis;
            $paragraphs = [];
            foreach (['research_objective' => 'Tujuan', 'methodology' => 'Metode', 'research_design' => 'Desain', 'sample' => 'Sampel', 'variables' => 'Variabel', 'main_findings' => 'Temuan', 'limitations' => 'Keterbatasan', 'recommendations' => 'Rekomendasi'] as $field => $label) {
                $paragraphs[] = $label.': '.$this->text($data[$field] ?? 'Tidak dilaporkan');
            }
            $paragraphs[] = 'Status bukti: '.$this->status($data['analysis_status'] ?? 'insufficient_evidence').'. Status metadata: '.$this->status($paper->metadata_verification_status).'.';
            if (isset($data['analyzed_chunk_count'], $data['source_chunk_count'])) {
                $paragraphs[] = 'Cakupan teks: '.$data['analyzed_chunk_count'].' dari '.$data['source_chunk_count'].' potongan teks PDF dianalisis.';
            } else {
                $paragraphs[] = 'Cakupan run historis: paling banyak 12 cuplikan artikel; rincian lain dapat terlewat.';
            }
            foreach ($data['evidence_references'] ?? [] as $source) {
                $paragraphs[] = 'Bukti'.(isset($source['page_number']) ? ' halaman '.$source['page_number'] : ' abstrak').': '.$this->text($source['quote'] ?? '');
            }
            $sections[] = ['heading' => 'Analisis artikel '.($numberByPaper[$paper->id] ?? '').' '.$paper->title, 'paragraphs' => $paragraphs];
        }
        $gapText = [];
        foreach ($gaps as $gap) {
            $gapText[] = $gap->title.': '.$gap->description;
            foreach (['Alasan' => $gap->rationale, 'Pertanyaan lanjutan' => $gap->proposed_question, 'Metodologi yang disarankan' => $gap->suggested_methodology, 'Batas bukti' => $gap->supporting_evidence['limitations'] ?? null] as $label => $value) {
                if ($value !== null && $value !== '') {
                    $gapText[] = $label.': '.$this->text($value);
                }
            }
            $gapText[] = 'Status bukti: '.$this->status($gap->review_status).'.';
            foreach ($gap->supporting_evidence['references'] ?? [] as $source) {
                $gapText[] = 'Artikel '.($numberByPaper[$source['paper_id'] ?? 0] ?? '?').(isset($source['page_number']) ? ', halaman '.$source['page_number'] : ', abstrak').': '.$this->text($source['quote'] ?? '');
            }
        }
        $sections[] = ['heading' => 'Kandidat celah penelitian', 'paragraphs' => $gapText ?: ['Belum ada kandidat celah pada run ini.']];
        $claimText = [];
        foreach ($claims as $claim) {
            $claimText[] = $claim->claim_text.' Status bukti: '.$this->status($claim->verification_status).'.';
            foreach ($claim->evidence as $source) {
                $claimText[] = 'Artikel '.($numberByPaper[$source->paper_id] ?? '?').($source->chunk_id !== null ? ', cuplikan '.$source->chunk_id : ', abstrak').': '.$this->text($source->evidence_text);
            }
        }
        $sections[] = ['heading' => 'Verifikasi klaim', 'paragraphs' => $claimText ?: ['Belum ada klaim pada run ini.']];
        $sections[] = ['heading' => 'Catatan pemeriksaan akhir', 'paragraphs' => [
            $this->text($state['verifying']['feedback'] ?? 'Catatan pemeriksaan belum tersedia.'),
            'Jumlah putaran revisi: '.($state['revision_count'] ?? 0).'.',
            'Batas revisi tercapai: '.(($state['revision_limit_reached'] ?? false) ? 'Ya; masalah tersisa perlu ditinjau pengguna.' : 'Tidak.'),
        ]];
        $sections[] = ['heading' => 'Daftar pustaka', 'paragraphs' => $references ?: ['Belum ada sumber yang dianalisis pada run ini.']];
        $sections[] = ['heading' => 'Batas analisis dan penggunaan', 'paragraphs' => [
            'Kandidat celah berdasarkan koleksi proyek, bukan bukti bahwa suatu topik belum pernah diteliti. Kutipan yang cocok tidak menjamin ketepatan interpretasi ilmiah. Status verified bukan jaminan kebenaran ilmiah atau kualitas penelitian.',
            'Pengolahan teks PDF memerlukan teks yang dapat diekstrak; OCR belum tersedia. Analisis teks tidak memeriksa gambar dan tabel yang gagal diekstrak. Detail dapat terlewat saat peringkasan antar-batch. Hasil memerlukan tinjauan ilmiah oleh manusia.',
            'Mode literatur offline membatasi sumber pada dokumen lokal. Chat, agen, dan embedding memakai gateway Senopati dan memerlukan internet. Metadata offline tidak diverifikasi melalui layanan eksternal. Persetujuan pengguna tidak mengubah batas tersebut.',
        ]];

        return ['title' => (string) ($snapshot['title'] ?? $project->title), 'subtitle' => 'Tinjauan literatur dan kandidat celah penelitian', 'sections' => $sections];
    }

    private function status(string $status): string
    {
        $label = __('evidence.'.$status);

        return is_string($label) && $label !== 'evidence.'.$status ? $label : $status;
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return implode('; ', array_map(fn ($item) => $this->text($item), $value));
        }

        return is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value) : 'Tidak dilaporkan';
    }

    /** @param array<string, mixed> $report */
    public function markdown(array $report): string
    {
        $lines = ['# '.$report['title'], '', $report['subtitle'], ''];
        foreach ($report['sections'] as $section) {
            $lines[] = '## '.$section['heading'];
            $lines[] = '';
            foreach ($section['paragraphs'] as $paragraph) {
                $lines[] = $paragraph;
                $lines[] = '';
            }
            if (! empty($section['table']['rows'])) {
                $escape = fn (string $text): string => str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], $text);
                $lines[] = '| '.implode(' | ', array_map($escape, $section['table']['headers'])).' |';
                $lines[] = '| '.implode(' | ', array_fill(0, count($section['table']['headers']), '---')).' |';
                foreach ($section['table']['rows'] as $row) {
                    $lines[] = '| '.implode(' | ', array_map($escape, $row)).' |';
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
