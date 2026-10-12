<?php

declare(strict_types=1);

namespace App\Services\Academic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CrossrefService
{
    /** @return array<string, mixed>|null */
    public function metadata(string $doi): ?array
    {
        $normalized = OpenAlexService::normalizeDoi($doi);
        if ($normalized === null) {
            throw new \InvalidArgumentException('DOI tidak valid.');
        }

        return Cache::remember('crossref:'.hash('sha256', $normalized), now()->addHour(), function () use ($normalized): ?array {
            try {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(max(1, min(120, (int) config('ai.academic_timeout', 15))))->withoutRedirecting()->retry(2, 500, throw: false)
                    ->get('https://api.crossref.org/works/'.rawurlencode($normalized));
            } catch (ConnectionException $exception) {
                throw new \RuntimeException('Crossref tidak dapat dihubungi atau waktu tunggu habis.', 0, $exception);
            }
            if ($response->status() === 404) {
                return null;
            }
            if ($response->status() === 429) {
                throw new \RuntimeException('Batas permintaan Crossref tercapai. Metadata belum diverifikasi.');
            }
            if (! $response->successful()) {
                throw new \RuntimeException('Crossref tidak dapat diakses. Metadata belum diverifikasi.');
            }
            $message = $response->json('message');
            if (! is_array($message) || OpenAlexService::normalizeDoi($message['DOI'] ?? null) !== $normalized) {
                throw new \RuntimeException('Metadata Crossref tidak cocok dengan DOI yang diminta.');
            }

            return $message;
        });
    }
}
