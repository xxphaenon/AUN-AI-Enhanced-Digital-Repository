<?php

namespace Tests\Feature;

use App\Contracts\RetrievalService;
use App\Models\Artifact;
use App\Support\RetrievedChunk;
use Tests\TestCase;

class SearchTest extends TestCase
{
    /**
     * A retriever that returns the given chunks regardless of the query,
     * and records whether admin mode was requested.
     */
    private function retrieverReturning(array $chunks): object
    {
        $retriever = new class($chunks) implements RetrievalService
        {
            public ?bool $adminMode = null;

            public function __construct(private array $chunks) {}

            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                $this->adminMode = $adminMode;

                return $this->chunks;
            }
        };

        $this->app->instance(RetrievalService::class, $retriever);

        return $retriever;
    }

    public function test_search_groups_chunks_by_document_and_matches_contract(): void
    {
        $ml = Artifact::factory()->embedded()->create(['title' => 'Machine Learning Fundamentals', 'course_code' => 'CSC 401']);
        $notEmbedded = Artifact::factory()->create(['title' => 'Still processing']);

        $retriever = $this->retrieverReturning([
            new RetrievedChunk((string) $ml->id, 'Neural networks operate...', 0.89),
            new RetrievedChunk((string) $notEmbedded->id, 'should be hidden', 0.95),
            new RetrievedChunk((string) $ml->id, 'Backpropagation adjusts weights...', 0.81),
            new RetrievedChunk('66f0000000000000000000ff', 'deleted doc', 0.9),
        ]);

        $this->getJson('/api/search?q=how+does+neural+networking+work&limit=5')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('query', 'how does neural networking work')
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.artifactId', (string) $ml->id)
            ->assertJsonPath('results.0.title', 'Machine Learning Fundamentals')
            ->assertJsonPath('results.0.courseCode', 'CSC 401')
            ->assertJsonPath('results.0.relevanceScore', 0.89)
            ->assertJsonPath('results.0.relevantSnippets', ['Neural networks operate...', 'Backpropagation adjusts weights...'])
            ->assertJsonMissingPath('results.0.reviewStatus');

        $this->assertFalse($retriever->adminMode);
    }

    public function test_public_search_never_returns_unapproved_files_even_if_qdrant_does(): void
    {
        $approved = Artifact::factory()->embedded()->create();
        $pending = Artifact::factory()->pending()->embedded()->create();
        $rejected = Artifact::factory()->rejected()->embedded()->create();

        // Simulates a stale is_approved payload in Qdrant.
        $this->retrieverReturning([
            new RetrievedChunk((string) $pending->id, 'pending text', 0.99),
            new RetrievedChunk((string) $rejected->id, 'rejected text', 0.98),
            new RetrievedChunk((string) $approved->id, 'approved text', 0.7),
        ]);

        $response = $this->getJson('/api/search?q=anything')->assertOk();

        $this->assertSame([(string) $approved->id], array_column($response->json('results'), 'artifactId'));
    }

    public function test_admin_search_uses_admin_mode_and_includes_every_status(): void
    {
        $approved = Artifact::factory()->embedded()->create();
        $pending = Artifact::factory()->pending()->embedded()->create();

        $retriever = $this->retrieverReturning([
            new RetrievedChunk((string) $pending->id, 'pending text', 0.99),
            new RetrievedChunk((string) $approved->id, 'approved text', 0.7),
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/search?q=anything')->assertOk();

        $this->assertTrue($retriever->adminMode);
        $this->assertSame(['pending', 'approved'], array_column($response->json('results'), 'reviewStatus'));
    }

    public function test_fake_retriever_respects_admin_mode(): void
    {
        Artifact::factory()->embedded()->create(['title' => 'Compilers Approved']);
        Artifact::factory()->pending()->embedded()->create(['title' => 'Compilers Pending']);

        $titles = fn ($url) => array_column($this->getJson($url)->assertOk()->json('results'), 'title');

        $this->assertSame(['Compilers Approved'], $titles('/api/search?q=compilers'));

        $this->actingAsAdmin();
        $this->assertEqualsCanonicalizing(['Compilers Approved', 'Compilers Pending'], $titles('/api/admin/search?q=compilers'));
    }

    public function test_query_is_required(): void
    {
        $this->getJson('/api/search')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/search?q=ai&limit=500')->assertStatus(422)->assertJsonValidationErrors(['limit']);
    }

    public function test_retrieval_failure_returns_502(): void
    {
        $this->app->instance(RetrievalService::class, new class implements RetrievalService
        {
            public function retrieve(string $query, int $limit, array $artifactIds = [], bool $adminMode = false): array
            {
                throw new \RuntimeException('Qdrant down');
            }
        });

        $this->getJson('/api/search?q=neural')->assertStatus(502)->assertJsonPath('status', 'error');
    }
}
