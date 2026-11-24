<?php

namespace App\Support;

/**
 * What the ChatResponder (Member 2, Gemini) hands back to the backend.
 */
final readonly class ChatReply
{
    /**
     * @param  string  $content  The model's answer text.
     * @param  list<string>  $sourcesReferenced  Artifact ids the answer actually drew on.
     */
    public function __construct(
        public string $content,
        public array $sourcesReferenced = [],
    ) {}
}
