<?php

namespace App\Http\Requests;

use App\Rules\ObjectId;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/chat/message  (logged in)
 *   { "sessionId"?: "...", "message": "...", "userId"?: "...", "documentId"?: "..." }
 *
 * The user is whoever owns the bearer token. `userId` is still accepted for
 * compatibility with the original contract but must be the caller's own id.
 * sessionId may be null/omitted to start a new conversation; the response
 * returns the new sessionId, which the frontend sends on later turns.
 *
 * documentId (sent by the assistant panel on a document page) limits the
 * answer to that one document, so "this project" means the one on screen.
 */
class ChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'sessionId' => ['nullable', 'string', new ObjectId],
            'userId' => ['nullable', 'string', new ObjectId],
            'message' => ['required', 'string', 'max:'.config('idr.chat.max_message_length')],
            'documentId' => ['nullable', 'string', new ObjectId],
        ];
    }
}
