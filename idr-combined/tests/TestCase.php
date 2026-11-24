<?php

namespace Tests;

use App\Contracts\VectorIndex;
use App\Models\Artifact;
use App\Models\User;
use App\Services\Fake\FakeVectorIndex;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Collections wiped before every test (MongoDB has no RefreshDatabase transactions here). */
    protected array $collections = ['users', 'artifacts', 'chat_sessions', 'failed_jobs', 'personal_access_tokens'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.mongodb.database');

        if (! str_contains($database, 'test')) {
            $this->fail("Refusing to wipe MongoDB database [{$database}] — tests need a database name containing 'test' (see phpunit.xml).");
        }

        foreach ($this->collections as $collection) {
            DB::connection('mongodb')->table($collection)->delete();
        }
    }

    protected function actingAsStudent(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Put a small real PDF where the artifact says its file is. */
    protected function storeFileFor(Artifact $artifact, string $contents = "%PDF-1.4\n% test file\n%%EOF\n"): void
    {
        Storage::disk($artifact->storage_disk)->put($artifact->file_path, $contents);
    }

    /** The fake Qdrant sync used in tests; records every call. */
    protected function vectorIndex(): FakeVectorIndex
    {
        return $this->app->make(VectorIndex::class);
    }
}
