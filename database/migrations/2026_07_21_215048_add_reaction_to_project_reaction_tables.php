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
        foreach (['stored_file_reactions', 'schedule_event_reactions', 'todo_reactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('reaction', 20)->default('🚀')->after('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['stored_file_reactions', 'schedule_event_reactions', 'todo_reactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('reaction');
            });
        }
    }
};
