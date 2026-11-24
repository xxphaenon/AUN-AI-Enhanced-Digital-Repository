<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Trusted certificate authorities for HTTPS calls to Gemini and Qdrant.
    | PHP on Windows often has none configured ("cURL error 60"), so the project
    | ships Mozilla's list (resources/certs/cacert.pem, from the certifi project).
    | IDR_CA_BUNDLE=/path/to/file uses another bundle; IDR_CA_BUNDLE=system uses
    | PHP's own curl.cainfo / openssl.cafile settings instead.
    */
    'ca_bundle' => env('IDR_CA_BUNDLE', resource_path('certs/cacert.pem')),

    // AI pipeline (Gemini + Qdrant). Used when IDR_AI_DRIVER=live.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // Must be the same model for indexing and querying. gemini-embedding-001 → 3072 dims.
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 3072),
        // Texts per batchEmbedContents call (Gemini allows up to 100).
        'embedding_batch_size' => (int) env('GEMINI_EMBEDDING_BATCH_SIZE', 50),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.8-flash'),
        // How long one Gemini call may take. Kept well under the request time limit
        // (idr.ai_request_seconds) so a slow answer fails cleanly instead of crashing.
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        // Gemini 3.x "thinks" before answering (default "medium" on 3.8 Flash). "low"
        // is much faster and plenty for answering from a few excerpts. Empty = model default.
        'chat_thinking_level' => env('GEMINI_CHAT_THINKING_LEVEL', 'low'),
        // Tried in order when the chat model is overloaded ("high demand" 503), rate
        // limited, timed out or retired. Comma-separated; empty = no fallback.
        'chat_fallback_models' => env('GEMINI_CHAT_FALLBACK_MODELS', 'gemini-3.6-flash,gemini-3.5-flash-lite'),
        // Total seconds one chat answer may spend across the model and its fallbacks.
        'chat_budget_seconds' => (int) env('GEMINI_CHAT_BUDGET_SECONDS', 60),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
        'key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'idr_chunks'),
        // Name of the vector inside each point (the pipeline has always used "content").
        'vector_name' => env('QDRANT_VECTOR_NAME', 'content'),
        'timeout' => (int) env('QDRANT_TIMEOUT', 15),
    ],

    // Text chunking for ingestion (words per chunk, words shared between neighbours).
    'chunking' => [
        'chunk_words' => (int) env('IDR_CHUNK_WORDS', 500),
        'overlap_words' => (int) env('IDR_CHUNK_OVERLAP_WORDS', 100),
        // PHP memory allowed while reading one PDF (large scans need more than 128M).
        'pdf_memory_limit' => env('IDR_PDF_MEMORY_LIMIT', '1024M'),
    ],

];
