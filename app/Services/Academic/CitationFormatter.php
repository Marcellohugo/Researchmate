<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Paper;
use InvalidArgumentException;

class CitationFormatter
{
    public function format(Paper $paper, string $style = 'apa'): string
    {
        if (! in_array($style, ['apa', 'ieee'], true)) {
            throw new InvalidArgumentException('Gaya sitasi harus APA atau IEEE.');
        }
        $authors = $this->authors($paper, $style);
        $year = $paper->publication_year !== null ? (string) $paper->publication_year : '[Tahun belum tersedia]';
        $title = rtrim($this->text($paper->title) ?: '[Judul belum tersedia]', '.');
        $journal = $this->text($paper->journal_name) ?: '[Jurnal belum tersedia]';
        $volume = $this->text($paper->getAttribute('volume'));
        $issue = $this->text($paper->getAttribute('issue'));
        $pages = $this->text($paper->getAttribute('pages'));
        $doi = OpenAlexService::normalizeDoi($paper->doi);
        $url = $doi !== null ? 'https://doi.org/'.str_replace('%2F', '/', rawurlencode($doi)) : $this->safeUrl($paper->source_url);

        if ($style === 'apa') {
            $citation = $authors.' ('.$year.'). '.$title.'. '.$journal;
            if ($volume !== '' || $issue !== '') {
                $citation .= ', '.($volume !== '' ? $volume : '[Volume belum tersedia]').($issue !== '' ? '('.$issue.')' : '');
            }
            if ($pages !== '') {
                $citation .= ', '.$pages;
            }

            return $citation.'. '.($url ?: '[DOI/URL belum tersedia].');
        }

        $citation = $authors.', “'.$title.',” '.$journal;
        if ($volume !== '') {
            $citation .= ', vol. '.$volume;
        }
        if ($issue !== '') {
            $citation .= ', no. '.$issue;
        }
        if ($pages !== '') {
            $citation .= ', pp. '.$pages;
        }
        $citation .= ', '.$year.'.';

        return $citation.($doi !== null ? ' doi: '.$doi.'.' : ($url !== null ? ' [Online]. Available: '.$url : ' [DOI/URL belum tersedia].'));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    }

    private function safeUrl(?string $url): ?string
    {
        $parts = $url !== null ? parse_url($url) : false;

        return $parts !== false && isset($parts['host']) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }

    private function authors(Paper $paper, string $style): string
    {
        $names = [];
        foreach ($paper->authors ?? [] as $author) {
            if (is_array($author)) {
                $author = isset($author['family'])
                    ? $this->text($author['family']).', '.$this->text($author['given'] ?? '')
                    : ($author['name'] ?? $author['display_name'] ?? '');
            }
            $name = $this->text($author);
            if ($name === '') {
                continue;
            }
            if (str_contains($name, ',')) {
                [$family, $given] = array_map('trim', explode(',', $name, 2));
            } else {
                // shortcut: the last word is the family name; use explicit family/given fields or a comma for ambiguous names.
                $parts = explode(' ', $name);
                $family = array_pop($parts);
                $given = implode(' ', $parts);
            }
            $initials = implode(' ', array_map(fn (string $part): string => mb_substr($part, 0, 1).'.', array_filter(preg_split('/[\s-]+/u', $given) ?: [])));
            $names[] = $initials === '' ? $family : ($style === 'apa' ? $family.', '.$initials : $initials.' '.$family);
        }
        if ($names === []) {
            return '[Penulis belum tersedia]';
        }
        if ($style === 'ieee') {
            if (count($names) > 6) {
                return $names[0].' et al.';
            }
            $last = array_pop($names);

            return $names === [] ? $last : implode(', ', $names).(count($names) === 1 ? ' and ' : ', and ').$last;
        }
        if (count($names) > 20) {
            return implode(', ', array_slice($names, 0, 19)).', … '.end($names);
        }
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names).', & '.$last;
    }
}
