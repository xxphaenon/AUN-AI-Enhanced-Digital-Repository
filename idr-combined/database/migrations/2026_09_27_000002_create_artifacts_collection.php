<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection($this->connection)->create('artifacts', function (Blueprint $collection) {
            // Search/chat join: "EMBEDDED artifacts with these ids".
            $collection->index(['processing_status' => 1, 'created_at' => -1]);
            // "My uploads" listings in the admin panel.
            $collection->index(['uploader_id' => 1, 'created_at' => -1]);
            $collection->unique('storage_path');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('artifacts');
    }
};
