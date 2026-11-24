<?php

namespace Tests\Feature;

use App\Contracts\ChatResponder;
use App\Models\Artifact;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatReply;
use Tests\TestCase;

class ChatTest extends TestCase
{
    public function test_chat_requires_login(): void
    {
        $this->postJson('/api/chat/message', ['message' => 'hello'])->assertUnauthorized();
        $this->getJson('/api/chat/sessions')->assertUnauthorized();
    }

    public function test_first_message_creates_session_and_matches_contract(): void
    {
        $user = $this->actingAsStudent();
        $doc = Artifact::factory()->embedded()->create(['title' => 'Machine Learning Fundamentals', 'author' => 'Dr. Smith']);

        $response = $this->postJson('/api/chat/message', ['message' => 'Summarize the machine learning findings']);

        $response->assertOk()
            ->assertJsonStructure(['status', 'sessionId', 'message' => ['role', 'content', 'timestamp'], 'sourcesReferenced'])
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message.role', 'model')
            ->assertJsonPath('sourcesReferenced', [(string) $doc->id]);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('message.timestamp'));

        $session = ChatSession::find($response->json('sessionId'));
        $this->assertSame((string) $user->id, $session->user_id);
        $this->assertCount(2, $session->messages);
        $this->assertSame(['user', 'model'], array_column($session->messages, 'role'));
    }

    public function test_chat_never_cites_unapproved_files(): void
    {
        $this->actingAsStudent();
        Artifact::factory()->pending()->embedded()->create(['title' => 'Machine Learning Secrets']);

        $this->postJson('/api/chat/message', ['message' => 'machine learning secrets'])
            ->assertOk()
            ->assertJsonPath('sourcesReferenced', []);
    }

    public function test_user_id_in_body_must_match_the_token(): void
    {
        $this->actingAsStudent();
        $other = User::factory()->create();

        $this->postJson('/api/chat/message', ['userId' => (string) $other->id, 'message' => 'hi'])->assertForbidden();
    }

    public function test_follow_up_passes_history_to_responder(): void
    {
        $this->actingAsStudent();

        $responder = new class implements ChatResponder
        {
            public array $seenHistory = [];

            public function respond(string $message, array $history, array $context): ChatReply
            {
                $this->seenHistory = $history;

                return new ChatReply("echo: {$message}", ['66f0000000000000000000ff']); // not in context -> stripped
            }
        };
        $this->app->instance(ChatResponder::class, $responder);

        $sessionId = $this->postJson('/api/chat/message', ['message' => 'hello'])->json('sessionId');

        $this->postJson('/api/chat/message', ['sessionId' => $sessionId, 'message' => 'and then?'])
            ->assertOk()
            ->assertJsonPath('sessionId', $sessionId)
            ->assertJsonPath('message.content', 'echo: and then?')
            ->assertJsonPath('sourcesReferenced', []);

        $this->assertSame(['hello', 'echo: hello'], array_column($responder->seenHistory, 'content'));
        $this->assertCount(4, ChatSession::find($sessionId)->messages);
        $this->assertSame(4, ChatSession::find($sessionId)->message_count);
    }

    public function test_cannot_use_another_users_session(): void
    {
        $owner = User::factory()->create();
        $session = ChatSession::create(['user_id' => (string) $owner->id]);

        $this->actingAsStudent();

        $this->postJson('/api/chat/message', ['sessionId' => (string) $session->id, 'message' => 'hi'])->assertForbidden();
        $this->getJson("/api/chat/sessions/{$session->id}")->assertForbidden();
    }

    public function test_ai_failure_returns_502_and_saves_nothing(): void
    {
        $user = $this->actingAsStudent();
        $session = ChatSession::create(['user_id' => (string) $user->id]);

        $this->app->instance(ChatResponder::class, new class implements ChatResponder
        {
            public function respond(string $message, array $history, array $context): ChatReply
            {
                throw new \RuntimeException('Gemini 503');
            }
        });

        $this->postJson('/api/chat/message', ['sessionId' => (string) $session->id, 'message' => 'hi'])
            ->assertStatus(502)
            ->assertJsonPath('status', 'error');

        $this->assertCount(0, $session->refresh()->messages);
    }

    public function test_ai_failure_message_says_what_went_wrong(): void
    {
        $this->actingAsStudent();
        $fail = function (string $error): void {
            $this->app->instance(ChatResponder::class, new class($error) implements ChatResponder
            {
                public function __construct(private string $error) {}

                public function respond(string $message, array $history, array $context): ChatReply
                {
                    throw new \RuntimeException($this->error);
                }
            });
        };

        $fail('cURL error 28: Connection timed out after 10010 milliseconds for https://x.cloud.qdrant.io:6333/collections/idr_chunks/points/query');
        $this->postJson('/api/chat/message', ['message' => 'hi'])->assertStatus(502)
            ->assertJsonPath('message', "The AI assistant can't reach the document search service (Qdrant) right now. Please try again in a few minutes.");

        $fail('Gemini models/gemini-3.6-flash:generateContent failed (503): This model is currently experiencing high demand.');
        $this->postJson('/api/chat/message', ['message' => 'hi'])->assertStatus(502)
            ->assertJsonPath('message', 'The AI model (Gemini) is busy right now. Please try again in a minute.');

        $fail('GEMINI_API_KEY is not configured.');
        $this->postJson('/api/chat/message', ['message' => 'hi'])->assertStatus(502)
            ->assertJsonPath('message', 'The AI assistant is not set up correctly: an AI service key is missing or invalid. Please tell an administrator.');
    }

    public function test_session_history_and_session_list(): void
    {
        $this->actingAsStudent();
        $sessionId = $this->postJson('/api/chat/message', ['message' => 'hello'])->json('sessionId');

        $this->getJson("/api/chat/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'hello');

        $this->getJson('/api/chat/sessions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sessionId', $sessionId)
            ->assertJsonPath('data.0.messageCount', 2);
    }

    public function test_on_a_document_page_the_answer_comes_from_that_document_only(): void
    {
        $user = $this->actingAsStudent();
        $doc = Artifact::factory()->embedded()->create(['title' => 'Network Intrusion Detection', 'author' => 'Victor Nnadi']);
        Artifact::factory()->embedded()->create(['title' => 'Another project']);

        $retriever = new class implements \App\Contracts\RetrievalService
        {
            public array $ids = ['not called'];

            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                $this->ids = $artifactIds;

                return array_map(fn (string $id) => new \App\Support\RetrievedChunk($id, 'We used the CIC-IDS2017 dataset.', 0.8), $artifactIds);
            }
        };
        $responder = new class implements ChatResponder
        {
            public string $seen = '';

            public function respond(string $message, array $history, array $context): ChatReply
            {
                $this->seen = $message;

                return new ChatReply('It used CIC-IDS2017 [1].', array_map(fn ($c) => $c->artifactId, $context));
            }
        };
        $this->app->instance(\App\Contracts\RetrievalService::class, $retriever);
        $this->app->instance(ChatResponder::class, $responder);

        $response = $this->postJson('/api/chat/message', ['message' => 'What dataset did this project use?', 'documentId' => (string) $doc->id])
            ->assertOk()
            ->assertJsonPath('sourcesReferenced', [(string) $doc->id]);

        $this->assertSame([(string) $doc->id], $retriever->ids);
        $this->assertStringContainsString('"Network Intrusion Detection" by Victor Nnadi', $responder->seen);

        // The conversation stores what the student typed, not the hint.
        $session = ChatSession::find($response->json('sessionId'));
        $this->assertSame('What dataset did this project use?', $session->messages[0]['content']);
        $this->assertSame((string) $user->id, $session->user_id);
    }

    public function test_document_chat_is_only_for_published_documents(): void
    {
        $this->actingAsStudent();
        $pending = Artifact::factory()->pending()->embedded()->create();

        $this->postJson('/api/chat/message', ['message' => 'hi', 'documentId' => (string) $pending->id])->assertNotFound();
        $this->postJson('/api/chat/message', ['message' => 'hi', 'documentId' => 'not-an-id'])->assertUnprocessable();
    }
}
