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
        Schema::table('card_columns', function (Blueprint $table) {
            $table->boolean('is_not_now')->default(false);
            $table->index(['card_table_id', 'is_not_now']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_columns', function (Blueprint $table) {
            $table->dropIndex(['card_table_id', 'is_not_now']);
            $table->dropColumn('is_not_now');
        });
    }
};
