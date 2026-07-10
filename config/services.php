<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'upstash_vector' => [
        'url' => env('UPSTASH_VECTOR_REST_URL'),
        'token' => env('UPSTASH_VECTOR_REST_TOKEN'),
        'dimension' => 768, // nomic-embed-text v1.5 (ADR-0025); was 1536 for Gemini embedding-001
        'similarity_threshold' => env('UPSTASH_VECTOR_THRESHOLD', 0.90),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-8b-instruct:free'),
        'url' => 'https://openrouter.ai/api/v1/chat/completions',
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'embedding_url' => env(
            'GEMINI_EMBEDDING_URL',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent'
        ),
        'flash_url' => env(
            'GEMINI_FLASH_URL',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'
        ),
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        'timeout' => env('OLLAMA_EMBEDDING_TIMEOUT', 10),
        'chat_model' => env('OLLAMA_CHAT_MODEL', '32qwen3.5:latest'),
        'chat_timeout' => env('OLLAMA_CHAT_TIMEOUT', 60),
    ],

    'ledger_l5' => [
        // Shared secret Ledger-L5 presents via the X-Ledger-Api-Key header
        // on GET /usage (ADR-0029). First-class secret — never logged.
        'api_key' => env('LEDGER_L5_API_KEY'),
    ],

];
