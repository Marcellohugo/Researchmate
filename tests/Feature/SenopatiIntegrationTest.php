<?php

namespace Tests\Feature;

use App\Services\AI\SenopatiService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('senopati')]
class SenopatiIntegrationTest extends TestCase
{
    public function test_real_gateway_models_generate_embedding_and_schema_valid_json(): void
    {
        if (getenv('RUN_SENOPATI_TESTS') !== '1') {
            $this->markTestSkipped('Aktifkan RUN_SENOPATI_TESTS=1 untuk menguji gateway Senopati yang sebenarnya.');
        }
        $ai = app(SenopatiService::class);
        $status = $ai->checkConnection();
        $this->assertTrue($status['connected'], $status['message']);
        $this->assertTrue($status['chat_available'], 'Model chat gateway belum tersedia.');
        $this->assertTrue($status['embedding_available'], 'Model embedding gateway belum tersedia.');
        $embedding = $ai->generateEmbedding('Penelitian menggunakan bukti dokumen.');
        $this->assertNotEmpty($embedding);
        $this->assertIsFloat($embedding[0]);
        $result = $ai->structuredChat([['role' => 'user', 'content' => 'Kembalikan objek JSON dengan status siap dan angka 7.']], [
            'type' => 'object', 'required' => ['status', 'number'], 'additionalProperties' => false,
            'properties' => ['status' => ['type' => 'string', 'enum' => ['siap']], 'number' => ['type' => 'integer', 'enum' => [7]]],
        ]);
        $this->assertSame('siap', $result['status']);
        $this->assertSame(7, $result['number']);
    }
}
