<?php

namespace App\Services\Ai;

use App\Contracts\VectorIndex;

/**
 * Live VectorIndex: keeps Qdrant in step with MongoDB after a file is embedded.
 * Failures simply throw; App\Services\VectorSync retries them from the queue.
 */
class QdrantVectorIndex implements VectorIndex
{
    /** Keys the backend may overwrite. text / chunkIndex are never touched. */
    private const METADATA_KEYS = ['artifactId', 'title', 'author', 'course_code', 'academic_year', 'document_type', 'status', 'is_approved'];

    public function __construct(protected QdrantClient $qdrant) {}

    public function deleteArtifact(string $artifactId): void
    {
        $this->qdrant->deleteArtifact($artifactId);
    }

    public function updateArtifactMetadata(string $artifactId, array $metadata): void
    {
        $payload = array_intersect_key($metadata, array_flip(self::METADATA_KEYS));

        // is_approved must always agree with status; the student filter depends on it.
        if (isset($payload['status'])) {
            $payload['is_approved'] = $payload['status'] === 'approved';
        }

        $this->qdrant->setPayloadForArtifact($artifactId, $payload);
    }
}
