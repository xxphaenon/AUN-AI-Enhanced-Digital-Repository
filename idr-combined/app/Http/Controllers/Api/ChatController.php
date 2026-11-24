<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatMessageRequest;
use App\Models\Artifact;
use App\Models\ChatSession;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat endpoints. All require a logged-in user; sessions are private to
 * the user who started them.
 */
class ChatController extends Controller
{
    /**
     * POST /api/chat/message
     */
    public function message(ChatMessageRequest $request, ChatService $chat): JsonResponse
    {
        // Embedding + vector search + Gemini's answer can exceed PHP's default 30s.
        set_time_limit((int) config('idr.ai_request_seconds', 120));

        $user = $request->user();

        $claimed = $request->validated('userId');
        abort_if($claimed !== null && $claimed !== (string) $user->id, 403, 'userId must be your own user id.');

        $message = $request->string('message')->toString();
        $sessionId = $request->validated('sessionId');

        // Asked from a document page: answer from that document only.
        $document = null;
        if ($documentId = $request->validated('documentId')) {
            $document = Artifact::visibleToPublic()->find($documentId);
            abort_if($document === null, 404, 'The assistant can only answer about published documents.');
        }

        if ($sessionId) {
            $session = ChatSession::find($sessionId);

            abort_if($session === null, 404, 'Chat session not found.');
            abort_unless($session->isOwnedBy((string) $user->id), 403, 'This chat session belongs to another user.');
        } else {
            $session = $chat->startSession($user, $message);
        }

        return response()->json($chat->reply($session, $message, $document));
    }

    /**
     * GET /api/chat/sessions?page=&perPage=
     *
     * The caller's conversations, most recent first (for a sidebar).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ChatSession::where('user_id', (string) $request->user()->id)
            ->orderBy('updated_at', 'desc');

        return response()->json($this->paginated(
            $query,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 20),
            fn (ChatSession $s) => [
                'sessionId' => (string) $s->id,
                'title' => $s->title,
                'messageCount' => (int) $s->message_count,
                'lastMessageAt' => Artifact::iso($s->last_message_at),
            ],
        ));
    }

    /**
     * GET /api/chat/sessions/{sessionId}
     *
     * Reload a conversation (e.g. after a page refresh). Messages have the
     * same shape as the chat response's "message" object.
     */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::find($sessionId);

        abort_if($session === null, 404, 'Chat session not found.');
        abort_unless($session->isOwnedBy((string) $request->user()->id), 403, 'This chat session belongs to another user.');

        return response()->json([
            'status' => 'success',
            'data' => [
                'sessionId' => (string) $session->id,
                'title' => $session->title,
                'messages' => array_values((array) $session->messages),
            ],
        ]);
    }
}
