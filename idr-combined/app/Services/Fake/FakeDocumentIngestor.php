<?php

namespace App\Services\Fake;

use App\Contracts\DocumentIngestor;
use App\Models\Artifact;

/**
 * Stand-in for Member 2's Gemini/Qdrant ingestion (IDR_AI_DRIVER=fake).
 *
 * Reads the file (so a missing file still fails realistically) and reports
 * a plausible chunk count (~1 chunk per 2 KB) without calling any AI.
 */
class FakeDocumentIngestor implements DocumentIngestor
{
    public function ingest(Artifact $artifact): int
    {
        $bytes = strlen($artifact->fileContents());

        return max(1, (int) ceil($bytes / 2048));
    }
}
