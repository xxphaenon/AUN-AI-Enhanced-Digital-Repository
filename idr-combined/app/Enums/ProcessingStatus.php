<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded artifact, exactly as exposed by the API contract
 * (GET /api/documents/{artifactId}/status -> data.processingStatus).
 */
enum ProcessingStatus: string
{
    /** Saved to storage + MongoDB, waiting in (or running on) the ingestion queue. */
    case Pending = 'PENDING';

    /** Chunks embedded and stored in Qdrant; the document is searchable. */
    case Embedded = 'EMBEDDED';

    /** Ingestion gave up after all retries. See Artifact::$processing_error. */
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
