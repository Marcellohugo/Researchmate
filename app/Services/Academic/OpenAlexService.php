<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Paper;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OpenAlexService
{
    /** @return list<array<string, mixed>> */
    public function search(string $query, ?int $yearFrom = null, ?int $yearTo = null, int $limit = 20): array
    {
        $parameters = ['search' => $query, 'per-page' => max(1, min($limit, (int) config('ai.max_papers', 20)))];
        $filters = [];
        if ($yearFrom !== null) {
            $filters[] = 'from_publication_date:'.$yearFrom.'-01-01';
        }
        if ($yearTo !== null) {
            $filters[] = 'to_publication_date:'.$yearTo.'-12-31';
        }
        if ($filters !== []) {
            $parameters['filter'] = implode(',', $filters);
        }
        $key = (string) config('ai.openalex_key', '');

        return Cache::remember('openalex:'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR)), now()->addHour(), function () use ($parameters, $key): array {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(max(1, min(120, (int) config('ai.academic_timeout', 15))))->withoutRedirecting()->retry(2, 500, throw: false);
            if ($key !== '') {
                $request->withToken($key);
            }
            try {
                $response = $request->get('https://api.openalex.org/works', $parameters);
            } catch (ConnectionException $exception) {
                throw new \RuntimeException('OpenAlex tidak dapat dihubungi atau waktu tunggu habis.', 0, $exception);
            }
            if (in_array($response->status(), [401, 403], true)) {
                throw new \RuntimeException('OpenAlex menolak autentikasi. Periksa OPENALEX_API_KEY di konfigurasi lokal.');
            }
            if ($response->status() === 429) {
                throw new \RuntimeException('Batas permintaan OpenAlex tercapai. Tunggu sebelum mencoba lagi.');
            }
            if (! $response->successful()) {
                throw new \RuntimeException('OpenAlex tidak dapat diakses (HTTP '.$response->status().'). Hasil sebelumnya tetap tersimpan.');
            }
            $results = $response->json('results');
            if (! is_array($results)) {
                throw new \RuntimeException('Format respons OpenAlex tidak valid.');
            }
            $papers = [];
            foreach ($results as $record) {
                if (is_array($record) && is_string($record['title'] ?? null) && $record['title'] !== '') {
                    $papers[] = $this->normalize($record);
                }
            }

            return $papers;
        });
    }

    public static function normalizeDoi(?string $doi): ?string
    {
        if ($doi === null) {
            return null;
        }
        $doi = strtolower(trim(preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', trim($doi)) ?? ''));

        return preg_match('~^10\.\d{4,9}/[^\s\x00-\x1f]+$~', $doi) === 1 ? $doi : null;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalize(array $record): array
    {
        $authors = [];
        foreach ((array) ($record['authorships'] ?? []) as $author) {
            $name = is_array($author) ? ($author['author']['display_name'] ?? null) : null;
            if (is_string($name)) {
                $authors[] = $name;
            }
        }
        $abstract = null;
        $index = $record['abstract_inverted_index'] ?? null;
        if (is_array($index)) {
            $words = [];
            foreach ($index as $word => $positions) {
                foreach ((array) $positions as $position) {
                    if (is_int($position) && $position >= 0 && $position < 20000) {
                        $words[$position] = $word;
                    }
                }
            }
            ksort($words);
            $abstract = implode(' ', $words);
        }
        $source = is_array($record['primary_location'] ?? null) ? $record['primary_location'] : [];
        $doi = self::normalizeDoi(is_string($record['doi'] ?? null) ? $record['doi'] : null);

        return [
            'doi' => $doi, 'title' => mb_substr((string) $record['title'], 0, 2000), 'abstract' => $abstract,
            'authors' => $authors, 'publication_year' => is_int($record['publication_year'] ?? null) ? $record['publication_year'] : null,
            'journal_name' => is_string($source['source']['display_name'] ?? null) ? mb_substr($source['source']['display_name'], 0, 255) : null,
            'source_url' => $this->sourceUrl($source['landing_page_url'] ?? null) ?? ($doi ? 'https://doi.org/'.$doi : null),
            'openalex_id' => is_string($record['id'] ?? null) && preg_match('~^https://openalex\.org/W\d+$~', $record['id']) === 1 ? $record['id'] : null,
            'metadata_source' => 'openalex', 'metadata_verification_status' => 'metadata_unverified',
        ];
    }

    private function sourceUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 4000) {
            return null;
        }
        $parts = parse_url($url);

        return $parts !== false && isset($parts['host']) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }

    /** @param list<array<string, mixed>> $records
     * @return list<int>
     */
    public function save(ResearchProject $project, array $records, ?ResearchRun $run = null): array
    {
        return DB::transaction(function () use ($project, $records, $run): array {
            $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($run === null && $locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw new \RuntimeException('Koleksi literatur tidak dapat diubah saat alur riset masih berjalan.');
            }
            if ($run !== null) {
                $lockedRun = ResearchRun::query()->where('project_id', $locked->id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($lockedRun->status === 'cancelled') {
                    throw new \RuntimeException('Alur dibatalkan; hasil penelusuran tidak disimpan.');
                }
            }
            $limit = (int) config('ai.max_papers', 20);
            $attached = array_fill_keys($locked->papers()->pluck('papers.id')->all(), true);
            $ids = [];
            foreach ($records as $rank => $record) {
                $paper = null;
                if (! empty($record['doi'])) {
                    $paper = Paper::query()->where('doi', $record['doi'])->first();
                }
                if ($paper === null && ! empty($record['openalex_id'])) {
                    $paper = Paper::query()->where('openalex_id', $record['openalex_id'])->first();
                }
                if (($paper === null || ! isset($attached[$paper->id])) && count($attached) >= $limit) {
                    continue;
                }
                $paper ??= Paper::query()->create($record);
                $locked->papers()->syncWithoutDetaching([$paper->id => ['relevance_score' => max(0, 1 - $rank / max(1, count($records)))]]);
                $ids[] = $paper->id;
                $attached[$paper->id] = true;
            }

            return array_values(array_unique($ids));
        });
    }
}
