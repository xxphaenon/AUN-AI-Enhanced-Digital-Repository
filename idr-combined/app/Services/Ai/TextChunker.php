<?php

namespace App\Services\Ai;

/**
 * Splits text into overlapping word windows (the pipeline's original scheme:
 * 500 words per chunk, 100 words shared with the previous chunk).
 */
class TextChunker
{
    public function __construct(
        protected int $chunkWords = 500,
        protected int $overlapWords = 100,
    ) {
        if ($this->chunkWords < 1 || $this->overlapWords < 0 || $this->overlapWords >= $this->chunkWords) {
            throw new \InvalidArgumentException('Chunk size must be positive and larger than the overlap.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('services.chunking.chunk_words', 500),
            (int) config('services.chunking.overlap_words', 100),
        );
    }

    /** @return list<string> */
    public function chunk(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return [];
        }

        $words = explode(' ', $text);
        $total = count($words);
        $step = $this->chunkWords - $this->overlapWords;
        $chunks = [];

        for ($i = 0; $i < $total; $i += $step) {
            $chunks[] = implode(' ', array_slice($words, $i, $this->chunkWords));

            if ($i + $this->chunkWords >= $total) {
                break; // the last window already reached the end; avoid a tail that is pure overlap
            }
        }

        return $chunks;
    }
}
