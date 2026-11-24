<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The repository now holds two document types only: "project" (Senior Design
 * Project) and "research" (Senior Research Project).
 *
 * Documents saved with a removed type (course, report, past-paper) would fail to
 * load, so their type is cleared to "not specified"; an admin can set the right
 * one from the admin page. Run `php artisan idr:sync-vectors` afterwards so the
 * search index drops the old values too. Safe to re-run.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        DB::connection($this->connection)->getCollection('artifacts')->updateMany(
            ['document_type' => ['$exists' => true, '$nin' => ['project', 'research', null]]],
            ['$set' => ['document_type' => null]],
        );
    }

    public function down(): void
    {
        // The old types cannot be restored; nothing to undo.
    }
};
