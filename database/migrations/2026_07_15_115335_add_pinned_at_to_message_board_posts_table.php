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
        Schema::table('message_board_posts', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->index(['project_id', 'pinned_at', 'published_at'], 'message_posts_pinned_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_board_posts', function (Blueprint $table) {
            $table->dropIndex('message_posts_pinned_order_index');
            $table->dropColumn('pinned_at');
        });
    }
};
