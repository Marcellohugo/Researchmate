<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class QdrantFixture
{
    public static function fake(): void
    {
        config(['ai.qdrant_url' => 'http://qdrant.test:6333', 'ai.qdrant_api_key' => 'test-qdrant-key', 'ai.qdrant_collection' => 'researchmate']);
        Http::fake(['http://qdrant.test:6333/*' => function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/collections')) {
                return Http::response(['status' => 'ok', 'result' => ['collections' => []]]);
            }
            if ($request->method() === 'GET') {
                preg_match('/_(\d+)$/', $request->url(), $matches);

                return Http::response(['status' => 'ok', 'result' => ['config' => ['params' => ['vectors' => ['size' => (int) ($matches[1] ?? 3), 'distance' => 'Cosine']]]]]);
            }
            if (str_ends_with($request->url(), '/points/query')) {
                $ids = $request['filter']['must'][3]['has_id'];

                return Http::response(['status' => 'ok', 'result' => ['points' => array_map(fn ($id) => ['id' => $id, 'score' => 1.0], array_slice($ids, 0, $request['limit']))]]);
            }

            return Http::response(['status' => 'ok', 'result' => ['status' => 'completed']]);
        }]);
    }
}
