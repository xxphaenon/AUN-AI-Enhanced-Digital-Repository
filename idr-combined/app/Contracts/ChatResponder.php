<?php

namespace App\Contracts;

use App\Support\ChatReply;
use App\Support\RetrievedChunk;

/**
 * MEMBER 2 IMPLEMENTS THIS.
 *
 * Build the grounded system prompt from $context, include the conversation
 * $history, call Gemini, and return the answer plus the artifact ids it used.
 * The backend loads/saves history in MongoDB; this class never touches the DB.
 */
interface ChatResponder
{
    /**
     * $history holds the previous turns, oldest first; role is "user" or
     * "model" (Gemini naming). $context holds the retrieved chunks, with
     * title/author filled in; only APPROVED files are ever included.
     *
     * @param  string  $message  The new user message.
     * @param  list<array{role: string, content: string, timestamp: string}>  $history
     * @param  list<RetrievedChunk>  $context
     *
     * @throws \Throwable if the model call fails (the API returns 502 and nothing is saved).
     */
    public function respond(string $message, array $history, array $context): ChatReply;
}
