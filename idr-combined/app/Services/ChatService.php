<?php

namespace App\Services;

use App\Contracts\ChatResponder;
use App\Contracts\RetrievalService;
use App\Exceptions\AiServiceUnavailableException;
use App\Models\Artifact;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\RetrievedChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backs POST /api/chat/message.
 *
 *   1. load (or start) the user's ChatSession from MongoDB
 *   2. retrieve grounding chunks (Member 2's RetrievalService)
 *   3. ask the model with history + context (Member 2's ChatResponder)
 *   4. atomically append the user + model messages to the session
 *   5. return the contract payload
 */
class ChatService
{
    public function __construct(
        private readonly RetrievalService $retrieval,
        private readonly ChatResponder $responder,
    ) {}

    public function startSession(User $user, string $firstMessage): ChatSession
    {
        return ChatSession::create([
            'user_id' => (string) $user->id,
            'title' => Str::limit($firstMessage, 60),
        ]);
    }

    /**
     * @return array{status: string, sessionId: string, message: array, sourcesReferenced: list<string>}
     *
     * @throws AiServiceUnavailableException
     */
    public function reply(ChatSession $session, string $message, ?Artifact $document = null): array
    {
        $receivedAt = now();
        $history = $session->recentHistory((int) config('idr.chat.history_window', 20));

        try {
            $context = $this->groundingContext($message, $document);
            $reply = $this->responder->respond($this->withDocumentHint($message, $document), $history, $context);
        } catch (Throwable $e) {
            Log::error('Chat AI call failed.', ['sessionId' => (string) $session->id, 'error' => $e->getMessage()]);

            throw AiServiceUnavailableException::forFailure($e, 'The AI assistant');
        }

        // Only allow citations of documents that were actually in the context,
        // so the UI never renders a link to a hallucinated/nonexistent id.
        $contextIds = array_unique(array_map(fn (RetrievedChunk $c) => $c->artifactId, $context));
        $sources = array_values(array_intersect(array_unique($reply->sourcesReferenced), $contextIds));

        $userMessage = ChatSession::makeMessage(ChatSession::ROLE_USER, $message, at: $receivedAt);
        $modelMessage = ChatSession::makeMessage(ChatSession::ROLE_MODEL, $reply->content, $sources);

        $session->appendMessages($userMessage, $modelMessage);

        return [
            'status' => 'success',
            'sessionId' => (string) $session->id,
            'message' => [
                'role' => $modelMessage['role'],
                'content' => $modelMessage['content'],
                'timestamp' => $modelMessage['timestamp'],
            ],
            'sourcesReferenced' => $sources,
        ];
    }

    /**
     * Tell the model which document is on screen, so "this project" or "the
     * author" needs no title. Only the model sees this; the saved message is
     * what the student typed.
     */
    private function withDocumentHint(string $message, ?Artifact $document): string
    {
        if ($document === null) {
            return $message;
        }

        $about = '"'.$document->title.'"'.($document->author ? ' by '.$document->author : '');

        return $message."\n\n(The student is reading {$about}. Questions about \"this project\", \"this document\" or \"the author\" refer to it.)";
    }

    /**
     * Retrieve chunks and attach title/author from MongoDB so the model can
     * say "Based on Dr. Smith's paper…". Chunks for missing, non-embedded or
     * unapproved artifacts are dropped: chat only ever cites approved files.
     *
     * @return list<RetrievedChunk>
     */
    private function groundingContext(string $message, ?Artifact $document = null): array
    {
        $chunks = $document === null
            ? $this->retrieval->retrieve($message, (int) config('idr.chat.context_chunks', 6))
            : $this->retrieval->retrieve($message, (int) config('idr.chat.document_context_chunks', 8), [(string) $document->id]);

        if ($chunks === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (RetrievedChunk $c) => $c->artifactId, $chunks)));

        $artifacts = Artifact::visibleToPublic()
            ->whereIn('_id', $ids)
            ->get(['_id', 'title', 'author'])
            ->keyBy(fn (Artifact $a) => (string) $a->id);

        $context = [];

        foreach ($chunks as $chunk) {
            if ($artifact = $artifacts->get($chunk->artifactId)) {
                $context[] = $chunk->withMetadata($artifact->title, $artifact->author);
            }
        }

        return $context;
    }
}
