<?php

namespace App\Services\Fake;

use App\Contracts\VectorIndex;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in for Member 2's Qdrant sync functions (IDR_AI_DRIVER=fake).
 *
 * There is no vector store in fake mode, so nothing needs deleting or
 * updating: it only logs, and records each call so tests can assert that the
 * backend triggered the right operation. Bound as a singleton.
 */
class FakeVectorIndex implements VectorIndex
{
    /** @var list<array{0: string, 1: string, 2?: array}> */
    public array $calls = [];

    public function deleteArtifact(string $artifactId): void
    {
        $this->calls[] = ['delete', $artifactId];
        Log::info('[fake vector index] deleteArtifact', ['artifactId' => $artifactId]);
    }

    public function updateArtifactMetadata(string $artifactId, array $metadata): void
    {
        $this->calls[] = ['update', $artifactId, $metadata];
        Log::info('[fake vector index] updateArtifactMetadata', ['artifactId' => $artifactId, 'metadata' => $metadata]);
    }
}
