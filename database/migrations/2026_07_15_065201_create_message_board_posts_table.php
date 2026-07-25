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
        Schema::create('message_board_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at');
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'published_at']);
            $table->index(
                ['project_id', 'pinned_at', 'published_at'],
                'message_posts_pinned_order_index',
            );
        });

        Schema::create('message_board_post_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_board_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['message_board_post_id', 'user_id']);
        });

        Schema::create('message_board_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_board_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20);
            $table->timestamps();

            $table->unique(
                ['message_board_post_id', 'user_id'],
                'message_board_reactions_post_user_unique',
            );
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('commentable');
            $table->text('body');
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('message_board_post_reactions');
        Schema::dropIfExists('message_board_post_user');
        Schema::dropIfExists('message_board_posts');
    }
};
