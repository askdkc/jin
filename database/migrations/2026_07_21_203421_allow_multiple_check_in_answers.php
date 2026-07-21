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
        Schema::table('check_in_prompts', function (Blueprint $table): void {
            $table->dropUnique(['check_in_id', 'user_id', 'scheduled_for']);
            $table->index(
                ['check_in_id', 'user_id', 'scheduled_for'],
                'check_in_prompts_schedule_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_in_prompts', function (Blueprint $table): void {
            $table->dropIndex('check_in_prompts_schedule_index');
            $table->unique(['check_in_id', 'user_id', 'scheduled_for']);
        });
    }
};
