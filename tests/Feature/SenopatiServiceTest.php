<?php

namespace Tests\Feature;

use App\Agents\DiscoveryAgent;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Models\User;
use App\Services\AI\AgentContext;
use App\Services\AI\SenopatiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SenopatiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.chat_model' => 'qwen3:14b', 'ai.senopati_key' => 'test-secret', 'ai.max_retries' => 1]);
        Http::preventStrayRequests();
    }

    public function test_gateway_authentication_and_schema_repair_use_remote_embedding(): void
    {
        Http::fake([
            SenopatiService::ENDPOINT => Http::sequence()
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"status":42}']]]])
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"status":"siap"}']]]]),
            SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(['data' => [['index' => 0, 'embedding' => [1, 2, 3]]]]),
        ]);
        $schema = ['type' => 'object', 'required' => ['status'], 'additionalProperties' => false, 'properties' => ['status' => ['type' => 'string']]];
        $ai = app(SenopatiService::class);
        $this->assertSame(['status' => 'siap'], $ai->structuredChat([['role' => 'user', 'content' => 'Siap?']], $schema));
        $this->assertSame([1.0, 2.0, 3.0], $ai->generateEmbedding('Sumber lokal'));
        Http::assertSent(fn (Request $request) => $request->url() === SenopatiService::ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer test-secret') && $request['model'] === 'qwen3:14b'
            && $request['stream'] === false && $request['response_format']['json_schema']['schema'] === $schema);
        Http::assertSent(fn (Request $request) => $request->url() === SenopatiService::EMBEDDINGS_ENDPOINT && $request->hasHeader('Authorization', 'Bearer test-secret') && $request['model'] === config('ai.embed_model'));
        Http::assertSentCount(3);
    }

    public function test_discovery_round_trip_preserves_tool_id_and_serializes_arguments(): void
    {
        $project = ResearchProject::factory()->create(['research_mode' => 'offline']);
        $run = ResearchRun::query()->create(['project_id' => $project->id, 'status' => 'discovering', 'workflow_state' => []]);
        Http::fake([SenopatiService::ENDPOINT => Http::sequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_project_documents', 'arguments' => '{"query":"AI mahasiswa"}']],
            ]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"discovered_paper_ids":[],"ranking_notes":"Tidak ada sumber.","missing_metadata":[]}']]]]),
        ]);
        $result = app(DiscoveryAgent::class)->execute(new AgentContext($project, $run, []));
        $this->assertSame([], $result->output['discovered_paper_ids']);
        $this->assertSame(1, $run->fresh()->workflow_state['tool_call_count']);
        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];
            $tool = collect($messages)->firstWhere('role', 'tool');
            $assistant = collect($messages)->firstWhere('role', 'assistant');

            return $tool !== null && $tool['tool_call_id'] === 'call_1' && ! isset($tool['tool_name'])
                && $assistant['tool_calls'][0]['function']['arguments'] === '{"query":"AI mahasiswa"}';
        });
        Http::assertSentCount(2);
    }

    public function test_missing_token_rejects_request_without_contacting_gateway(): void
    {
        config(['ai.senopati_key' => '']);
        try {
            app(SenopatiService::class)->chat([]);
            $this->fail('Token kosong harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SENOPATI_API_KEY', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('gatewayFailures')]
    public function test_gateway_errors_are_safe_and_do_not_redirect_or_retry(int $status): void
    {
        Http::fake([SenopatiService::ENDPOINT => Http::response(['error' => 'test-secret'], $status, ['Location' => 'https://other.example'])]);
        try {
            app(SenopatiService::class)->chat([]);
            $this->fail('Kegagalan gateway harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP '.$status, $exception->getMessage());
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function gatewayFailures(): array
    {
        return [[401], [429], [302], [500]];
    }

    public function test_embeddings_restore_input_order_and_reject_invalid_vectors(): void
    {
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::sequence()
            ->push(['data' => [['index' => 1, 'embedding' => [4, 5]], ['index' => 0, 'embedding' => [1, 2]]]])
            ->push(['data' => [['index' => 0, 'embedding' => [0, 0]]]]),
        ]);
        $this->assertSame([[1.0, 2.0], [4.0, 5.0]], app(SenopatiService::class)->generateEmbeddings(['a', 'b']));
        $this->expectExceptionMessage('bernilai nol');
        app(SenopatiService::class)->generateEmbedding('a');
    }

    #[DataProvider('invalidEmbeddings')]
    public function test_invalid_embedding_response_is_rejected(array $data): void
    {
        Http::fake([SenopatiService::EMBEDDINGS_ENDPOINT => Http::response(['data' => $data])]);
        $this->expectException(RuntimeException::class);
        app(SenopatiService::class)->generateEmbeddings(['a', 'b']);
    }

    public static function invalidEmbeddings(): array
    {
        return [
            [[['index' => 0, 'embedding' => [1, 2]]]],
            [[['index' => 0, 'embedding' => [1]], ['index' => 0, 'embedding' => [2]]]],
            [[['index' => 0, 'embedding' => [1]], ['index' => 1, 'embedding' => [1, 2]]]],
            [[['index' => 0, 'embedding' => ['invalid']], ['index' => 1, 'embedding' => [1]]]],
        ];
    }

    public function test_schema_retry_limit_and_gateway_connection_failure_are_reported(): void
    {
        Http::fake([SenopatiService::ENDPOINT => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'invalid']]]])]);
        try {
            app(SenopatiService::class)->structuredChat([], ['type' => 'object']);
            $this->fail('JSON tidak valid harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('batas percobaan', $exception->getMessage());
        }
        Http::assertSentCount(2);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([SenopatiService::MODELS_ENDPOINT => Http::failedConnection()]);
        $status = app(SenopatiService::class)->checkConnection();
        $this->assertFalse($status['connected']);
        $this->assertStringContainsString('waktu tunggu', $status['message']);
    }

    public function test_malformed_tool_arguments_are_rejected_before_execution(): void
    {
        Http::fake([SenopatiService::ENDPOINT => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_project_documents', 'arguments' => '[]']],
        ]]]]])]);
        $this->expectExceptionMessage('harus berupa objek JSON');
        app(SenopatiService::class)->chat([]);
    }

    public function test_health_checks_gateway_models_and_settings_hide_token(): void
    {
        Http::fake([
            SenopatiService::MODELS_ENDPOINT => Http::response(['data' => [['id' => config('ai.embed_model')], ['id' => config('ai.chat_model')]]]),
            SenopatiService::ENDPOINT => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'siap']]]]),
        ]);
        $this->actingAs(User::factory()->create())->post(route('ai.check'))->assertRedirect();
        $this->get(route('ai.settings'))->assertOk()->assertSee('Gateway Senopati terhubung')
            ->assertSee('qwen3:14b')->assertSee('cuplikan teks PDF')->assertDontSee('test-secret');
        Http::assertSentCount(2);
    }
}
