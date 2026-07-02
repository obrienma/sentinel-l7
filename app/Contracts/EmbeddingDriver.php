<?php

namespace App\Contracts;

interface EmbeddingDriver
{
    public const TASK_DOCUMENT = 'search_document';

    public const TASK_QUERY = 'search_query';

    /**
     * Embed text into a vector.
     *
     * @param  string  $task  EmbeddingDriver::TASK_DOCUMENT for indexed/cached content,
     *                        EmbeddingDriver::TASK_QUERY for text used to search against
     *                        already-indexed content. Drivers that don't distinguish
     *                        (e.g. Gemini) may ignore this.
     * @return array<int, float>
     */
    public function embed(string $text, string $task = self::TASK_DOCUMENT): array;
}
