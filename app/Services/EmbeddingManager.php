<?php

namespace App\Services;

use App\Services\Embedding\GeminiEmbeddingDriver;
use App\Services\Embedding\OllamaEmbeddingDriver;
use Illuminate\Support\Manager;

class EmbeddingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('sentinel.embedding_driver', 'gemini');
    }

    protected function createGeminiDriver(): GeminiEmbeddingDriver
    {
        return $this->container->make(GeminiEmbeddingDriver::class);
    }

    protected function createOllamaDriver(): OllamaEmbeddingDriver
    {
        return $this->container->make(OllamaEmbeddingDriver::class);
    }
}
