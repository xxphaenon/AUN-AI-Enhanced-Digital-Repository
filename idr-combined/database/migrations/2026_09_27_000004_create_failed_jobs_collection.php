<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Failed queue jobs are stored in MongoDB (config/queue.php "failed" driver),
 * so `php artisan queue:failed` / `queue:retry` work. Horizon also keeps its
 * own failed-job view in Redis.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection($this->connection)->create('failed_jobs', function (Blueprint $collection) {
            $collection->unique('uuid');
            $collection->index('failed_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('failed_jobs');
    }
};
