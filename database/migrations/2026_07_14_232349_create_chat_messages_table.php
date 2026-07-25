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
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['project_id', 'id']);
            $table->index(['chat_channel_id', 'parent_id', 'id']);
            $table->index('parent_id');
        });

        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'recipient_id', 'read_at', 'id']);
            $table->index(['sender_id', 'team_id', 'id']);
            $table->index(['recipient_id', 'team_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_channels');
    }
};
