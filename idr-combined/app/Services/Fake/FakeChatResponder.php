<?php

namespace App\Services\Fake;

use App\Contracts\ChatResponder;
use App\Support\ChatReply;
use App\Support\RetrievedChunk;

/**
 * Stand-in for Member 2's Gemini chat (IDR_AI_DRIVER=fake).
 *
 * Produces a templated answer that names the retrieved documents, so the
 * frontend can build the chat UI and clickable source links for real.
 */
class FakeChatResponder implements ChatResponder
{
    public function respond(string $message, array $history, array $context): ChatReply
    {
        // Only cite chunks that plausibly matched (see FakeRetrievalService scoring).
        $relevant = array_values(array_filter($context, fn (RetrievedChunk $c) => $c->score >= 0.5));

        if ($relevant === []) {
            return new ChatReply(
                "[fake AI] I couldn't find anything in the repository about \"{$message}\". "
                .'Try rephrasing, or ask about a specific project title.',
            );
        }

        $sources = [];
        $titles = [];

        foreach ($relevant as $chunk) {
            if (! isset($sources[$chunk->artifactId])) {
                $sources[$chunk->artifactId] = true;
                $titles[] = "\"{$chunk->title}\"".($chunk->author ? " by {$chunk->author}" : '');
            }
        }

        $turn = intdiv(count($history), 2) + 1;

        return new ChatReply(
            content: "[fake AI · turn {$turn}] Based on ".implode(', ', array_slice($titles, 0, 3))
                .', here is a grounded answer to: "'.$message.'". '
                .'(Replace IDR_AI_DRIVER=fake with live once the Gemini pipeline is ready.)',
            sourcesReferenced: array_keys($sources),
        );
    }
}
