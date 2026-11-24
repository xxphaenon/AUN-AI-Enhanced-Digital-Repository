<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

/**
 * Collection: chat_sessions — one document per conversation.
 *
 * Messages are embedded as an array (append-only via atomic $push), which
 * keeps "load session + history" to a single read. Each message:
 *
 *   {
 *     "role": "user" | "model",
 *     "content": "...",
 *     "timestamp": "2026-09-17T14:20:34Z",
 *     "sourcesReferenced": ["<artifactId>", ...]   // model messages only
 *   }
 *
 * "model" (not "assistant") matches Gemini's role naming and the contract.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $title
 * @property array $messages
 * @property int $message_count
 * @property Carbon|null $last_message_at
 */
class ChatSession extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_MODEL = 'model';

    protected $connection = 'mongodb';

    protected $table = 'chat_sessions';

    protected $fillable = [
        'user_id',
        'title',
        'messages',
        'message_count',
        'last_message_at',
    ];

    protected $attributes = [
        'messages' => [],
        'message_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'string',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwnedBy(string $userId): bool
    {
        return (string) $this->user_id === $userId;
    }

    /**
     * Build a message in the exact shape the contract returns.
     */
    public static function makeMessage(string $role, string $content, array $sourcesReferenced = [], ?Carbon $at = null): array
    {
        $message = [
            'role' => $role,
            'content' => $content,
            'timestamp' => ($at ?? now())->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($role === self::ROLE_MODEL) {
            $message['sourcesReferenced'] = array_values($sourcesReferenced);
        }

        return $message;
    }

    /**
     * Atomically append messages ($push) without rewriting the whole array,
     * so two quick requests on the same session can't clobber each other.
     */
    public function appendMessages(array ...$messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->push('messages', $messages);

        $this->increment('message_count', count($messages)); // atomic $inc

        $this->last_message_at = now();
        $this->save();
    }

    /**
     * The last N messages, oldest first — the conversational memory handed
     * to the ChatResponder.
     *
     * @return list<array{role: string, content: string, timestamp: string}>
     */
    public function recentHistory(int $limit): array
    {
        $messages = array_values((array) ($this->messages ?? []));

        return array_slice($messages, -$limit);
    }
}
