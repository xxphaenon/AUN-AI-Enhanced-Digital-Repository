<?php

namespace App\Contracts;

/**
 * MEMBER 2 IMPLEMENTS THIS.
 *
 * Keeps Qdrant in step with MongoDB after a file has been embedded. Every
 * point (chunk) of a file carries the same payload, so both operations act on
 * all points whose payload `artifactId` equals the given id.
 *
 * The backend calls these synchronously and, if one throws, retries it from
 * the queue (App\Jobs\SyncArtifactVectorMetadata / DeleteArtifactVectors), so
 * implementations should simply throw on failure and be safe to repeat.
 */
interface VectorIndex
{
    /**
     * Permanently delete every point that belongs to this file.
     * Called when an admin deletes the file. Deleting a file that has no
     * points (never embedded, or already deleted) must not throw.
     */
    public function deleteArtifact(string $artifactId): void;

    /**
     * Overwrite payload fields on every point of this file WITHOUT
     * re-embedding (Qdrant "set payload" with a filter on artifactId).
     * Called after an admin approves/rejects a file or edits its details.
     * $metadata is exactly Artifact::vectorMetadata(); store these keys as-is.
     *
     * @param  array{artifactId: string, title: string, author: ?string, course_code: ?string, academic_year: ?string, document_type: ?string, status: string, is_approved: bool}  $metadata
     */
    public function updateArtifactMetadata(string $artifactId, array $metadata): void;
}
