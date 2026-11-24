<?php

use App\Models\Artifact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documents can now list several authors (group projects). Each existing
 * document gets an `authors` list made from its `author` text, e.g.
 * "Ada Obi, Musa Bello and Grace Eze" → three authors. The `author` text is
 * left as it is. Safe to re-run: only documents without a list are touched.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $artifacts = DB::connection($this->connection)->getCollection('artifacts');

        foreach ($artifacts->find(['authors' => ['$exists' => false]], ['projection' => ['author' => 1]]) as $doc) {
            $artifacts->updateOne(
                ['_id' => $doc['_id']],
                ['$set' => ['authors' => Artifact::parseAuthors(isset($doc['author']) ? (string) $doc['author'] : null)]],
            );
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->getCollection('artifacts')->updateMany([], ['$unset' => ['authors' => '']]);
    }
};
