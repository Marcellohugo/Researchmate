<?php

namespace Tests\Support;

class GatewayFixture
{
    public static function chat(string $content): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    public static function embeddings(array $vectors): array
    {
        return ['data' => array_map(fn ($vector, $index) => ['index' => $index, 'embedding' => $vector], $vectors, array_keys($vectors))];
    }
}
