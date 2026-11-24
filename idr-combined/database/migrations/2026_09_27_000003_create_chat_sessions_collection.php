<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection($this->connection)->create('chat_sessions', function (Blueprint $collection) {
            // "A user's conversations, most recent first".
            $collection->index(['user_id' => 1, 'last_message_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('chat_sessions');
    }
};
