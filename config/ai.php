<?php

declare(strict_types=1);

return [
    'senopati_key' => env('SENOPATI_API_KEY'),
    'senopati_timeout' => (int) env('SENOPATI_TIMEOUT', 300),
    'chat_model' => env('SENOPATI_CHAT_MODEL', 'qwen3:14b'),
    'embed_model' => env('SENOPATI_EMBED_MODEL', 'embeddinggemma:latest'),
    'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    'max_papers' => (int) env('AI_MAX_PAPERS', 20),
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'max_pdf_mb' => (int) env('PDF_MAX_UPLOAD_MB', 20),
    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1200),
    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    'retrieval_limit' => (int) env('RAG_RETRIEVAL_LIMIT', 6),
    'max_tool_calls' => (int) env('AI_MAX_TOOL_CALLS', 40),
    'openalex_key' => env('OPENALEX_API_KEY'),
    'academic_timeout' => (int) env('ACADEMIC_API_TIMEOUT', 15),
    'max_document_chunks' => (int) env('AI_MAX_DOCUMENT_CHUNKS', 1000),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4096),
    'qdrant_url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
    'qdrant_api_key' => env('QDRANT_API_KEY'),
    'qdrant_collection' => env('QDRANT_COLLECTION', 'researchmate'),
    'qdrant_timeout' => (int) env('QDRANT_TIMEOUT', 15),
];
