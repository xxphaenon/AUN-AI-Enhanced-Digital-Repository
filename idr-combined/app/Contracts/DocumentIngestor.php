<?php

namespace App\Contracts;

use App\Models\Artifact;

/**
 * MEMBER 2 IMPLEMENTS THIS.
 *
 * Turns one stored file into searchable vectors:
 *   extract PDF text -> split into overlapping chunks -> embed with Gemini
 *   -> upsert into Qdrant.
 *
 * Every point's payload must contain $artifact->vectorMetadata() (which
 * includes artifactId, title, course_code, academic_year, status and
 * is_approved) plus "chunkIndex" and "text". Pending and rejected files are
 * embedded too, so admins can search them; student searches filter on
 * is_approved.
 *
 * The queued job (App\Jobs\ProcessArtifactIngestion, owned by Member 3) wraps
 * this call and takes care of statuses, retries and failure recording. After
 * a successful ingest it also re-sends the CURRENT metadata through
 * VectorIndex::updateArtifactMetadata(), in case an admin approved or edited
 * the file while it was being embedded. So the implementation only needs to:
 *   - return the number of chunks embedded, or
 *   - throw an exception (the job will retry, then mark the artifact FAILED).
 *
 * Get the file bytes with $artifact->fileContents() (works for local and s3).
 * Implementations must be idempotent: a retry may re-run the same artifact,
 * so delete/overwrite existing Qdrant points for that artifactId first.
 */
interface DocumentIngestor
{
    /**
     * @return int Number of chunks embedded and stored.
     *
     * @throws \Throwable on any failure.
     */
    public function ingest(Artifact $artifact): int;
}
