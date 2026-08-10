<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('chat_channel_id')
                ->nullable()
                ->after('todo_list_id')
                ->constrained()
                ->nullOnDelete();
            $table->index(
                ['chat_channel_id', 'completed_at', 'position', 'id'],
                'todos_chat_channel_status_position_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex('todos_chat_channel_status_position_index');
            $table->dropConstrainedForeignId('chat_channel_id');
        });
    }
};
