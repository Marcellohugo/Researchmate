<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class SenopatiService
{
    public function __construct(private JsonSchemaValidator $validator) {}

    public const ENDPOINT = 'https://senopati.its.ac.id/llm-gateway/v1/chat/completions';

    public const EMBEDDINGS_ENDPOINT = 'https://senopati.its.ac.id/llm-gateway/v1/embeddings';

    public const MODELS_ENDPOINT = 'https://senopati.its.ac.id/llm-gateway/v1/models';

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $options = []): array
    {
        // Endpoint tetap mencegah token dikirim ke host lain atau melalui redirect.
        foreach ($messages as &$message) {
            unset($message['tool_name']);
            if (isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as &$call) {
                    if (is_array($call['function']['arguments'] ?? null)) {
                        $call['function']['arguments'] = json_encode((object) $call['function']['arguments'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    }
                }
                unset($call);
            }
        }
        unset($message);
        $payload = [
            'model' => $options['model'] ?? config('ai.chat_model'),
            'messages' => $messages,
            'stream' => false,
            'temperature' => config('ai.temperature', 0.2),
            'max_tokens' => $options['max_tokens'] ?? (int) config('ai.max_output_tokens', 4096),
        ];
        if (isset($options['format'])) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'researchmate_result', 'schema' => $options['format'], 'strict' => true]];
        }
        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        $data = $this->send(self::ENDPOINT, $payload);
        $message = $data['choices'][0]['message'] ?? null;
        if (! is_array($message) || ($message['role'] ?? '') !== 'assistant') {
            throw new RuntimeException('Gateway Senopati mengembalikan pesan yang tidak valid.');
        }
        $calls = $message['tool_calls'] ?? [];
        if (! is_array($calls) || ! array_is_list($calls)) {
            throw new RuntimeException('Gateway Senopati mengembalikan daftar alat yang tidak valid.');
        }
        $result = ['role' => 'assistant', 'content' => $message['content'] ?? ''];
        if (! is_string($result['content']) || ($message['content'] ?? null) === null && $calls === []) {
            throw new RuntimeException('Gateway Senopati mengembalikan konten yang tidak valid.');
        }
        foreach ($calls as $call) {
            if (! is_array($call) || ! is_string($call['id'] ?? null) || $call['id'] === ''
                || ($call['type'] ?? '') !== 'function' || ! is_string($call['function']['name'] ?? null)
                || ! is_string($call['function']['arguments'] ?? null)) {
                throw new RuntimeException('Permintaan alat dari Senopati tidak valid.');
            }
            try {
                $arguments = json_decode($call['function']['arguments'], false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Argumen alat Senopati bukan JSON yang valid.');
            }
            if (! $arguments instanceof \stdClass) {
                throw new RuntimeException('Argumen alat Senopati harus berupa objek JSON.');
            }
            $call['function']['arguments'] = json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR);
            $result['tool_calls'][] = $call;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function structuredChat(array $messages, array $schema): array
    {
        $schemaPrompt = 'Kembalikan hanya JSON sesuai skema berikut: '.json_encode($schema, JSON_THROW_ON_ERROR);
        if (($messages[0]['role'] ?? null) === 'system') {
            $messages[0]['content'] .= "\n".$schemaPrompt;
        } else {
            array_unshift($messages, ['role' => 'system', 'content' => $schemaPrompt]);
        }
        $lastError = 'JSON tidak valid';
        for ($attempt = 0; $attempt <= max(0, min(5, (int) config('ai.max_retries', 2))); $attempt++) {
            $message = $this->chat($messages, ['format' => $schema]);
            try {
                $value = json_decode($message['content'], false, 512, JSON_THROW_ON_ERROR);
                $this->validator->validate($value, $schema);
                $decoded = json_decode($message['content'], true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Hasil terstruktur harus berupa objek JSON.');
                }

                return $decoded;
            } catch (JsonException|RuntimeException $exception) {
                $lastError = $exception->getMessage();
                $messages[] = ['role' => 'assistant', 'content' => $message['content']];
                $messages[] = ['role' => 'user', 'content' => 'JSON sebelumnya gagal validasi: '.$lastError.'. Perbaiki sesuai skema; jangan menambah fakta atau sumber.'];
            }
        }
        throw new RuntimeException('AI gagal menghasilkan JSON valid setelah batas percobaan: '.$lastError);
    }

    /** @return list<float> */
    public function generateEmbedding(string $text): array
    {
        return $this->generateEmbeddings([$text])[0];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        $data = $this->send(self::EMBEDDINGS_ENDPOINT, ['model' => config('ai.embed_model'), 'input' => $texts]);
        $items = $data['data'] ?? null;
        if (! is_array($items) || count($items) !== count($texts)) {
            throw new RuntimeException('Jumlah embedding Senopati tidak sesuai jumlah teks.');
        }
        $result = [];
        $dimensions = null;
        foreach ($items as $item) {
            $index = $item['index'] ?? null;
            $vector = $item['embedding'] ?? null;
            if (! is_int($index) || $index < 0 || $index >= count($texts) || isset($result[$index])
                || ! is_array($vector) || ! array_is_list($vector) || $vector === []) {
                throw new RuntimeException('Indeks atau vektor embedding tidak valid.');
            }
            $normalized = [];
            foreach ($vector as $number) {
                if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number)) {
                    throw new RuntimeException('Embedding mengandung angka tidak valid.');
                }
                $normalized[] = (float) $number;
            }
            $norm = array_sum(array_map(fn (float $n): float => $n * $n, $normalized));
            if ($norm === 0.0 || ! is_finite($norm)) {
                throw new RuntimeException('Norma vektor embedding tidak valid atau bernilai nol.');
            }
            $dimensions ??= count($normalized);
            if (count($normalized) !== $dimensions) {
                throw new RuntimeException('Dimensi embedding tidak konsisten dalam satu respons.');
            }
            $result[$index] = $normalized;
        }
        ksort($result);

        return array_values($result);
    }

    /** @return list<array<string, mixed>> */
    public function listModels(): array
    {
        $data = $this->send(self::MODELS_ENDPOINT);
        $models = $data['data'] ?? null;
        if (! is_array($models) || ! array_is_list($models)) {
            throw new RuntimeException('Daftar model Senopati tidak valid.');
        }
        foreach ($models as $model) {
            if (! is_array($model) || ! is_string($model['id'] ?? null)) {
                throw new RuntimeException('Identitas model Senopati tidak valid.');
            }
        }

        return $models;
    }

    /** @return array<string, mixed> */
    public function checkConnection(): array
    {
        try {
            $names = array_column($this->listModels(), 'id');
            $chat = in_array(config('ai.chat_model'), $names, true);
            $embed = in_array(config('ai.embed_model'), $names, true);
            if ($chat) {
                $this->chat([['role' => 'user', 'content' => 'Jawab hanya: siap']], ['max_tokens' => 16]);
            }

            return ['connected' => true, 'chat_available' => $chat, 'embedding_available' => $embed,
                'models' => $names, 'message' => $chat && $embed ? 'Gateway Senopati terhubung; model chat dan embedding tersedia.' : 'Gateway Senopati terhubung; model yang dikonfigurasi belum lengkap.'];
        } catch (RuntimeException $exception) {
            return ['connected' => false, 'chat_available' => false, 'embedding_available' => false, 'models' => [], 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(string $endpoint, ?array $payload = null): array
    {
        $key = (string) config('ai.senopati_key');
        if (trim($key) === '') {
            throw new RuntimeException('Token Senopati belum diatur pada SENOPATI_API_KEY.');
        }
        try {
            $request = Http::withToken($key)->acceptJson()->connectTimeout(10)
                ->timeout(max(1, min(1800, (int) config('ai.senopati_timeout', 300))))
                ->withOptions(['allow_redirects' => false]);
            $response = $payload === null ? $request->get($endpoint) : $request->post($endpoint, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Gateway Senopati tidak dapat dihubungi atau waktu tunggu habis. Periksa koneksi internet.');
        }
        if (! $response->successful()) {
            $hint = match ($response->status()) {
                401, 403 => 'Periksa token dan izin kelompok.',
                429 => 'Batas penggunaan tercapai; coba lagi nanti.',
                default => 'Periksa ketersediaan model dan gateway.',
            };
            throw new RuntimeException('Permintaan Senopati gagal (HTTP '.$response->status().'). '.$hint);
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Respons Senopati bukan JSON yang valid.');
        }

        return $data;
    }
}
