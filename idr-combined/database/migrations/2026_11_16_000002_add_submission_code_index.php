<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Declared submissions get a unique code (printed on the certificate the
 * student hands in at the Dean's office). Older documents have no code, so the
 * index is sparse: only documents that have the field are checked.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection($this->connection)->table('artifacts', function (Blueprint $collection) {
            $collection->unique('submission_code', options: ['sparse' => true]);
            $collection->index('hard_copy_received_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('artifacts', function (Blueprint $collection) {
            $collection->dropIndexIfExists('submission_code_1');
            $collection->dropIndexIfExists('hard_copy_received_at_1');
        });
    }
};
