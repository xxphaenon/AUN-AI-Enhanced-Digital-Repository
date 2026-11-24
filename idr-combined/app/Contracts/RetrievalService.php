<?php

namespace App\Contracts;

use App\Support\RetrievedChunk;

/**
 * MEMBER 2 IMPLEMENTS THIS.
 *
 * Embed the natural-language query with Gemini and run a nearest-neighbour
 * search in Qdrant. Return raw chunk hits; the backend groups them by
 * document, joins MongoDB metadata and shapes the API response.
 *
 * Visibility: for normal (student/guest) searches, filter the Qdrant query
 * on the payload field `is_approved: true`. With $adminMode = true, drop that
 * filter so admins can search pending, approved and rejected files alike.
 * The backend also re-checks approval in MongoDB, so a stale payload can
 * never leak an unapproved file, but filtering in Qdrant keeps results full.
 */
interface RetrievalService
{
    /**
     * @param  string  $query  The user's natural-language query.
     * @param  int  $limit  Maximum number of chunks to return.
     * @param  list<string>  $artifactIds  Optional filter: only search inside these artifacts.
     * @param  bool  $adminMode  true = ignore the is_approved filter (admin searches only).
     * @return list<RetrievedChunk> Sorted by score, highest first.
     */
    public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array;
}
