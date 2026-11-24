<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Moves existing artifacts to the review-workflow schema:
 *
 *   uploader_id  -> uploaded_by
 *   storage_path -> file_path
 *   + status (pending | approved | rejected)
 *   + course_code, academic_year, file_hash, reviewed_by, reviewed_at, rejection_reason
 *
 * Files uploaded before this change were uploaded by admins, so they are
 * marked "approved" (reviewed_by = their uploader) and stay searchable.
 * Safe to run on an empty database and safe to re-run.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Old indexes on the renamed fields must go first: a unique index on
        // storage_path would reject every document once that field is gone.
        $schema->table('artifacts', function (Blueprint $collection) {
            $collection->dropIndexIfExists('storage_path_1');
            $collection->dropIndexIfExists('uploader_id_1_created_at_-1');
        });

        $artifacts = DB::connection($this->connection)->getCollection('artifacts');

        $artifacts->updateMany(['uploader_id' => ['$exists' => true]], ['$rename' => ['uploader_id' => 'uploaded_by']]);
        $artifacts->updateMany(['storage_path' => ['$exists' => true]], ['$rename' => ['storage_path' => 'file_path']]);

        foreach ($artifacts->find(['status' => ['$exists' => false]]) as $doc) {
            $artifacts->updateOne(['_id' => $doc['_id']], ['$set' => [
                'status' => 'approved',
                'reviewed_by' => $doc['uploaded_by'] ?? null,
                'reviewed_at' => $doc['created_at'] ?? new UTCDateTime,
                'rejection_reason' => null,
            ]]);
        }

        foreach (['course_code', 'academic_year', 'file_hash'] as $field) {
            $artifacts->updateMany([$field => ['$exists' => false]], ['$set' => [$field => null]]);
        }

        $schema->table('artifacts', function (Blueprint $collection) {
            // Admin dashboard tabs + public listing ("approved, newest first").
            $collection->index(['status' => 1, 'created_at' => -1]);
            // "Everything uploaded by X" (admin filter, "my submissions").
            $collection->index(['uploaded_by' => 1, 'created_at' => -1]);
            $collection->index('original_filename');
            $collection->index('course_code');
            // Duplicate-upload check.
            $collection->index('file_hash');
            $collection->unique('file_path');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->table('artifacts', function (Blueprint $collection) {
            $collection->dropIndexIfExists('status_1_created_at_-1');
            $collection->dropIndexIfExists('uploaded_by_1_created_at_-1');
            $collection->dropIndexIfExists('original_filename_1');
            $collection->dropIndexIfExists('course_code_1');
            $collection->dropIndexIfExists('file_hash_1');
            $collection->dropIndexIfExists('file_path_1');
        });

        $artifacts = DB::connection($this->connection)->getCollection('artifacts');
        $artifacts->updateMany(['uploaded_by' => ['$exists' => true]], ['$rename' => ['uploaded_by' => 'uploader_id']]);
        $artifacts->updateMany(['file_path' => ['$exists' => true]], ['$rename' => ['file_path' => 'storage_path']]);

        $schema->table('artifacts', function (Blueprint $collection) {
            $collection->index(['uploader_id' => 1, 'created_at' => -1]);
            $collection->unique('storage_path');
        });
    }
};
