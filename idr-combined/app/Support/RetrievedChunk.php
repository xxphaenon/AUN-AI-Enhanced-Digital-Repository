<?php

namespace App\Support;

/**
 * One text chunk returned by the vector search (Qdrant nearest-neighbour hit).
 *
 * Member 2's RetrievalService returns these; Member 3's SearchService and
 * ChatService group them by artifact and join them to MongoDB metadata.
 */
final readonly class RetrievedChunk
{
    public function __construct(
        /** MongoDB artifact id (string), stored in the Qdrant point payload. */
        public string $artifactId,
        /** The chunk's text, used as a search snippet and as chat context. */
        public string $text,
        /** Similarity score, higher = more relevant (cosine similarity, 0..1). */
        public float $score,
        /** Position of the chunk inside the document, if known. */
        public ?int $chunkIndex = null,
        /** Filled in by the backend from MongoDB before the chunk reaches the ChatResponder. */
        public ?string $title = null,
        public ?string $author = null,
    ) {}

    public function withMetadata(?string $title, ?string $author): self
    {
        return new self($this->artifactId, $this->text, $this->score, $this->chunkIndex, $title, $author);
    }
}
