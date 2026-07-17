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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('chat_channel_id')
                ->nullable()
                ->after('project_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->after('user_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            $table->index(['chat_channel_id', 'parent_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['chat_channel_id', 'parent_id', 'id']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('chat_channel_id');
        });
    }
};
